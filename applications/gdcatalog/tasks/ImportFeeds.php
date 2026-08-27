<?php
/**
 * @brief       GD Master Catalog — ImportFeeds Scheduled Task
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Runs every 15 minutes. Checks each active feed's schedule and
 * dispatches imports for any feeds that are due.
 *
 * v1.0.124 (Phase 8):
 *   Generic feeds (auth_type != 'sportssouth') now enqueue a
 *   GenericImport background job instead of running Importer::run()
 *   synchronously inside the cron slot. A very large feed no longer
 *   monopolises one 15-minute tick; each due feed's due-check +
 *   job-creation completes in ~ms, and the queue runner processes
 *   the actual work asynchronously.
 *
 *   Sports South scheduling remains unchanged — SS feeds still go
 *   through Importer::run() here (which internally applies the
 *   MAX_RECORDS_PER_RUN cap). A full SS import lives on the
 *   existing SportsSouthImport queue extension and is only kicked
 *   off from the ACP dashboard, not from this task; this task
 *   handles the smaller scheduled SS delta.
 *
 * Duplicate protection:
 *   Before enqueueing a generic feed, this task checks
 *   ImportJob::activeForFeed() and skips the feed if one is already
 *   queued/running. The task therefore cannot spawn a second job
 *   for a source that is still in flight.
 */

namespace IPS\gdcatalog\tasks;

/* To prevent PHP errors (extending class does not exist) revealing path */

use IPS\gdcatalog\Feed\Distributor;
use IPS\gdcatalog\Feed\Importer;
use IPS\gdcatalog\Feed\ImportJob;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ImportFeeds extends \IPS\Task
{
	/**
	 * Execute the task.
	 *
	 * @return string|null  Message to log, or NULL
	 */
	public function execute(): mixed
	{
		$feeds     = Distributor::loadActive();
		$queued    = 0;
		$ranSync   = 0;
		$errors    = [];

		foreach ( $feeds as $feed )
		{
			/* Skip feeds that aren't due or are already running via
			 * the legacy Distributor::isRunning path. */
			if ( !$feed->isDue() || $feed->isRunning() )
			{
				continue;
			}

			$authType = (string) ( $feed->auth_type ?? '' );

			/* v1.0.124 (Phase 8): generic feeds enqueue GenericImport
			 * and move on. Sports South continues to run via the
			 * legacy synchronous Importer::run() path — SS behaviour
			 * is out of Phase 8 scope. */
			if ( $authType !== 'sportssouth' )
			{
				try
				{
					if ( ImportJob::activeForFeed( (int) $feed->id ) !== null )
					{
						continue;
					}
					$job = ImportJob::enqueueFor( (int) $feed->id );
					if ( $job === null )
					{
						continue;
					}
					\IPS\Task\Queue::queue( 'gdcatalog', 'GenericImport', [
						'feed_id' => (int) $feed->id,
						'job_id'  => (int) $job->id,
					] );
					$queued++;
				}
				catch ( \Throwable $e )
				{
					$errors[] = $feed->feed_name . ': ' . $e->getMessage();
				}
				continue;
			}

			try
			{
				$log = Importer::run( $feed );
				$ranSync++;

				if ( $log->status === 'failed' )
				{
					$errors[] = $feed->feed_name . ': ' . ( $log->error_log ?? 'unknown error' );
				}
				else
				{
					try
					{
						\IPS\Task\Queue::queue( 'gdcatalog', 'BackfillAttributes', [ 'offset' => 0 ] );
					}
					catch ( \Throwable ) {}
					try
					{
						\IPS\Task\Queue::queue( 'gdcatalog', 'ResolveBrands', [ 'offset' => 0 ] );
					}
					catch ( \Throwable ) {}
				}
			}
			catch ( \Throwable $e )
			{
				$errors[] = $feed->feed_name . ': ' . $e->getMessage();
				$feed->markFailed();
			}
		}

		if ( !empty( $errors ) )
		{
			\IPS\Log::log( implode( "\n", $errors ), 'gdcatalog_import' );
		}

		$parts = [];
		if ( $queued  > 0 ) { $parts[] = "queued {$queued} generic job(s)"; }
		if ( $ranSync > 0 ) { $parts[] = "ran {$ranSync} Sports South import(s)"; }
		if ( !empty( $errors ) ) { $parts[] = \count( $errors ) . ' error(s)'; }
		return empty( $parts ) ? null : implode( ', ', $parts );
	}
}
class ImportFeeds extends _ImportFeeds {}
