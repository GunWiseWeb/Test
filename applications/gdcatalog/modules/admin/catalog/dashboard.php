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

		try { $totalProducts  = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog' )->first(); } catch ( \Exception ) {}
		try { $activeProducts = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'record_status=?', 'active' ] )->first(); } catch ( \Exception ) {}
		try { $reviewProducts = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'record_status=?', 'admin_review' ] )->first(); } catch ( \Exception ) {}

		/* Per-category counts */
		$categoryCounts = [];
		try
		{
			foreach ( Category::roots() as $cat )
			{
				$count = 0;
				try { $count = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'category_id=?', $cat->id ] )->first(); } catch ( \Exception ) {}
				$categoryCounts[] = [ 'name' => $cat->name, 'count' => $count ];
			}
		}
		catch ( \Exception ) {}

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
				catch ( \Exception ) {}

				$productCount = 0;
				try
				{
					$productCount = (int) \IPS\Db::i()->select(
						'COUNT(*)', 'gd_catalog',
						[ 'FIND_IN_SET(?, distributor_sources)', $feed->distributor ]
					)->first();
				}
				catch ( \Exception ) {}

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
				];
			}
		}
		catch ( \Exception ) {}

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

		try { $pendingConflicts  = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_feed_conflicts', [ 'status=?', 'pending' ] )->first(); } catch ( \Exception ) {}
		try { $pendingCompliance = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_flags', [ 'status=?', 'pending_review' ] )->first(); } catch ( \Exception ) {}
		try { $lockedFields      = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_field_locks' )->first(); } catch ( \Exception ) {}
		try { $reindexQueue      = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_reindex_queue' )->first(); } catch ( \Exception ) {}

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

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_dash_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->dashboard(
			$totalProducts, $activeProducts, $reviewProducts,
			$categoryCounts, $distributorStats,
			$osExists, $osStats,
			$pendingConflicts, $pendingCompliance, $lockedFields, $reindexQueue,
			$taskUrls
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
}

class dashboard extends _dashboard {}
