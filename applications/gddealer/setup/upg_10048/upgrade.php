<?php
/**
 * v1.0.48: support tickets — notifications + audit timeline + admin-reopen.
 *
 * - Creates gd_dealer_support_events (idempotent via checkForTable).
 * - Seeds 3 notification defaults idempotently.
 * - Re-runs installEmailTemplates so the 3 new email templates land.
 * - Backfills events for existing tickets (idempotent via COUNT check).
 * - Re-seeds supportView (dealer) and supportTicketView (admin) with timeline.
 */

namespace IPS\gddealer\setup\upg_10048;

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
		/* 1. Create gd_dealer_support_events table. */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_support_events' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_dealer_support_events',
					'columns' => [
						[ 'name' => 'id',         'type' => 'INT',      'length' => 10,  'unsigned' => TRUE, 'allow_null' => FALSE, 'auto_increment' => TRUE ],
						[ 'name' => 'ticket_id',  'type' => 'INT',      'length' => 10,  'unsigned' => TRUE, 'allow_null' => FALSE ],
						[ 'name' => 'event_type', 'type' => 'VARCHAR',  'length' => 64,  'allow_null' => FALSE ],
						[ 'name' => 'actor_id',   'type' => 'BIGINT',   'length' => 20,  'unsigned' => TRUE, 'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'actor_role', 'type' => 'VARCHAR',  'length' => 16,  'allow_null' => FALSE, 'default' => 'system' ],
						[ 'name' => 'note',       'type' => 'TEXT',     'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'old_value',  'type' => 'VARCHAR',  'length' => 64,  'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'new_value',  'type' => 'VARCHAR',  'length' => 64,  'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'created_at', 'type' => 'DATETIME', 'allow_null' => FALSE ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',    'columns' => [ 'id' ] ],
						[ 'type' => 'key',     'name' => 'ticket_id',  'columns' => [ 'ticket_id' ] ],
						[ 'type' => 'key',     'name' => 'created_at', 'columns' => [ 'created_at' ] ],
					],
				] );
			}
		}
		catch ( \Exception ) {}

		/* 2. Seed notification defaults for the 3 new keys. Idempotent —
		   core_notification_defaults has a unique key on notification_key. */
		$notifDefaults = [
			'support_ticket_new'       => 'inline,email',
			'support_reply_to_dealer'  => 'inline,email',
			'support_reply_to_admin'   => 'inline,email',
		];
		foreach ( $notifDefaults as $key => $default )
		{
			try
			{
				$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_notification_defaults',
					[ 'notification_key=?', $key ]
				)->first();
				if ( $exists === 0 )
				{
					\IPS\Db::i()->insert( 'core_notification_defaults', [
						'notification_key' => $key,
						'default'          => $default,
						'disabled'         => '',
					] );
				}
			}
			catch ( \Exception ) {}
		}

		/* 3. Re-install email templates so supportTicketNew, supportReplyToDealer,
		   and supportReplyToAdmin get parsed in from data/emails.xml. */
		try
		{
			\IPS\Application::load( 'gddealer' )->installEmailTemplates();
		}
		catch ( \Exception ) {}

		/* 3b. Safety net — mirrors install.php logic since installEmailTemplates()
		   silently no-ops on some installs. */
		$emailsXmlPath = \IPS\ROOT_PATH . '/applications/gddealer/data/emails.xml';
		if ( file_exists( $emailsXmlPath ) )
		{
			$xml  = @simplexml_load_file( $emailsXmlPath, \SimpleXMLElement::class, LIBXML_NONET );

			if ( $xml instanceof \SimpleXMLElement )
			{
				$targets = [ 'supportTicketNew', 'supportReplyToDealer', 'supportReplyToAdmin' ];
				foreach ( $xml->template as $t )
				{
					$templateName = trim( (string) $t->template_name );
					if ( !in_array( $templateName, $targets, TRUE ) ) { continue; }

					try
					{
						$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_email_templates',
							[ 'template_app=? AND template_name=?', 'gddealer', $templateName ]
						)->first();

						if ( $exists > 0 ) { continue; }

						\IPS\Db::i()->insert( 'core_email_templates', [
							'template_app'               => 'gddealer',
							'template_name'              => $templateName,
							'template_data'              => (string) $t->template_data,
							'template_content_html'      => (string) $t->template_content_html,
							'template_content_plaintext' => (string) $t->template_content_plaintext,
							'template_key'               => md5( 'gddealer;' . $templateName ),
							'template_parent'            => 0,
							'template_edited'            => 0,
							'template_pinned'            => 0,
						] );
					}
					catch ( \Exception )
					{
						try
						{
							\IPS\Db::i()->insert( 'core_email_templates', [
								'template_app'               => 'gddealer',
								'template_name'              => $templateName,
								'template_content_html'      => (string) $t->template_content_html,
								'template_content_plaintext' => (string) $t->template_content_plaintext,
							] );
						}
						catch ( \Exception ) {}
					}
				}
			}
		}

		/* 4. Backfill events for existing tickets (idempotent — skip if events
		   already logged for that ticket). */
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_support_tickets' ) as $row )
			{
				$tid = (int) $row['id'];

				try
				{
					$existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_support_events',
						[ 'ticket_id=?', $tid ]
					)->first();
					if ( $existing > 0 ) { continue; }
				}
				catch ( \Exception ) { continue; }

				$createdAt = (string) ( $row['created_at'] ?? date( 'Y-m-d H:i:s' ) );
				try
				{
					\IPS\Db::i()->insert( 'gd_dealer_support_events', [
						'ticket_id'  => $tid,
						'event_type' => 'ticket_opened',
						'actor_id'   => (int) $row['member_id'],
						'actor_role' => 'dealer',
						'note'       => NULL,
						'old_value'  => NULL,
						'new_value'  => NULL,
						'created_at' => $createdAt,
					] );
				}
				catch ( \Exception ) {}

				try
				{
					foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_support_replies',
						[ 'ticket_id=?', $tid ], 'created_at ASC'
					) as $reply )
					{
						$role      = (string) $reply['role'];
						$eventType = $role === 'admin' ? 'admin_replied' : 'dealer_replied';
						\IPS\Db::i()->insert( 'gd_dealer_support_events', [
							'ticket_id'  => $tid,
							'event_type' => $eventType,
							'actor_id'   => (int) $reply['member_id'],
							'actor_role' => $role,
							'note'       => NULL,
							'old_value'  => NULL,
							'new_value'  => NULL,
							'created_at' => (string) $reply['created_at'],
						] );
					}
				}
				catch ( \Exception ) {}

				if ( ( $row['status'] ?? '' ) === 'closed' )
				{
					try
					{
						\IPS\Db::i()->insert( 'gd_dealer_support_events', [
							'ticket_id'  => $tid,
							'event_type' => 'ticket_closed',
							'actor_id'   => NULL,
							'actor_role' => 'system',
							'note'       => NULL,
							'old_value'  => NULL,
							'new_value'  => 'closed',
							'created_at' => (string) ( $row['updated_at'] ?? $createdAt ),
						] );
					}
					catch ( \Exception ) {}
				}
			}
		}
		catch ( \Exception ) {}

		/* 5. Re-seed supportView (dealer) + supportTicketView (admin) templates
		   with the new timeline block. */
		require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10048.php';

		try { unset( \IPS\Data\Store::i()->applications ); }    catch ( \Exception ) {}
		try { unset( \IPS\Data\Store::i()->modules ); }         catch ( \Exception ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }      catch ( \Exception ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }          catch ( \Exception ) {}
		try { unset( \IPS\Data\Store::i()->emailTemplates ); }  catch ( \Exception ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }               catch ( \Exception ) {}
		try { \IPS\Data\Store::i()->clearAll(); }               catch ( \Exception ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
