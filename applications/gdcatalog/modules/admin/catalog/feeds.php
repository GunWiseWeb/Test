<?php
/**
 * GD Master Catalog — Feeds Controller (admin/catalog/feeds)
 *
 * v1.0.2: adds add(), delete(), reorder() actions for distributor management.
 * v1.0.4: enqueue dev/js/admin/feedSort.js in manage() so drag-and-drop works.
 * v1.0.5: move JS to interface/feedSort.js for production-mode serving.
 * v1.0.6: CSRF fix - drop ->csrf() URL-bake on reorder URL, pass session csrfKey
 *         to template as separate arg, JS reads it from data-csrf-key attribute.
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
		Output::i()->jsFiles = array_merge(
			Output::i()->jsFiles,
			Output::i()->js( 'feedSort.js', 'gdcatalog', 'interface' )
		);

		$rawFeeds = Distributor::loadAll();
		$lang     = \IPS\Member::loggedIn()->language();

		$lastImportLogs = [];
		try
		{
			$logRows = \IPS\Db::i()->select(
				'l.*',
				[ 'gd_import_log', 'l' ],
				'l.id IN ( SELECT MAX(id) FROM gd_import_log GROUP BY feed_id )'
			);
			foreach ( $logRows as $row )
			{
				$lastImportLogs[ (int) $row['feed_id'] ] = $row;
			}
		}
		catch ( \Throwable ) {}

		$feeds = [];
		$activeCount = 0;
		$urlCount    = 0;
		foreach ( $rawFeeds as $feed )
		{
			$editUrl = (string) \IPS\Http\Url::internal(
				'app=gdcatalog&module=catalog&controller=feeds&do=edit&id=' . (int) $feed->id
			);

			$deleteUrl = (string) \IPS\Http\Url::internal(
				'app=gdcatalog&module=catalog&controller=feeds&do=delete&id=' . (int) $feed->id
			)->csrf();

			/* v1.0.8: Test Connection URL only for sportssouth-typed feeds.
			 * The feedList template renders the button when this is non-null. */
			$testUrl = ( (string) $feed->auth_type === 'sportssouth' )
				? (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=feeds&do=testConnection&id=' . (int) $feed->id
				)
				: '';

			$isActive = (bool) $feed->active;
			$feedUrl  = (string) ( $feed->feed_url ?? '' );

			if ( $isActive )    { $activeCount++; }
			if ( $feedUrl !== '' ) { $urlCount++; }

			$authType = (string) ( $feed->auth_type ?? 'none' );
			$isManualUpload = ( $authType === 'manual_upload' );

			$uploadUrl = '';
			$runManualUrl = '';
			$uploadFilename = '';
			if ( $isManualUpload )
			{
				$uploadUrl = (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=feeds&do=uploadFeed&id=' . (int) $feed->id
				);
				$runManualUrl = (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=feeds&do=runManualFeed&id=' . (int) $feed->id
				)->csrf();
				$filePath = (string) ( $feed->uploaded_file_path ?? '' );
				if ( $filePath !== '' )
				{
					$uploadFilename = basename( $filePath );
				}
			}

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
				'test_url'          => $testUrl,
				'is_locked'         => (bool) ( $feed->locked ?? false ),
				'lock_catalog_url'  => (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=dashboard&do=lockCatalog&feed_id=' . (int) $feed->id
				)->csrf(),
				'unlock_catalog_url' => (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=dashboard&do=unlockCatalog&feed_id=' . (int) $feed->id
				)->csrf(),
				'reset_url' => $feed->last_run_status === 'running'
					? (string) \IPS\Http\Url::internal(
						'app=gdcatalog&module=catalog&controller=feeds&do=resetFeedStatus&id=' . (int) $feed->id
					  )->csrf()
					: null,
				'last_import_log' => $lastImportLogs[ (int) $feed->id ] ?? null,
				'auth_type'       => $authType,
				'upload_url'      => $uploadUrl,
				'run_manual_url'  => $runManualUrl,
				'has_upload'      => ( $uploadFilename !== '' ),
				'upload_filename' => $uploadFilename,
			];
		}

		$feedCounts = [
			'total'  => \count( $feeds ),
			'active' => $activeCount,
			'urls'   => $urlCount,
		];

		$addUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=feeds&do=add'
		);

		/* v1.0.6: reorder URL no longer baked with ->csrf(). The CSRF token is
		 * passed separately to the template and sent as a POST body parameter
		 * by the JS - matching IPS's expected pattern for AJAX endpoints
		 * (per gddealer/modules/admin/dealers/stockreplies.php pattern). */
		$reorderUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=feeds&do=reorder'
		);

		$csrfKey = \IPS\Session::i()->csrfKey;

		$reExtractUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=feeds&do=reExtractAttributes'
		)->csrf();

		Output::i()->title  = $lang->addToStack( 'gdcatalog_feeds_title' );
		Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->feedList(
			$feeds, $feedCounts, $addUrl, $reorderUrl, $csrfKey, $reExtractUrl
		);
	}

	/**
	 * Add a new distributor feed.
	 */
	protected function add()
	{
		if ( \IPS\Request::i()->requestMethod() === 'POST' ) { \IPS\Session::i()->csrfCheck(); }

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

			$existing = \IPS\Db::i()->select( 'COUNT(*)', 'gd_distributor_feeds', [ 'distributor=?', $slug ] )->first();
			if ( $existing > 0 )
			{
				$form->error = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_feed_slug_duplicate' );
				Output::i()->output = (string) $form;
				return;
			}

			\IPS\Db::i()->update( 'gd_distributor_feeds',
				'priority = priority + 1',
				[ 'priority >= ?', $position ]
			);

			$newId = \IPS\Db::i()->insert( 'gd_distributor_feeds', [
				'feed_name'       => $feedName,
				'distributor'     => $slug,
				'priority'        => $position,
				'feed_url'        => '',
				'feed_format'     => 'xml',
				'auth_type'       => 'none',
				'import_schedule' => '6hr',
				'active'          => 0,
			] );

			try
			{
				\IPS\Lang::saveCustom( 'gdcatalog', 'gdcatalog_dist_' . $slug, $label );
			}
			catch ( \Throwable ) {}

			Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds&do=edit&id=' . (int) $newId ),
				'gdcatalog_feed_added'
			);
		}

		Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_feed_add' );
		Output::i()->output = (string) $form;
	}

	/**
	 * Delete a distributor feed with cascade.
	 */
	protected function delete()
	{
		\IPS\Session::i()->csrfCheck();

		$id   = (int) Request::i()->id;
		$feed = Distributor::load( $id );
		$slug = $feed->distributor;

		try { \IPS\Db::i()->delete( 'gd_feed_conflicts', [ 'distributor_id=?', $id ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'gd_field_locks', [ 'locked_distributor_id=?', $id ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'gd_compliance_flags', [ 'distributor_id=?', $id ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'gd_import_log', [ 'feed_id=?', $id ] ); } catch ( \Throwable ) {}

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
						$nextPrimary  = NULL;
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
							catch ( \Throwable ) {}
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

		\IPS\Db::i()->delete( 'gd_distributor_feeds', [ 'id=?', $id ] );

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
	 * v1.0.6: csrfCheck() now validates against the csrfKey POST body parameter
	 * sent by feedSort.js (read from the table's data-csrf-key attribute).
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
		if ( \IPS\Request::i()->requestMethod() === 'POST' ) { \IPS\Session::i()->csrfCheck(); }

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
				'none'           => 'None',
				'basic'          => 'Basic Auth',
				'apikey'         => 'API Key',
				'ftp'            => 'FTP Credentials',
				'sportssouth'    => 'Sports South Web Service',
				'manual_upload'  => 'Manual File Upload',
			],
			'toggles' => [
				'none'          => [ 'gdcatalog_feed_url', 'gdcatalog_feed_auth_credentials' ],
				'basic'         => [ 'gdcatalog_feed_url', 'gdcatalog_feed_auth_credentials' ],
				'apikey'        => [ 'gdcatalog_feed_url', 'gdcatalog_feed_auth_credentials' ],
				'ftp'           => [ 'gdcatalog_feed_url', 'gdcatalog_feed_auth_credentials' ],
				'sportssouth'   => [ 'gdcatalog_feed_url', 'gdcatalog_feed_auth_credentials' ],
				'manual_upload' => [],
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

	/**
	 * v1.0.50: Upload a feed file for a manual_upload feed.
	 * GET renders the upload form; POST receives the file.
	 */
	protected function uploadFeed()
	{
		$id   = (int) Request::i()->id;
		$feed = Distributor::load( $id );

		if ( (string) $feed->auth_type !== 'manual_upload' )
		{
			Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
				'gdcatalog_upload_wrong_auth_type'
			);
			return;
		}

		if ( \IPS\Request::i()->requestMethod() === 'POST' )
		{
			\IPS\Session::i()->csrfCheck();

			if ( !isset( $_FILES['feed_file'] ) || $_FILES['feed_file']['error'] !== UPLOAD_ERR_OK )
			{
				Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
					'gdcatalog_upload_no_file'
				);
				return;
			}

			$uploadDir = \IPS\ROOT_PATH . '/uploads/gdcatalog_feeds';
			if ( !is_dir( $uploadDir ) )
			{
				mkdir( $uploadDir, 0755, true );
				file_put_contents( $uploadDir . '/.htaccess', "Deny from all\n" );
			}

			$ext = strtolower( pathinfo( $_FILES['feed_file']['name'], PATHINFO_EXTENSION ) );
			$allowed = [ 'xml', 'json', 'csv' ];
			if ( !\in_array( $ext, $allowed, true ) )
			{
				Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
					'gdcatalog_upload_invalid_type'
				);
				return;
			}

			$safeName = 'feed_' . (int) $feed->id . '_' . time() . '.' . $ext;
			$destPath = $uploadDir . '/' . $safeName;

			if ( !move_uploaded_file( $_FILES['feed_file']['tmp_name'], $destPath ) )
			{
				Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
					'gdcatalog_upload_move_failed'
				);
				return;
			}

			$feed->uploaded_file_path = $destPath;
			$feed->save();

			Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
				'gdcatalog_upload_success'
			);
			return;
		}

		$uploadActionUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=feeds&do=uploadFeed&id=' . (int) $feed->id
		);
		$csrfKey   = \IPS\Session::i()->csrfKey;
		$backUrl   = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' );
		$feedName  = (string) $feed->feed_name;

		Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_upload_feed_title' );
		Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->uploadFeedForm(
			$uploadActionUrl, $csrfKey, $backUrl, $feedName
		);
	}

	/**
	 * v1.0.50: Run an import on a manual_upload feed using the previously uploaded file.
	 */
	protected function runManualFeed()
	{
		\IPS\Session::i()->csrfCheck();

		$id   = (int) Request::i()->id;
		$feed = Distributor::load( $id );

		if ( (string) $feed->auth_type !== 'manual_upload' )
		{
			Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
				'gdcatalog_upload_wrong_auth_type'
			);
			return;
		}

		$filePath = (string) ( $feed->uploaded_file_path ?? '' );
		if ( $filePath === '' || !file_exists( $filePath ) )
		{
			Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
				'gdcatalog_upload_no_file_stored'
			);
			return;
		}

		try
		{
			$log = \IPS\gdcatalog\Feed\Importer::run( $feed );

			Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
				'gdcatalog_manual_import_complete'
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Manual feed import failed feed_id=' . $id . ': ' . $e->getMessage(), 'gdcatalog_manual_import' ); } catch ( \Throwable ) {}

			Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
				'gdcatalog_manual_import_failed'
			);
		}
	}

	/**
	 * v1.0.11: Refresh Sports South lookup tables (brands, categories).
	 * Stores results in gd_sportssouth_brands and gd_sportssouth_categories
	 * for the Importer to use during enrichment.
	 */
	protected function refreshLookups()
	{
		try
		{
			$id = (int) \IPS\Request::i()->id;
			if ( $id <= 0 )
			{
				throw new \RuntimeException( 'Missing distributor feed ID' );
			}

			$feed = \IPS\gdcatalog\Feed\Distributor::load( $id );

			if ( $feed->auth_type !== 'sportssouth' )
			{
				throw new \RuntimeException(
					'Refresh Lookups is only available for Sports South feeds. This feed has auth_type=' . (string) $feed->auth_type
				);
			}

			$client = \IPS\gdcatalog\Feed\Distributor\SportsSouthClient::fromDistributor( $feed );

			$credErrors = $client->validate();
			if ( !empty( $credErrors ) )
			{
				throw new \RuntimeException( 'Credential validation failed: ' . implode( '; ', $credErrors ) );
			}

			$now = time();
			$brandStats = $this->processBrandLookup( $client, $now );
			$categoryStats = $this->processCategoryLookup( $client, $now );

			$backUrl = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' );

			$html  = '<div style="padding:16px 20px;max-width:1100px;margin:0 auto">';
			$html .= '<h2 style="margin:0 0 16px">Sports South Lookup Refresh</h2>';
			$html .= '<div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:16px">';
			$html .= '<strong>Lookups refreshed</strong><br>';
			$html .= 'Brands: ' . (int) $brandStats['count'] . ' synced (' . (int) $brandStats['elapsed_ms'] . 'ms)<br>';
			$html .= 'Categories: ' . (int) $categoryStats['count'] . ' synced (' . (int) $categoryStats['elapsed_ms'] . 'ms)';
			$html .= '</div>';

			$html .= '<h3 style="margin:20px 0 8px">Sample Brand Field Keys</h3>';
			$html .= '<pre style="background:#1f2937;color:#f3f4f6;padding:12px;border-radius:8px;overflow-x:auto;font-size:0.85em">';
			$html .= htmlspecialchars( implode( ', ', $brandStats['sample_keys'] ?? [] ), ENT_QUOTES, 'UTF-8' );
			$html .= '</pre>';

			$html .= '<h3 style="margin:20px 0 8px">Sample Category Field Keys</h3>';
			$html .= '<pre style="background:#1f2937;color:#f3f4f6;padding:12px;border-radius:8px;overflow-x:auto;font-size:0.85em">';
			$html .= htmlspecialchars( implode( ', ', $categoryStats['sample_keys'] ?? [] ), ENT_QUOTES, 'UTF-8' );
			$html .= '</pre>';

			$html .= '<div style="margin-top:16px"><a href="' . htmlspecialchars( $backUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton_normal">Back to feeds</a></div>';
			$html .= '</div>';

			try { \IPS\Log::log( sprintf( 'Sports South refreshLookups: brands=%d categories=%d', $brandStats['count'], $categoryStats['count'] ), 'gdcatalog_sportssouth_lookups' ); } catch ( \Throwable ) {}

			\IPS\Output::i()->title  = 'Sports South Lookup Refresh';
			\IPS\Output::i()->output = $html;
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Sports South refreshLookups FAILED: ' . $e->getMessage(), 'gdcatalog_sportssouth_lookups' ); } catch ( \Throwable ) {}

			$backUrl = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' );
			$html  = '<div style="padding:16px 20px;max-width:1100px;margin:0 auto">';
			$html .= '<h2 style="margin:0 0 16px">Sports South Lookup Refresh</h2>';
			$html .= '<div style="background:#fee2e2;border:1px solid #fca5a5;color:#7f1d1d;padding:12px 16px;border-radius:8px;margin-bottom:16px">';
			$html .= '<strong>Lookup refresh failed</strong><br>';
			$html .= htmlspecialchars( $e->getMessage(), ENT_QUOTES, 'UTF-8' );
			$html .= '</div>';
			$html .= '<div style="margin-top:16px"><a href="' . htmlspecialchars( $backUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton_normal">Back to feeds</a></div>';
			$html .= '</div>';

			\IPS\Output::i()->title  = 'Lookup Refresh - Failed';
			\IPS\Output::i()->output = $html;
		}
	}

	/**
	 * v1.0.11 helper: Process brand lookup from Sports South API.
	 */
	protected function processBrandLookup( \IPS\gdcatalog\Feed\Distributor\SportsSouthClient $client, int $syncedAt ): array
	{
		$start = microtime( true );
		$rows = $client->brandUpdate();
		$elapsed = round( ( microtime( true ) - $start ) * 1000 );

		$sampleKeys = !empty( $rows ) ? array_keys( $rows[0] ) : [];
		$count = 0;

		foreach ( $rows as $row )
		{
			/* Resilient against actual field shape - try BRDNO then any key
			 * containing 'NO', and BRDNAM then any key containing 'NAM'/'NAME'/'DESC'. */
			$brdno = (string) ( $row['BRDNO'] ?? '' );
			/* v1.0.14: BRDNM is the real Sports South field. BRDNAM/BRDNAME/
			 * BRDDESC are kept as fallbacks for resilience. */
			$brdnam = (string) (
				$row['BRDNM']
				?? $row['BRDNAM']
				?? $row['BRDNAME']
				?? $row['BRDDESC']
				?? ''
			);

			if ( $brdno === '' )
			{
				/* Try a fallback by scanning keys */
				foreach ( $row as $k => $v )
				{
					if ( stripos( $k, 'NO' ) !== false && is_numeric( $v ) )
					{
						$brdno = (string) $v;
						break;
					}
				}
			}

			if ( $brdno === '' )
			{
				continue;
			}

			try
			{
				\IPS\Db::i()->replace( 'gd_sportssouth_brands', [
					'brdno'       => (int) $brdno,
					'brdnam'      => $brdnam,
					'last_synced' => $syncedAt,
					'raw_data'    => json_encode( $row ),
				] );
				$count++;
			}
			catch ( \Throwable $rowException )
			{
				try { \IPS\Log::log( 'Brand row insert failed brdno=' . $brdno . ': ' . $rowException->getMessage(), 'gdcatalog_sportssouth_lookups' ); } catch ( \Throwable ) {}
			}
		}

		return [
			'count'       => $count,
			'elapsed_ms'  => $elapsed,
			'sample_keys' => $sampleKeys,
		];
	}

	/**
	 * v1.0.11 helper: Process category lookup from Sports South API.
	 */
	protected function processCategoryLookup( \IPS\gdcatalog\Feed\Distributor\SportsSouthClient $client, int $syncedAt ): array
	{
		$start = microtime( true );
		$rows = $client->categoryUpdate();
		$elapsed = round( ( microtime( true ) - $start ) * 1000 );

		$sampleKeys = !empty( $rows ) ? array_keys( $rows[0] ) : [];
		$count = 0;

		foreach ( $rows as $row )
		{
			$catid = (string) ( $row['CATID'] ?? '' );
			$catdes = (string) ( $row['CATDES'] ?? $row['CATDESC'] ?? $row['CATEGORY'] ?? '' );

			if ( $catid === '' )
			{
				continue;
			}

			try
			{
				\IPS\Db::i()->replace( 'gd_sportssouth_categories', [
					'catid'       => (int) $catid,
					'catdes'      => $catdes,
					'last_synced' => $syncedAt,
					'raw_data'    => json_encode( $row ),
				] );
				$count++;
			}
			catch ( \Throwable $rowException )
			{
				try { \IPS\Log::log( 'Category row insert failed catid=' . $catid . ': ' . $rowException->getMessage(), 'gdcatalog_sportssouth_lookups' ); } catch ( \Throwable ) {}
			}
		}

		return [
			'count'       => $count,
			'elapsed_ms'  => $elapsed,
			'sample_keys' => $sampleKeys,
		];
	}

	/**
	 * v1.0.8: Test the Sports South connection for a distributor feed.
	 * Calls DailyItemCount (lightweight) to verify creds + reachability,
	 * then calls DailyItemUpdate with LastUpdate=1/1/1990 and LastItem=0
	 * to pull a small sample of products for display. Does NOT write
	 * anything to gd_catalog — purely a connection validator.
	 *
	 * URL: ?app=gdcatalog&module=catalog&controller=feeds&do=testConnection&id=N
	 */
	protected function testConnection()
	{
		try
		{
			$id = (int) \IPS\Request::i()->id;
			if ( $id <= 0 )
			{
				throw new \RuntimeException( 'Missing distributor feed ID' );
			}

			$feed = \IPS\gdcatalog\Feed\Distributor::load( $id );
			if ( $feed->auth_type !== 'sportssouth' )
			{
				throw new \RuntimeException( sprintf(
					'Test Connection is only available for Sports South feeds. This feed has auth_type=%s',
					(string) $feed->auth_type
				) );
			}

			$client = \IPS\gdcatalog\Feed\Distributor\SportsSouthClient::fromDistributor( $feed );
			$credErrors = $client->validate();
			if ( !empty( $credErrors ) )
			{
				throw new \RuntimeException(
					'Credential validation failed: ' . implode( '; ', $credErrors )
				);
			}

			/* v1.0.10: pull a 30-day window instead of full catalog. Full
			 * catalog (LastUpdate=1/1/1990) returns 58k+ products and
			 * exceeds PHP timeouts in the test path. */
			$sinceDate = date( 'n/j/Y', strtotime( '-30 days' ) );

			/* Step 1: lightweight count call */
			$startCount = microtime( true );
			$count = $client->dailyItemCount( $sinceDate );
			$elapsedCount = round( ( microtime( true ) - $startCount ) * 1000 );

			/* Step 2: pull a single page of products to inspect shape */
			$startPull = microtime( true );
			$products = $client->dailyItemUpdate( $sinceDate, 0 );
			$elapsedPull = round( ( microtime( true ) - $startPull ) * 1000 );

			$sample = array_slice( $products, 0, 10 );

			/* Build the results HTML */
			$html  = '<div style="padding:16px 20px;max-width:1200px;margin:0 auto">';
			$html .= '<h2 style="margin:0 0 16px">Sports South Connection Test</h2>';
			$html .= '<div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:16px">';
			$html .= '<strong>Connection successful</strong><br>';
			$html .= 'DailyItemCount: ' . (int) $count . ' items changed since ' . htmlspecialchars( $sinceDate, ENT_QUOTES, 'UTF-8' ) . ' (' . $elapsedCount . 'ms)<br>';
			$html .= 'DailyItemUpdate: pulled ' . count( $products ) . ' products in this page (' . $elapsedPull . 'ms)';
			$html .= '</div>';

			if ( empty( $products ) )
			{
				$html .= '<p>No products returned. Credentials may be wrong or test account is empty.</p>';
			}
			else
			{
				/* Show first 10 products in a table */
				$firstProduct = $products[0];
				$fieldNames = array_keys( $firstProduct );
				$html .= '<h3 style="margin:20px 0 12px">First ' . count( $sample ) . ' Products (Fields)</h3>';
				$html .= '<div style="overflow-x:auto;border:1px solid #e5e7eb;border-radius:8px"><table style="width:100%;border-collapse:collapse;font-size:0.85em;background:#fff">';
				$html .= '<thead><tr style="background:#f9fafb">';
				foreach ( $fieldNames as $fname )
				{
					$html .= '<th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e5e7eb;font-weight:600">' . htmlspecialchars( $fname, ENT_QUOTES, 'UTF-8' ) . '</th>';
				}
				$html .= '</tr></thead><tbody>';
				foreach ( $sample as $prod )
				{
					$html .= '<tr style="border-bottom:1px solid #f3f4f6">';
					foreach ( $fieldNames as $fname )
					{
						$val = (string) ( $prod[ $fname ] ?? '' );
						if ( strlen( $val ) > 80 )
						{
							$val = substr( $val, 0, 77 ) . '...';
						}
						$html .= '<td style="padding:6px 10px;vertical-align:top">' . htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' ) . '</td>';
					}
					$html .= '</tr>';
				}
				$html .= '</tbody></table></div>';

				$html .= '<h3 style="margin:20px 0 12px">Raw First Product (JSON)</h3>';
				$html .= '<pre style="background:#1f2937;color:#f3f4f6;padding:12px;border-radius:8px;overflow-x:auto;font-size:0.8em;line-height:1.5">';
				$html .= htmlspecialchars( json_encode( $firstProduct, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), ENT_QUOTES, 'UTF-8' );
				$html .= '</pre>';
			}

			$backUrl = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' );
			$html .= '<div style="margin-top:16px"><a href="' . htmlspecialchars( $backUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton_normal">Back to feeds</a></div>';
			$html .= '</div>';

			try { \IPS\Log::log( 'Sports South test connection success: count=' . (int) $count . ', pulled=' . count( $products ), 'gdcatalog_sportssouth_test' ); } catch ( \Throwable ) {}

			Output::i()->title  = 'Sports South Connection Test';
			Output::i()->output = $html;
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Sports South test connection FAILED: ' . $e->getMessage(), 'gdcatalog_sportssouth_test' ); } catch ( \Throwable ) {}

			$backUrl = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' );

			$html  = '<div style="padding:16px 20px;max-width:1100px;margin:0 auto">';
			$html .= '<h2 style="margin:0 0 16px">Sports South Connection Test</h2>';
			$html .= '<div style="background:#fee2e2;border:1px solid #fca5a5;color:#7f1d1d;padding:12px 16px;border-radius:8px;margin-bottom:16px">';
			$html .= '<strong>Connection failed</strong><br>';
			$html .= htmlspecialchars( $e->getMessage(), ENT_QUOTES, 'UTF-8' );
			$html .= '</div>';
			$html .= '<p style="color:#6b7280;font-size:0.9em">Check the IPS error log for full details. Filter by category=<code>gdcatalog_sportssouth_test</code>.</p>';
			$html .= '<div style="margin-top:16px"><a href="' . htmlspecialchars( $backUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton_normal">Back to feeds</a></div>';
			$html .= '</div>';

			Output::i()->title  = 'Sports South Connection Test - Failed';
			Output::i()->output = $html;
		}
	}
	protected function resetFeedStatus(): void
	{
		\IPS\Session::i()->csrfCheck();

		$feedId = (int) Request::i()->id;
		try
		{
			$feed = Distributor::load( $feedId );
			$feed->resetRunningStatus();
		}
		catch ( \Throwable ) {}

		Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
			'Feed status reset.'
		);
	}

	protected function catAttrs(): void
	{
		$ssFeed = null;
		try
		{
			foreach ( Distributor::loadAll() as $f )
			{
				if ( (string) $f->auth_type === 'sportssouth' )
				{
					$ssFeed = $f;
					break;
				}
			}
		}
		catch ( \Throwable ) {}

		if ( $ssFeed === null )
		{
			Output::i()->title  = 'Sports South Category Attribute Labels';
			Output::i()->output = '<p style="color:#b00">No Sports South feed configured.</p>';
			return;
		}

		$client = \IPS\gdcatalog\Feed\Distributor\SportsSouthClient::fromDistributor( $ssFeed );

		$rows = [];
		$err  = '';
		try { $rows = $client->categoryUpdate(); }
		catch ( \Throwable $e ) { $err = $e->getMessage(); }

		$cols = [];
		foreach ( $rows as $r ) { foreach ( array_keys( $r ) as $k ) { $cols[ $k ] = TRUE; } }
		$cols = array_keys( $cols );

		$localCounts = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'name, product_count', 'gd_categories' ) as $cat )
			{
				$localCounts[ mb_strtolower( trim( (string) $cat['name'] ) ) ] = (int) $cat['product_count'];
			}
		}
		catch ( \Throwable ) {}

		$targets = [ 'cleaning', 'electronic', 'comm', 'gunsmith', 'parts', 'reload', 'storage', 'safety', 'tactical', 'training' ];

		$backUrl = (string) \IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' );

		$html = '<div style="padding:16px 20px;max-width:100%;margin:0 auto">';
		$html .= '<h2 style="margin:0 0 16px">Sports South Category Attribute Labels</h2>';
		$html .= '<p style="color:#6b7280;font-size:0.9em;margin-bottom:16px">Highlighted rows match the 8 target categories needing facet mappings. "Local Count" shows product_count from gd_categories matched by name.</p>';

		if ( $err !== '' )
		{
			$html .= '<div style="background:#fee2e2;border:1px solid #fca5a5;color:#7f1d1d;padding:12px 16px;border-radius:8px;margin-bottom:16px">' . htmlspecialchars( $err, ENT_QUOTES, 'UTF-8' ) . '</div>';
		}

		$html .= '<div style="overflow-x:auto;border:1px solid #e5e7eb;border-radius:8px"><table style="width:100%;border-collapse:collapse;font-size:0.8em;background:#fff">';
		$html .= '<thead><tr style="background:#f9fafb">';
		foreach ( $cols as $c )
		{
			$html .= '<th style="text-align:left;padding:6px 8px;border-bottom:2px solid #e5e7eb;font-weight:600;white-space:nowrap">' . htmlspecialchars( $c, ENT_QUOTES, 'UTF-8' ) . '</th>';
		}
		$html .= '<th style="text-align:right;padding:6px 8px;border-bottom:2px solid #e5e7eb;font-weight:600;white-space:nowrap">Local Count</th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $r )
		{
			$name = strtolower( implode( ' ', array_map( 'strval', $r ) ) );
			$hit  = FALSE;
			foreach ( $targets as $t ) { if ( str_contains( $name, $t ) ) { $hit = TRUE; break; } }

			$catName = mb_strtolower( trim( (string) ( $r['CATDES'] ?? $r['CATDESC'] ?? $r['CATEGORY'] ?? '' ) ) );
			$localCount = $localCounts[ $catName ] ?? '—';

			$bg = $hit ? 'background:#fff7e0;' : '';
			$html .= '<tr style="' . $bg . 'border-bottom:1px solid #f3f4f6">';
			foreach ( $cols as $c )
			{
				$val = (string) ( $r[ $c ] ?? '' );
				$html .= '<td style="padding:4px 8px;vertical-align:top;white-space:nowrap">' . htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' ) . '</td>';
			}
			$html .= '<td style="padding:4px 8px;text-align:right;vertical-align:top">' . htmlspecialchars( (string) $localCount, ENT_QUOTES, 'UTF-8' ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';

		$html .= '<div style="margin-top:16px"><a href="' . htmlspecialchars( $backUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton_normal">Back to feeds</a></div>';
		$html .= '</div>';

		Output::i()->title  = 'Sports South Category Attribute Labels';
		Output::i()->output = $html;
	}
	protected function reExtractAttributes(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'feeds_manage' );
		\IPS\Session::i()->csrfCheck();

		$attrCols = [
			'product_type','material','color','finish','size','mount_type','fit','battery_size','nrr','lock_type','species',
			'caliber','capacity','action_type','case_type','bullet_type',
			'holster_type','holster_color','holster_material','holster_hand',
			'apparel_pattern','apparel_size','apparel_material',
			'hunt_call_type','hunt_game',
			'blade_shape','blade_length','blade_material','blade_edge','knife_handle',
			'optic_magnification','optic_objective',
		];
		$liveCols = \IPS\gdcatalog\Feed\Importer::catalogColumns();
		$valid = array_values( array_filter( $attrCols, fn( $c ) => in_array( $c, $liveCols, true ) ) );

		$updated = 0;
		$scanned = 0;

		foreach ( \IPS\Db::i()->select( 'upc, raw_distributor_data', 'gd_catalog', [ 'raw_distributor_data IS NOT NULL' ] ) as $row )
		{
			$scanned++;
			$raw = json_decode( (string) $row['raw_distributor_data'], true );
			if ( !is_array( $raw ) ) { continue; }

			$ssCatId = (int) ( $raw['CATID'] ?? 0 );
			if ( $ssCatId <= 0 ) { continue; }

			$set = [];
			for ( $i = 1; $i <= 20; $i++ )
			{
				$itatrKey = $i === 10 ? 'ITATR0' : 'ITATR' . $i;
				$val = trim( (string) ( $raw[ $itatrKey ] ?? '' ) );
				if ( $val === '' ) { continue; }

				$col = \IPS\gdcatalog\Feed\Distributor\SportsSouthAttributeMap::resolve( $ssCatId, $i );
				if ( $col !== null && in_array( $col, $valid, true ) && !isset( $set[ $col ] ) )
				{
					$set[ $col ] = mb_substr( $val, 0, 150 );
				}
			}

			if ( $set )
			{
				\IPS\Db::i()->update( 'gd_catalog', $set, [ 'upc=?', $row['upc'] ] );
				$updated++;
			}
		}

		Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=feeds' ),
			"Re-extracted attributes: {$updated} of {$scanned} products updated."
		);
	}
}

class feeds extends _feeds {}
