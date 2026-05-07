<?php
/**
 * GD Master Catalog — Feeds Controller (admin/catalog/feeds)
 *
 * v1.0.2: adds add(), delete(), reorder() actions for distributor management.
 * Existing manage() and edit() methods are unchanged in behavior; manage()
 * is updated to pass the new $addUrl and $reorderUrl template arguments and
 * to include $feed['id'] and $feed['delete_url'] per row.
 */

namespace IPS\gdcatalog\modules\admin\catalog;

use IPS\Helpers\Form;
use IPS\Output;
use IPS\Request;
use IPS\gdcatalog\Feed\Distributor;
use IPS\gdcatalog\Catalog\Product;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _feeds extends \IPS\Dispatcher\Controller
{
	public static $csrfProtected = TRUE;

	public function execute(): void
	{
		parent::execute();
	}

	/**
	 * List all configured distributor feeds.
	 */
	protected function manage()
	{
		$rawFeeds = Distributor::loadAll();
		$lang     = \IPS\Member::loggedIn()->language();

		$feeds = [];
		$activeCount = 0;
		$urlCount    = 0;
		foreach ( $rawFeeds as $feed )
		{
			$editUrl = (string) \IPS\Http\Url::internal(
				'app=gdcatalog&module=catalog&controller=feeds&do=edit&id=' . (int) $feed->id
			)->csrf();

			$deleteUrl = (string) \IPS\Http\Url::internal(
				'app=gdcatalog&module=catalog&controller=feeds&do=delete&id=' . (int) $feed->id
			)->csrf();

			$isActive = (bool) $feed->active;
			$feedUrl  = (string) ( $feed->feed_url ?? '' );

			if ( $isActive )    { $activeCount++; }
			if ( $feedUrl !== '' ) { $urlCount++; }

			$feeds[] = [
				'id'                => (int) $feed->id,
				'priority'          => (int) $feed->priority,
				'feed_name'         => (string) $feed->feed_name,
				'distributor_label' => $lang->addToStack( 'gdcatalog_dist_' . $feed->distributor ),
				'feed_format'       => strtoupper( (string) $feed->feed_format ),
				'import_schedule'   => (string) $feed->import_schedule,
				'active'            => $isActive,
				'last_run'          => $feed->last_run ?? null,
				'last_record_count' => (int) ( $feed->last_record_count ?? 0 ),
				'last_run_status'   => $feed->last_run_status ?? null,
				'feed_url'          => $feedUrl,
				'edit_url'          => $editUrl,
				'delete_url'        => $deleteUrl,
			];
		}

		$feedCounts = [
			'total'  => \count( $feeds ),
			'active' => $activeCount,
			'urls'   => $urlCount,
		];

		$addUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=feeds&do=add'
		)->csrf();

		$reorderUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=feeds&do=reorder'
		)->csrf();

		Output::i()->title  = $lang->addToStack( 'gdcatalog_feeds_title' );
		Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->feedList(
			$feeds, $feedCounts, $addUrl, $reorderUrl
		);
	}

	/**
	 * Add a new distributor feed.
	 */
	protected function add()
	{
		\IPS\Session::i()->csrfCheck();

		$form = new Form;

		$existingCount = \IPS\Db::i()->select( 'COUNT(*)', 'gd_distributor_feeds' )->first();
		$priorityOptions = [];
		for ( $i = 1; $i <= ( $existingCount + 1 ); $i++ )
		{
			$priorityOptions[$i] = (string) $i;
		}

		$form->add( new Form\Text( 'gdcatalog_feed_name', NULL, TRUE ) );
		$form->add( new Form\Text( 'gdcatalog_feed_distributor_slug', NULL, TRUE, [
			'placeholder' => 'e.g. acme_distributor (lowercase, alphanumeric and underscores only)',
			'regex'       => '/^[a-z0-9_]+$/',
		] ) );
		$form->add( new Form\Text( 'gdcatalog_feed_distributor_label', NULL, TRUE, [
			'placeholder' => 'e.g. Acme Distributor (display name)',
		] ) );
		$form->add( new Form\Select( 'gdcatalog_feed_priority_position', $existingCount + 1, TRUE, [
			'options' => $priorityOptions,
		] ) );

		if ( $values = $form->values() )
		{
			$slug      = trim( $values['gdcatalog_feed_distributor_slug'] );
			$feedName  = trim( $values['gdcatalog_feed_name'] );
			$label     = trim( $values['gdcatalog_feed_distributor_label'] );
			$position  = (int) $values['gdcatalog_feed_priority_position'];

			/* Reject duplicate slugs */
			$existing = \IPS\Db::i()->select( 'COUNT(*)', 'gd_distributor_feeds', [ 'distributor=?', $slug ] )->first();
			if ( $existing > 0 )
			{
				$form->error = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_feed_slug_duplicate' );
				Output::i()->output = (string) $form;
				return;
			}

			/* Shift existing distributors with priority >= position to make room.
			 * Use update() with a parameterized condition so the value is bound, not interpolated. */
			\IPS\Db::i()->update( 'gd_distributor_feeds',
				'priority = priority + 1',
				[ 'priority >= ?', $position ]
			);

			/* Insert the new distributor */
			\IPS\Db::i()->insert( 'gd_distributor_feeds', [
				'feed_name'       => $feedName,
				'distributor'     => $slug,
				'priority'        => $position,
				'feed_url'        => '',
				'feed_format'     => 'xml',
				'auth_type'       => 'none',
				'import_schedule' => '6hr',
				'active'          => 0,
			] );

			/* Add the language string for this slug to lang.xml-equivalent runtime store */
			try
			{
				\IPS\Lang::saveCustom( 'gdcatalog', 'gdcatalog_dist_' . $slug, $label );
			}
			catch ( \Throwable ) { /* Lang::saveCustom may not exist on all IPS versions; non-fatal */ }

			Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
				'gdcatalog_feed_added'
			);
		}

		Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_feed_add' );
		Output::i()->output = (string) $form;
	}

	/**
	 * Delete a distributor feed.
	 *
	 * Cascade: deletes related rows from gd_feed_conflicts, gd_field_locks,
	 * gd_compliance_flags, gd_import_log. Then for each gd_catalog product
	 * that listed this distributor in distributor_sources, removes the slug,
	 * reassigns primary_source to next-highest-priority remaining distributor,
	 * and marks record_status = 'admin_review' for visibility.
	 */
	protected function delete()
	{
		\IPS\Session::i()->csrfCheck();

		$id   = (int) Request::i()->id;
		$feed = Distributor::load( $id );
		$slug = $feed->distributor;

		/* 1. Cascade delete history tables */
		try { \IPS\Db::i()->delete( 'gd_feed_conflicts', [ 'distributor_id=?', $id ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'gd_field_locks', [ 'locked_distributor_id=?', $id ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'gd_compliance_flags', [ 'distributor_id=?', $id ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'gd_import_log', [ 'feed_id=?', $id ] ); } catch ( \Throwable ) {}

		/* 2. Reassign affected catalog products */
		$affectedProducts = \IPS\Db::i()->select( '*', 'gd_catalog', [
			"FIND_IN_SET(?, distributor_sources) > 0", $slug
		] );

		$reassignedCount = 0;
		foreach ( $affectedProducts as $row )
		{
			try
			{
				$product = Product::constructFromData( $row );

				$product->removeDistributorSource( $slug );

				if ( $product->primary_source === $slug )
				{
					$remainingSources = $product->getDistributorSources();
					if ( !empty( $remainingSources ) )
					{
						$nextPrimary = NULL;
						$bestPriority = PHP_INT_MAX;
						foreach ( $remainingSources as $remainingSlug )
						{
							try
							{
								$remainingFeed = Distributor::loadByDistributor( $remainingSlug );
								if ( $remainingFeed->priority < $bestPriority )
								{
									$bestPriority = $remainingFeed->priority;
									$nextPrimary  = $remainingSlug;
								}
							}
							catch ( \Throwable ) { /* feed may not exist; skip */ }
						}
						$product->primary_source = $nextPrimary ?? '';
					}
					else
					{
						$product->primary_source = '';
					}
				}

				$product->record_status = 'admin_review';
				$product->save();
				$reassignedCount++;
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'distributor delete reassign failed for upc=' . ( $row['upc'] ?? '?' ) . ': ' . $e->getMessage(), 'gdcatalog_distributor_delete' ); } catch ( \Throwable ) {}
			}
		}

		/* 3. Delete the distributor row itself */
		\IPS\Db::i()->delete( 'gd_distributor_feeds', [ 'id=?', $id ] );

		/* 4. Resequence remaining priorities to close the gap */
		$remaining = iterator_to_array( \IPS\Db::i()->select( 'id, priority', 'gd_distributor_feeds', NULL, 'priority ASC' ) );
		$newPriority = 1;
		foreach ( $remaining as $row )
		{
			\IPS\Db::i()->update( 'gd_distributor_feeds',
				[ 'priority' => $newPriority ],
				[ 'id=?', $row['id'] ]
			);
			$newPriority++;
		}

		Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
			$reassignedCount > 0
				? \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_feed_deleted_with_reassign', FALSE, [ 'sprintf' => [ $reassignedCount ] ] )
				: 'gdcatalog_feed_deleted'
		);
	}

	/**
	 * Persist new priority order from drag-and-drop.
	 *
	 * Accepts POST 'ids' as an array of distributor IDs in the new desired order.
	 * Updates each distributor's priority to its position in the array (1-indexed).
	 */
	protected function reorder()
	{
		\IPS\Session::i()->csrfCheck();

		$ids = Request::i()->ids ?? [];
		if ( !is_array( $ids ) || empty( $ids ) )
		{
			Output::i()->json( [ 'ok' => FALSE, 'error' => 'no_ids' ] );
			return;
		}

		$priority = 1;
		foreach ( $ids as $id )
		{
			$id = (int) $id;
			if ( $id <= 0 ) continue;

			\IPS\Db::i()->update( 'gd_distributor_feeds',
				[ 'priority' => $priority ],
				[ 'id=?', $id ]
			);
			$priority++;
		}

		Output::i()->json( [ 'ok' => TRUE, 'count' => $priority - 1 ] );
	}

	/**
	 * Edit a single feed configuration.
	 */
	protected function edit()
	{
		\IPS\Session::i()->csrfCheck();

		$id   = (int) Request::i()->id;
		$feed = Distributor::load( $id );

		$form = new Form;

		$form->add( new Form\Text( 'gdcatalog_feed_name', $feed->feed_name, TRUE ) );
		$form->add( new Form\Url( 'gdcatalog_feed_url', $feed->feed_url, FALSE ) );
		$form->add( new Form\Select( 'gdcatalog_feed_format', $feed->feed_format, TRUE, [
			'options' => [ 'xml' => 'XML', 'json' => 'JSON', 'csv' => 'CSV' ],
		] ) );
		$form->add( new Form\Select( 'gdcatalog_feed_auth_type', $feed->auth_type, TRUE, [
			'options' => [
				'none'   => 'None',
				'basic'  => 'Basic Auth',
				'apikey' => 'API Key',
				'ftp'    => 'FTP Credentials',
			],
		] ) );
		$form->add( new Form\TextArea( 'gdcatalog_feed_auth_credentials', $feed->getCredentials() ?? '', FALSE, [
			'placeholder' => 'JSON: {"username":"...","password":"..."} or {"api_key":"..."}',
		] ) );
		$form->add( new Form\Select( 'gdcatalog_feed_schedule', $feed->import_schedule, TRUE, [
			'options' => [
				'15min' => 'Every 15 minutes',
				'30min' => 'Every 30 minutes',
				'1hr'   => 'Every hour',
				'6hr'   => 'Every 6 hours',
				'daily' => 'Daily',
			],
		] ) );
		$form->add( new Form\YesNo( 'gdcatalog_feed_active', $feed->active, FALSE ) );

		$form->addHeader( 'gdcatalog_feed_field_mapping' );
		$form->add( new Form\TextArea( 'gdcatalog_feed_field_mapping_json', $feed->field_mapping ?? '', FALSE, [
			'rows'        => 12,
			'placeholder' => '{"DIST_FIELD":"canonical_field", "PROD_NAME":"title", ...}',
		] ) );

		$form->addHeader( 'gdcatalog_feed_category_mapping' );
		$form->add( new Form\TextArea( 'gdcatalog_feed_category_mapping_json', $feed->category_mapping ?? '', FALSE, [
			'rows'        => 12,
			'placeholder' => '{"DIST CATEGORY":"canonical-slug", "HANDGUNS":"handguns", ...}',
		] ) );

		$form->addHeader( 'gdcatalog_feed_conflict_detection' );
		$conflictFields = $feed->getConflictDetectionFields();
		foreach ( $conflictFields as $fieldName => $enabled )
		{
			$form->add( new Form\YesNo(
				'gdcatalog_conflict_' . $fieldName,
				$enabled,
				FALSE
			) );
		}

		if ( $values = $form->values() )
		{
			$feed->feed_name       = $values['gdcatalog_feed_name'];
			$feed->feed_url        = (string) $values['gdcatalog_feed_url'];
			$feed->feed_format     = $values['gdcatalog_feed_format'];
			$feed->auth_type       = $values['gdcatalog_feed_auth_type'];
			$feed->import_schedule = $values['gdcatalog_feed_schedule'];
			$feed->active          = (int) $values['gdcatalog_feed_active'];

			$creds = trim( $values['gdcatalog_feed_auth_credentials'] );
			$feed->setCredentials( $creds !== '' ? $creds : null );

			$fieldJson = trim( $values['gdcatalog_feed_field_mapping_json'] );
			$feed->field_mapping = ( $fieldJson !== '' && json_decode( $fieldJson ) !== null )
				? $fieldJson
				: null;

			$catJson = trim( $values['gdcatalog_feed_category_mapping_json'] );
			$feed->category_mapping = ( $catJson !== '' && json_decode( $catJson ) !== null )
				? $catJson
				: null;

			$updatedConflict = [];
			foreach ( $conflictFields as $fieldName => $default )
			{
				$updatedConflict[$fieldName] = (bool) $values['gdcatalog_conflict_' . $fieldName];
			}
			$feed->setConflictDetectionFields( $updatedConflict );

			$feed->save();

			Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
				'saved'
			);
		}

		Output::i()->title  = $feed->feed_name;
		Output::i()->output = (string) $form;
	}
}

class feeds extends _feeds {}
