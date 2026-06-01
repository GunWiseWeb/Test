<?php
/**
 * @brief       GD Master Catalog — ACP Dashboard Controller
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Section 2.9: Total product count, per-distributor stats, manual
 * import trigger, OpenSearch status, rebuild index button.
 */

namespace IPS\gdcatalog\modules\admin\catalog;

/* To prevent PHP errors (extending class does not exist) revealing path */

use IPS\gdcatalog\Feed\Distributor;
use IPS\gdcatalog\Feed\Importer;
use IPS\gdcatalog\Catalog\Product;
use IPS\gdcatalog\Catalog\Category;
use IPS\gdcatalog\Search\OpenSearchIndexer;
use IPS\Task;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _dashboard extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'catalog_manage' );
		parent::execute();
	}

	/**
	 * Dashboard overview.
	 *
	 * Every query is wrapped in its own try/catch so a single missing table
	 * or transient DB hiccup cannot hang the page. OpenSearch is NOT queried
	 * here — live HTTP probes to the search cluster were causing the page
	 * to hang indefinitely, so status is reported as unavailable and the
	 * dedicated rebuild/processQueue actions perform the real work on demand.
	 */
	protected function manage()
	{
		/* Total product counts */
		$totalProducts  = 0;
		$activeProducts = 0;
		$reviewProducts = 0;

		try { $totalProducts  = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog' )->first(); } catch ( \Throwable ) {}
		try { $activeProducts = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'record_status=?', 'active' ] )->first(); } catch ( \Throwable ) {}
		try { $reviewProducts = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'record_status=?', 'admin_review' ] )->first(); } catch ( \Throwable ) {}

		/* Per-category counts */
		$categoryCounts = [];
		try
		{
			foreach ( Category::roots() as $cat )
			{
				$count = 0;
				try { $count = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'category_id=?', $cat->id ] )->first(); } catch ( \Throwable ) {}
				$categoryCounts[] = [ 'name' => $cat->name, 'count' => $count ];
			}
		}
		catch ( \Throwable ) {}

		/* Per-distributor stats from latest import logs.
		 *
		 * Values are flattened to scalar keys so the dashboard template
		 * can use plain `{$ds['key']}` access without mixing array and
		 * object traversal, which the IPS template compiler rejects with
		 * UnexpectedValueException. The Run Import URL is also built
		 * here so the template does not need a nested `{url="..."}` tag
		 * with embedded `{$...}` placeholders inside an `{{if}}` block.
		 */
		$distributorStats = [];
		try
		{
			foreach ( Distributor::loadAll() as $feed )
			{
				$lastLog = null;
				try
				{
					$lastLog = \IPS\Db::i()->select(
						'*', 'gd_import_log',
						[ 'feed_id=?', $feed->id ],
						'run_start DESC', [ 0, 1 ]
					)->first();
				}
				catch ( \Throwable ) {}

				$productCount = 0;
				try
				{
					$productCount = (int) \IPS\Db::i()->select(
						'COUNT(*)', 'gd_catalog',
						[ 'FIND_IN_SET(?, distributor_sources)', $feed->distributor ]
					)->first();
				}
				catch ( \Throwable ) {}

				$runImportUrl = (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=dashboard&do=runImport&id=' . (int) $feed->id
				)->csrf();

				/* v1.0.24: Queue Full Import URL - background task that pages
				 * through the full ~58k Sports South catalog. Only meaningful
				 * for sportssouth feeds; gated by is_sportssouth flag below. */
				$queueFullImportUrl = (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=dashboard&do=queueFullImport&id=' . (int) $feed->id
				)->csrf();

				/* v1.0.29: Compute the labels the dashboard template expects.
				 * Pre-existing bug: template fields (distributor_label, etc)
				 * never existed in the controller output, so the distributor
				 * table rendered empty cells for everything except feed_name
				 * and the action buttons. */
				$distributorLabel = ucwords( str_replace( '_', ' ', (string) $feed->distributor ) );

				$importSchedule = (string) ( $feed->import_schedule ?? '' );
				$scheduleMap = [
					'15min' => 'Every 15 min',
					'30min' => 'Every 30 min',
					'1hr'   => 'Hourly',
					'6hr'   => 'Every 6 hours',
					'daily' => 'Daily',
				];
				$scheduleLabel = $scheduleMap[ $importSchedule ] ?? ( $importSchedule !== '' ? $importSchedule : '—' );

				$lastRunRaw   = $lastLog['run_start'] ?? ( $feed->last_run ?? null );
				$lastRunLabel = '—';
				if ( !empty( $lastRunRaw ) )
				{
					try
					{
						$ts = is_numeric( $lastRunRaw ) ? (int) $lastRunRaw : strtotime( (string) $lastRunRaw );
						if ( $ts > 0 )
						{
							$lastRunLabel = date( 'Y-m-d H:i', $ts );
						}
					}
					catch ( \Throwable ) {}
				}

				$lastFeedStatus = (string) ( $feed->last_run_status ?? '' );

				/* v1.0.36: Lock status + URLs for Lock/Unlock Catalog buttons. */
				$isLocked = (int) ( $feed->locked ?? 0 ) === 1;

				$lockCatalogUrl = (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=dashboard&do=lockCatalog&feed_id=' . (int) $feed->id
				)->csrf();

				$unlockCatalogUrl = (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=dashboard&do=unlockCatalog&feed_id=' . (int) $feed->id
				)->csrf();

				$lockedAtHuman = '';
				if ( $isLocked && !empty( $feed->locked_at ) )
				{
					try
					{
						$lockedAtHuman = (string) \IPS\DateTime::ts( strtotime( (string) $feed->locked_at ) )->relative();
					}
					catch ( \Throwable )
					{
						$lockedAtHuman = (string) $feed->locked_at;
					}
				}

				$distributorStats[] = [
					'priority'              => (int) $feed->priority,
					'feed_name'             => (string) $feed->feed_name,
					'feed_id'               => (int) $feed->id,
					'active'                => (bool) $feed->active,
					'product_count'         => $productCount,
					'last_run_start'        => $lastLog['run_start'] ?? null,
					'last_status'           => $lastLog['status'] ?? null,
					'run_import_url'        => $runImportUrl,
					'queue_full_import_url' => $queueFullImportUrl,
					'is_sportssouth'        => $feed->auth_type === 'sportssouth',

					/* v1.0.29: fields the dashboard template expects */
					'distributor_label'     => $distributorLabel,
					'schedule_label'        => $scheduleLabel,
					'last_run_label'        => $lastRunLabel,
					'record_count'          => $productCount,
					'is_running'            => $lastFeedStatus === 'running',
					'is_failed'             => $lastFeedStatus === 'failed',

					/* v1.0.36: Per-distributor lock state */
					'is_locked'             => $isLocked,
					'locked_at_human'       => $lockedAtHuman,
					'lock_catalog_url'      => $lockCatalogUrl,
					'unlock_catalog_url'    => $unlockCatalogUrl,
				];
			}
		}
		catch ( \Throwable ) {}

		/* v1.0.30: Real OpenSearch probe.
		 *
		 * The hardcoded FALSE was a workaround for a HEAD-request hang bug
		 * in OpenSearchIndexer::request() that v1.0.30 fixed. Now we can
		 * call indexExists() and getStats() with confidence - HEAD now uses
		 * CURLOPT_NOBODY+3s timeout, so worst case is a 3-second add to
		 * page render if OpenSearch is down. */
		$osExists = FALSE;
		$osStats  = [];

		try
		{
			$indexer  = OpenSearchIndexer::i();
			$osExists = $indexer->indexExists();

			if ( $osExists )
			{
				try
				{
					$stats = $indexer->getStats();
				}
				catch ( \Throwable )
				{
					$stats = [];
				}

				$osStats['doc_count']  = (int) ( $stats['doc_count'] ?? 0 );
				$osStats['size_bytes'] = (int) ( $stats['size_bytes'] ?? 0 );
			}

			/* URLs are always set regardless of $osExists - they're rendered
			 * in different template branches (Build Index Now vs Rebuild). */
			$osStats['rebuild_url'] = (string) \IPS\Http\Url::internal(
				'app=gdcatalog&module=catalog&controller=dashboard&do=rebuildIndex'
			)->csrf();

			$osStats['process_queue_url'] = (string) \IPS\Http\Url::internal(
				'app=gdcatalog&module=catalog&controller=dashboard&do=processQueue'
			)->csrf();
		}
		catch ( \Throwable $e )
		{
			/* Keep $osExists = FALSE on any unexpected error so the
			 * "Build Index Now" path still renders. Log for diagnosis. */
			try { \IPS\Log::log( 'Dashboard OpenSearch probe failed: ' . $e->getMessage(), 'gdcatalog_dashboard' ); } catch ( \Throwable ) {}

			/* But still set the URLs so the button works even if probe failed. */
			try
			{
				$osStats['rebuild_url'] = (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=dashboard&do=rebuildIndex'
				)->csrf();
				$osStats['process_queue_url'] = (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=dashboard&do=processQueue'
				)->csrf();
			}
			catch ( \Throwable ) {}
		}

		/* Pending items */
		$pendingConflicts  = 0;
		$pendingCompliance = 0;
		$lockedFields      = 0;
		$reindexQueue      = 0;

		try { $pendingConflicts  = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_feed_conflicts', [ 'status=?', 'pending' ] )->first(); } catch ( \Throwable ) {}
		try { $pendingCompliance = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_flags', [ 'status=?', 'pending_review' ] )->first(); } catch ( \Throwable ) {}
		try { $lockedFields      = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_field_locks' )->first(); } catch ( \Throwable ) {}
		try { $reindexQueue      = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_reindex_queue' )->first(); } catch ( \Throwable ) {}

		/* v1.0.29: URLs for the new Run Now buttons in the dashboard. */
		$taskUrls = [
			'validate_images'   => (string) \IPS\Http\Url::internal(
				'app=gdcatalog&module=catalog&controller=dashboard&do=runValidateImages'
			)->csrf(),
			'resolve_conflicts' => (string) \IPS\Http\Url::internal(
				'app=gdcatalog&module=catalog&controller=dashboard&do=runResolveConflicts'
			)->csrf(),
			'prune_log'         => (string) \IPS\Http\Url::internal(
				'app=gdcatalog&module=catalog&controller=dashboard&do=runPruneLog'
			)->csrf(),
		];

		$runQueueUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=dashboard&do=runQueue'
		)->csrf();

		$queueCount = 0;
		try { $queueCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_queue', [ 'app=?', 'gdcatalog' ] )->first(); } catch ( \Throwable ) {}

		$syncBrandsUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=dashboard&do=syncBrands'
		)->csrf();

		$monitorUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=dashboard&do=monitor'
		);

		\IPS\Output::i()->jsVars['gdcatalog_monitor_url'] = $monitorUrl;
		\IPS\Output::i()->js( 'dashboardMonitor.js', 'gdcatalog', 'interface' );

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_dash_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->dashboard(
			$totalProducts,
			$activeProducts,
			$reviewProducts,
			$pendingConflicts,
			$distributorStats,
			$taskUrls,
			$osExists,
			$osStats,
			$reindexQueue,
			$lockedFields,
			$runQueueUrl,
			$queueCount,
			$syncBrandsUrl,
			$monitorUrl
		);
	}

	/**
	 * Manual import trigger — runs a single feed immediately.
	 */
	protected function runImport()
	{
		\IPS\Session::i()->csrfCheck();

		$feedId = (int) \IPS\Request::i()->id;
		$feed   = Distributor::load( $feedId );

		$log = Importer::run( $feed );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
			$log->status === 'completed'
				? "Import completed: {$log->records_created} created, {$log->records_updated} updated"
				: "Import failed: " . ( $log->error_log ?? 'unknown error' )
		);
	}

	/**
	 * v1.0.24: Queue the Sports South full catalog import as a background task.
	 *
	 * Unlike runImport() which executes synchronously and is capped at 1000
	 * products per call (MAX_RECORDS_PER_RUN), this action enqueues an IPS
	 * Task that processes the full ~58k-product catalog in 1000-product
	 * chunks via cron.
	 *
	 * Only available for feeds with auth_type='sportssouth'.
	 */
	protected function queueFullImport()
	{
		\IPS\Session::i()->csrfCheck();

		$feedId = (int) \IPS\Request::i()->id;

		try
		{
			$feed = Distributor::load( $feedId );
		}
		catch ( \OutOfRangeException )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
				'Feed not found'
			);
			return;
		}

		if ( $feed->auth_type !== 'sportssouth' )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
				'Queue Full Import is only available for Sports South feeds'
			);
			return;
		}

		try
		{
			\IPS\Task::queue(
				'gdcatalog',
				'SportsSouthImport',
				[ 'feed_id' => $feedId ],
				4,
				[ 'feed_id' ]
			);

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
				'Sports South full catalog import queued. Progress visible in AdminCP -> System -> Background Processes.'
			);
		}
		catch ( \Throwable $e )
		{
			try
			{
				\IPS\Log::log(
					'Failed to queue SportsSouthImport for feed_id=' . $feedId . ': ' . $e->getMessage(),
					'gdcatalog_queue'
				);
			}
			catch ( \Throwable ) {}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
				'Failed to queue import: ' . $e->getMessage()
			);
		}
	}

	/**
	 * Rebuild OpenSearch index.
	 */
	protected function rebuildIndex()
	{
		\IPS\Session::i()->csrfCheck();

		$indexer = OpenSearchIndexer::i();
		$count   = $indexer->rebuildIndex();

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
			"OpenSearch index rebuilt: {$count} documents indexed"
		);
	}

	/**
	 * Process the reindex queue now.
	 */
	protected function processQueue()
	{
		\IPS\Session::i()->csrfCheck();

		$indexer = OpenSearchIndexer::i();
		$count   = $indexer->processQueue( 2000 );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
			"Processed reindex queue: {$count} documents indexed"
		);
	}

	/**
	 * v1.0.29: Run the ValidateProductImages task synchronously.
	 * HEAD-checks the next batch of image URLs.
	 */
	protected function runValidateImages()
	{
		\IPS\Session::i()->csrfCheck();

		$message = 'Image validation task ran';
		try
		{
			$task   = new \IPS\gdcatalog\tasks\ValidateProductImages;
			$result = $task->execute();
			if ( is_string( $result ) && $result !== '' )
			{
				$message = $result;
			}
		}
		catch ( \Throwable $e )
		{
			$message = 'Image validation task failed: ' . $e->getMessage();
			try { \IPS\Log::log( $message, 'gdcatalog_imgcheck' ); } catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
			$message
		);
	}

	/**
	 * v1.0.29: Run the AutoResolveConflicts task synchronously.
	 */
	protected function runResolveConflicts()
	{
		\IPS\Session::i()->csrfCheck();

		$message = 'Auto-resolve conflicts task ran';
		try
		{
			$task   = new \IPS\gdcatalog\tasks\AutoResolveConflicts;
			$result = $task->execute();
			if ( is_string( $result ) && $result !== '' )
			{
				$message = $result;
			}
		}
		catch ( \Throwable $e )
		{
			$message = 'Auto-resolve conflicts task failed: ' . $e->getMessage();
			try { \IPS\Log::log( $message, 'gdcatalog_autoresolve' ); } catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
			$message
		);
	}

	/**
	 * v1.0.29: Run the PruneConflictLog task synchronously.
	 */
	protected function runPruneLog()
	{
		\IPS\Session::i()->csrfCheck();

		$message = 'Prune conflict log task ran';
		try
		{
			$task   = new \IPS\gdcatalog\tasks\PruneConflictLog;
			$result = $task->execute();
			if ( is_string( $result ) && $result !== '' )
			{
				$message = $result;
			}
		}
		catch ( \Throwable $e )
		{
			$message = 'Prune conflict log task failed: ' . $e->getMessage();
			try { \IPS\Log::log( $message, 'gdcatalog_prune' ); } catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
			$message
		);
	}

	/**
	 * v1.0.54: Process all pending gdcatalog queue jobs in one shot.
	 */
	protected function runQueue(): void
	{
		\IPS\Session::i()->csrfCheck();

		$processed = 0;
		$products  = 0;

		try
		{
			$limit = 500;
			while ( $limit-- > 0 )
			{
				$queueRow = \IPS\Db::i()->select(
					'*', 'core_queue', [ 'app=?', 'gdcatalog' ], 'priority ASC', [ 0, 1 ]
				)->first();

				$task = \IPS\Task::constructFromData( $queueRow );
				$task->runAndLog();
				$processed++;
			}
		}
		catch ( \UnderflowException )
		{
			// Queue empty — normal exit
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'runQueue error after ' . $processed . ' jobs: ' . $e->getMessage(), 'gdcatalog_run_queue' ); } catch ( \Throwable ) {}
		}

		try { $products = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog' )->first(); } catch ( \Throwable ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
			\IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_queue_ran', FALSE, [
				'sprintf' => [ $processed, number_format( $products ) ]
			] )
		);
	}

	/**
	 * v1.0.36: Lock a distributor's catalog.
	 *
	 * Sets the feed's locked flag and enqueues LockDistributorCatalog queue
	 * job which iterates every product where this distributor is in
	 * distributor_sources and locks every populated field.
	 *
	 * After lock completes, future distributor imports for these products
	 * cannot overwrite the locked fields - all changes route to
	 * gd_feed_conflicts (and auto-resolve after 48 hours via the existing
	 * AutoResolveConflicts task).
	 */
	protected function lockCatalog()
	{
		\IPS\Session::i()->csrfCheck();

		$feedId = (int) \IPS\Request::i()->feed_id;

		try
		{
			$feed = \IPS\gdcatalog\Feed\Distributor::load( $feedId );
		}
		catch ( \OutOfRangeException )
		{
			\IPS\Output::i()->error( 'Feed not found', '2GDC100/L1', 404 );
			return;
		}

		if ( (int) ( $feed->locked ?? 0 ) === 1 )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
				'Catalog is already locked'
			);
			return;
		}

		/* Set lock flags first - so even if queue takes time to start, the
		 * UI shows "locked" status. */
		try { $feed->markLocked(); } catch ( \Throwable ) {}

		/* Enqueue the bulk-lock background task. Same pattern as Sports South
		 * full catalog import. */
		try
		{
			\IPS\Task::queue(
				'gdcatalog',
				'LockDistributorCatalog',
				[
					'feed_id'     => $feedId,
					'distributor' => (string) $feed->distributor,
				],
				4,
				[ 'feed_id' ]
			);

			try { \IPS\Log::log( sprintf( 'Admin enqueued LockDistributorCatalog for feed_id=%d distributor=%s', $feedId, $feed->distributor ), 'gdcatalog_lock' ); } catch ( \Throwable ) {}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
				sprintf( 'Lock Catalog queued for %s. Progress visible in AdminCP -> System -> Background Processes.', $feed->distributor )
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'lockCatalog enqueue failed: ' . $e->getMessage(), 'gdcatalog_lock' ); } catch ( \Throwable ) {}
			\IPS\Output::i()->error( 'Failed to enqueue lock task: ' . $e->getMessage(), '2GDC100/L2', 500 );
		}
	}

	/**
	 * v1.0.36: Unlock a distributor's catalog.
	 *
	 * Clears the feed's locked flags and runs a synchronous UPDATE to wipe
	 * the locked_fields JSON column for all products from this distributor.
	 *
	 * Unlock is fast (single UPDATE) so no queue needed. After unlock,
	 * distributor imports will resume overwriting fields normally.
	 */
	protected function unlockCatalog()
	{
		\IPS\Session::i()->csrfCheck();

		$feedId = (int) \IPS\Request::i()->feed_id;

		try
		{
			$feed = \IPS\gdcatalog\Feed\Distributor::load( $feedId );
		}
		catch ( \OutOfRangeException )
		{
			\IPS\Output::i()->error( 'Feed not found', '2GDC100/U1', 404 );
			return;
		}

		if ( (int) ( $feed->locked ?? 0 ) !== 1 )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
				'Catalog was not locked'
			);
			return;
		}

		$affected = 0;
		try
		{
			/* Clear locked_fields on every product where this distributor is
			 * in distributor_sources. NULL is preferred over empty JSON []
			 * because getLockedFields() treats both as empty. */
			$affected = \IPS\Db::i()->update(
				'gd_catalog',
				[ 'locked_fields' => null ],
				[ 'FIND_IN_SET(?, distributor_sources)', $feed->distributor ]
			);

			try { $feed->markUnlocked(); } catch ( \Throwable ) {}

			try { \IPS\Log::log( sprintf( 'Admin unlocked catalog for feed_id=%d distributor=%s - cleared locked_fields on %d products', $feedId, $feed->distributor, (int) $affected ), 'gdcatalog_lock' ); } catch ( \Throwable ) {}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
				sprintf( 'Unlocked %s catalog. Field locks cleared from %d products.', $feed->distributor, (int) $affected )
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'unlockCatalog failed: ' . $e->getMessage(), 'gdcatalog_lock' ); } catch ( \Throwable ) {}
			\IPS\Output::i()->error( 'Failed to unlock catalog: ' . $e->getMessage(), '2GDC100/U2', 500 );
		}
	}

	protected function syncBrands(): void
	{
		\IPS\Session::i()->csrfCheck();

		$synced = 0;
		try
		{
			$feedRow = \IPS\Db::i()->select( '*', 'gd_distributor_feeds', [ 'distributor=?', 'sports_south' ] )->first();
			$feed    = Distributor::constructFromData( $feedRow );
			$client  = \IPS\gdcatalog\Feed\Distributor\SportsSouthClient::fromDistributor( $feed );
			$brands  = $client->brandUpdate();

			foreach ( $brands as $brand )
			{
				try
				{
					\IPS\Db::i()->replace( 'gd_sportssouth_brands', [
						'brdno'  => (int) $brand['BRDNO'],
						'brdnam' => (string) $brand['BRDNM'],
					] );
					$synced++;
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( $e, 'gdcatalog_sync_brands' );
		}

		if ( $synced > 0 )
		{
			try
			{
				\IPS\Task\Queue::queue( 'gdcatalog', 'ResolveBrands', [ 'offset' => 0 ] );
			}
			catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=dashboard' ),
			\IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_brands_synced', FALSE, [ 'sprintf' => [ $synced ] ] )
		);
	}

	protected function monitor(): void
	{
		$data = [
			'products'          => 0,
			'active'            => 0,
			'queue_total'       => 0,
			'queue_jobs'        => [],
			'pending_conflicts' => 0,
			'broken_images'     => 0,
			'no_image'          => 0,
			'last_import'       => [],
			'feeds'             => [],
			'timestamp'         => time(),
		];

		try { $data['products']          = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog' )->first(); } catch ( \Throwable ) {}
		try { $data['active']            = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'record_status=?', 'active' ] )->first(); } catch ( \Throwable ) {}
		try { $data['queue_total']       = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_queue', [ 'app=?', 'gdcatalog' ] )->first(); } catch ( \Throwable ) {}
		try { $data['pending_conflicts'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_flags', [ 'status=?', 'pending_review' ] )->first(); } catch ( \Throwable ) {}
		try { $data['broken_images']     = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'image_validated=1 AND (image_http_status >= 400 OR image_http_status = 0)' ] )->first(); } catch ( \Throwable ) {}
		try { $data['no_image']          = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'image_url IS NULL OR image_url=?', '' ] )->first(); } catch ( \Throwable ) {}

		try
		{
			foreach ( \IPS\Db::i()->select( 'key, data', 'core_queue', [ 'app=?', 'gdcatalog' ] ) as $row )
			{
				$qData  = json_decode( $row['data'], true );
				$offset = $qData['offset'] ?? 0;
				$data['queue_jobs'][] = [ 'key' => $row['key'], 'offset' => $offset ];
			}
		}
		catch ( \Throwable ) {}

		try
		{
			$row = \IPS\Db::i()->select(
				'feed_id, status, records_created, records_updated, records_errored, run_started_at, run_completed_at',
				'gd_import_log',
				[],
				'id DESC',
				[ 0, 1 ]
			)->first();
			$data['last_import'] = [
				'feed_id'   => $row['feed_id'],
				'status'    => $row['status'],
				'created'   => $row['records_created'],
				'updated'   => $row['records_updated'],
				'errored'   => $row['records_errored'],
				'started'   => $row['run_started_at'],
				'completed' => $row['run_completed_at'],
			];
		}
		catch ( \Throwable ) {}

		try
		{
			foreach ( \IPS\Db::i()->select( 'id, feed_name, active, last_run, last_run_status, last_record_count', 'gd_distributor_feeds' ) as $row )
			{
				$data['feeds'][] = [
					'id'          => $row['id'],
					'name'        => $row['feed_name'],
					'active'      => (bool) $row['active'],
					'last_run'    => $row['last_run'],
					'last_status' => $row['last_run_status'],
					'last_count'  => $row['last_record_count'],
				];
			}
		}
		catch ( \Throwable ) {}

		\IPS\Output::i()->sendOutput(
			json_encode( $data ),
			200,
			'application/json'
		);
	}
}

class dashboard extends _dashboard {}
