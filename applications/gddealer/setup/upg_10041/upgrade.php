<?php
/**
 * v1.0.41: dispute timeline + admin-edit-requested notification.
 *
 * - Create gd_dealer_dispute_events table (timeline history).
 * - Seed dispute_edit_requested notification default.
 * - Insert disputeEditRequested email template from emails.xml if missing.
 * - Backfill events for existing disputes (best-effort reconstruction from
 *   current row state).
 * - Re-seed admin disputeQueue + front dealerReviews + front dealerProfile
 *   templates so timeline block + pre-filled editors render.
 */

namespace IPS\gddealer\setup\upg_10041;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* 1. Create gd_dealer_dispute_events table (idempotent). */
		try
		{
			$exists = \IPS\Db::i()->checkForTable( 'gd_dealer_dispute_events' );
			if ( !$exists )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_dealer_dispute_events',
					'columns' => [
						[ 'name' => 'id',         'type' => 'INT',      'length' => 10, 'unsigned' => TRUE, 'allow_null' => FALSE, 'auto_increment' => TRUE ],
						[ 'name' => 'review_id',  'type' => 'INT',      'length' => 10, 'unsigned' => TRUE, 'allow_null' => FALSE ],
						[ 'name' => 'event_type', 'type' => 'VARCHAR',  'length' => 64, 'allow_null' => FALSE ],
						[ 'name' => 'actor_id',   'type' => 'BIGINT',   'length' => 20, 'unsigned' => TRUE, 'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'actor_role', 'type' => 'VARCHAR',  'length' => 16, 'allow_null' => FALSE, 'default' => 'system' ],
						[ 'name' => 'note',       'type' => 'TEXT',     'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'created_at', 'type' => 'DATETIME', 'allow_null' => FALSE ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',   'columns' => [ 'id' ] ],
						[ 'type' => 'key',     'name' => 'review_id', 'columns' => [ 'review_id' ] ],
					],
				] );
			}
		}
		catch ( \Exception ) {}

		/* 2. Seed notification default — idempotent. */
		try
		{
			$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_notification_defaults',
				[ 'notification_key=?', 'dispute_edit_requested' ]
			)->first();
			if ( $exists === 0 )
			{
				\IPS\Db::i()->insert( 'core_notification_defaults', [
					'notification_key' => 'dispute_edit_requested',
					'default'          => 'inline,email',
					'disabled'         => '',
				] );
			}
		}
		catch ( \Exception ) {}

		/* 3. Seed disputeEditRequested email template if missing. Parse
		   data/emails.xml with simplexml and insert the row directly since
		   IPS's own email-template installer has silently no-op'd in past
		   upgrades (rule #18). */
		$emailsXmlPath = \IPS\ROOT_PATH . '/applications/gddealer/data/emails.xml';
		if ( file_exists( $emailsXmlPath ) )
		{
			$xml  = @simplexml_load_file( $emailsXmlPath, \SimpleXMLElement::class, LIBXML_NONET );

			if ( $xml instanceof \SimpleXMLElement )
			{
				foreach ( $xml->template as $t )
				{
					$name = trim( (string) $t->template_name );
					if ( $name !== 'disputeEditRequested' ) { continue; }

					try
					{
						$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_email_templates',
							[ 'template_app=? AND template_name=?', 'gddealer', $name ]
						)->first();
						if ( $exists === 0 )
						{
							\IPS\Db::i()->insert( 'core_email_templates', [
								'template_app'               => 'gddealer',
								'template_name'              => $name,
								'template_data'              => (string) $t->template_data,
								'template_content_html'      => (string) $t->template_content_html,
								'template_content_plaintext' => (string) $t->template_content_plaintext,
							] );
						}
					}
					catch ( \Exception ) {}
					break;
				}
			}
		}

		/* 4. Backfill events for existing disputes. Skip any review that
		   already has events logged (idempotent re-run safety). */
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_ratings',
				\IPS\Db::i()->in( 'dispute_status', [ 'pending_customer', 'pending_admin', 'resolved_dealer', 'dismissed' ] )
			) as $row )
			{
				$rid = (int) $row['id'];

				try
				{
					$existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_dispute_events', [ 'review_id=?', $rid ] )->first();
				}
				catch ( \Exception )
				{
					continue;
				}
				if ( $existing > 0 ) { continue; }

				$disputeAt = (string) ( $row['dispute_at'] ?? $row['created_at'] ?? date( 'Y-m-d H:i:s' ) );
				try
				{
					\IPS\Db::i()->insert( 'gd_dealer_dispute_events', [
						'review_id'  => $rid,
						'event_type' => 'dispute_opened',
						'actor_id'   => (int) ( $row['dealer_id'] ?? 0 ) ?: NULL,
						'actor_role' => 'dealer',
						'note'       => NULL,
						'created_at' => $disputeAt,
					] );
				}
				catch ( \Exception ) {}

				if ( !empty( $row['customer_responded_at'] ) )
				{
					try
					{
						\IPS\Db::i()->insert( 'gd_dealer_dispute_events', [
							'review_id'  => $rid,
							'event_type' => 'customer_responded',
							'actor_id'   => (int) ( $row['member_id'] ?? 0 ) ?: NULL,
							'actor_role' => 'customer',
							'note'       => NULL,
							'created_at' => (string) $row['customer_responded_at'],
						] );
					}
					catch ( \Exception ) {}
				}

				$resolvedAt = (string) ( $row['dispute_resolved_at'] ?? '' );
				if ( ( $row['dispute_status'] ?? '' ) === 'resolved_dealer' )
				{
					try
					{
						\IPS\Db::i()->insert( 'gd_dealer_dispute_events', [
							'review_id'  => $rid,
							'event_type' => 'admin_upheld',
							'actor_id'   => (int) ( $row['dispute_resolved_by'] ?? 0 ) ?: NULL,
							'actor_role' => 'admin',
							'note'       => NULL,
							'created_at' => $resolvedAt !== '' ? $resolvedAt : date( 'Y-m-d H:i:s' ),
						] );
					}
					catch ( \Exception ) {}
				}
				if ( ( $row['dispute_status'] ?? '' ) === 'dismissed' )
				{
					try
					{
						\IPS\Db::i()->insert( 'gd_dealer_dispute_events', [
							'review_id'  => $rid,
							'event_type' => 'admin_dismissed',
							'actor_id'   => (int) ( $row['dispute_resolved_by'] ?? 0 ) ?: NULL,
							'actor_role' => 'admin',
							'note'       => NULL,
							'created_at' => $resolvedAt !== '' ? $resolvedAt : date( 'Y-m-d H:i:s' ),
						] );
					}
					catch ( \Exception ) {}
				}
			}
		}
		catch ( \Exception ) {}

		/* 5. Re-seed templates that gained timeline + editor pre-fill. */
		require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10041.php';

		/* 6. Clear caches so the new extension class + template changes load. */
		try { unset( \IPS\Data\Store::i()->applications ); }    catch ( \Exception ) {}
		try { unset( \IPS\Data\Store::i()->modules ); }         catch ( \Exception ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }      catch ( \Exception ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }          catch ( \Exception ) {}
		try { unset( \IPS\Data\Store::i()->frontNavigation ); } catch ( \Exception ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }               catch ( \Exception ) {}
		try { \IPS\Data\Store::i()->clearAll(); }               catch ( \Exception ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
