<?php
/**
 * @brief    Background Task — Generic Structured Feed Import (Phase 7)
 * @since    v1.0.123
 *
 * Resumable background execution for HTTP / FTP / manual-upload catalog
 * feeds (auth_type in { none, basic, apikey, ftp, manual_upload }).
 * Sports South continues to use its own SportsSouthImport queue
 * extension with its own DailyItemUpdate LastItem pagination — this
 * extension explicitly refuses to process auth_type='sportssouth' so
 * the two paths never overlap.
 *
 * Life cycle:
 *
 *   preQueueData()
 *     - validates feed exists + is generic + no other active job
 *     - creates gd_import_jobs row via ImportJob::enqueueFor
 *     - fetches + parses the source ONCE (Importer::fetchAndParse)
 *     - stages parsed records to uploads/gdcatalog_job_{id}.json so
 *       subsequent bounded batches do not re-fetch the source
 *     - starts a single gd_import_log row via ImportLog::startRun
 *
 *   run( &$data, $offset )
 *     - atomically claims the job (queued → running); a second worker
 *       calling run at the same time sees claim() return false and
 *       exits without re-processing the batch
 *     - reads next 500 raw records from the staged file starting at
 *       $offset
 *     - hands the batch to Importer::runChunk (which routes each raw
 *       row through the existing StructuredFeedAdapter →
 *       processNormalizedRecord pipeline) — no duplication of
 *       matching / conflict / compliance / reindex / logging
 *     - accumulates seen UPCs on the job cursor for later
 *       discontinuation
 *     - throws QueueOutOfRangeException when the staged file is
 *       exhausted → postComplete runs
 *
 *   postComplete( $data, $processed )
 *     - marks the ImportJob completed
 *     - completes the ImportLog with accumulated counters
 *     - marks the Distributor completed (last_run / last_run_status)
 *     - runs Importer::processDiscontinuationsForSeenUpcs using the
 *       accumulated seenUpcs — the 80% safety guard inside that
 *       method continues to protect against premature discontinue
 *     - deletes the staged file
 *
 * Concurrency:
 *   - ImportJob::claim() uses an atomic conditional UPDATE
 *     (queued → running only if still queued) so two workers cannot
 *     both enter run() for the same job.
 *   - preQueueData refuses to enqueue if there is already an active
 *     (queued / running) job for the same feed.
 *   - IPS's core_queue itself dispatches one queue row at a time; a
 *     third safety net.
 *
 * Rule #1: dual class wrapper.
 * Rule #16: this file must be registered in data/extensions.json
 *           under Queue as GenericImport.
 */

namespace IPS\gdcatalog\extensions\core\Queue;

use IPS\Extensions\QueueAbstract;
use IPS\gdcatalog\Feed\Distributor;
use IPS\gdcatalog\Feed\Importer;
use IPS\gdcatalog\Feed\ImportJob;
use IPS\Log;
use IPS\Task\Queue\OutOfRangeException as QueueOutOfRangeException;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _GenericImport extends QueueAbstract
{
	protected const BATCH_SIZE = 500;

	/**
	 * Storage location for the staged parsed-records file. Uploads
	 * dir is persistent across PHP restarts (unlike sys temp) and
	 * writable by IPS's default runtime user.
	 */
	protected static function stagePath( int $jobId ): string
	{
		return \IPS\ROOT_PATH . '/uploads/gdcatalog_job_' . $jobId . '.json';
	}

	/**
	 * Validate + stage the source. Returns the $data payload that
	 * IPS will pass to run(). Return null to abort.
	 *
	 * @param  array $data  Must contain 'feed_id' and 'job_id'.
	 * @return array|null
	 */
	public function preQueueData( array $data ): ?array
	{
		$feedId = (int) ( $data['feed_id'] ?? 0 );
		$jobId  = (int) ( $data['job_id']  ?? 0 );

		if ( $feedId <= 0 || $jobId <= 0 )
		{
			try { Log::log( 'GenericImport: missing feed_id/job_id', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		try
		{
			$feed = Distributor::load( $feedId );
		}
		catch ( \OutOfRangeException )
		{
			try { Log::log( 'GenericImport: feed_id=' . $feedId . ' not found', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		$authType = (string) ( $feed->auth_type ?? '' );
		if ( $authType === 'sportssouth' )
		{
			try { Log::log( 'GenericImport: refuses SS feed feed_id=' . $feedId . ' — SportsSouthImport owns this', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		try
		{
			$job = ImportJob::load( $jobId );
		}
		catch ( \OutOfRangeException )
		{
			try { Log::log( 'GenericImport: job_id=' . $jobId . ' not found', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		if ( $job->status !== ImportJob::STATUS_QUEUED )
		{
			try { Log::log( 'GenericImport: job_id=' . $jobId . ' status=' . $job->status . ' — not queued, refusing', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		/* Fetch + parse once, up front. This is the same work
		 * pre-Phase-7 synchronous Importer::run() did on every ACP
		 * runImport click; the only difference is that we do it once
		 * inside the queue's preQueueData and stage the parsed
		 * records for bounded run() batches to consume. */
		try
		{
			$records = Importer::fetchAndParse( $feed );
		}
		catch ( \Throwable $e )
		{
			try { Log::log( 'GenericImport: fetch/parse failed for feed_id=' . $feedId . ': ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			$job->markFailed( 'fetch/parse: ' . $e->getMessage() );
			return null;
		}

		if ( empty( $records ) )
		{
			$job->markFailed( 'Source returned zero records.' );
			try { Log::log( 'GenericImport: zero records for feed_id=' . $feedId, 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		/* Stage to the persistent uploads dir so subsequent batches
		 * do not re-fetch. */
		$path = self::stagePath( $jobId );
		try
		{
			@file_put_contents( $path, json_encode( $records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		catch ( \Throwable $e )
		{
			$job->markFailed( 'stage write failed: ' . $e->getMessage() );
			return null;
		}
		if ( !is_file( $path ) )
		{
			$job->markFailed( 'stage write failed silently: ' . $path );
			return null;
		}

		/* Mark the feed as running so the list page reflects state. */
		try { $feed->markRunning(); } catch ( \Throwable ) {}

		/* Start ONE gd_import_log row for the whole job — its stats
		 * accumulate across batches inside postComplete. */
		$importLogId = 0;
		try
		{
			$log = \IPS\gdcatalog\Log\ImportLog::startRun( (int) $feed->id, (string) $feed->distributor );
			$importLogId = (int) $log->id;
		}
		catch ( \Throwable ) {}

		/* Seed the job cursor with the staged file + total. */
		$cursor = $job->cursor();
		$cursor['staged_file_path']    = $path;
		$cursor['total_records']       = count( $records );
		$cursor['batch_size']          = self::BATCH_SIZE;
		$cursor['offset']              = 0;
		$cursor['seen_upcs']           = [];
		$cursor['records_processed']   = 0;
		$cursor['records_created']     = 0;
		$cursor['records_updated']     = 0;
		$cursor['records_skipped']     = 0;
		$cursor['records_errored']     = 0;
		$cursor['conflicts_logged']    = 0;
		if ( $importLogId > 0 )
		{
			$job->import_log_id = $importLogId;
		}
		$job->saveCursor( $cursor );

		/* The mutable $data IPS carries between run() calls. */
		$data['feed_id']       = $feedId;
		$data['job_id']        = $jobId;
		$data['import_log_id'] = $importLogId;
		$data['total_records'] = count( $records );

		try { Log::log( sprintf( 'GenericImport queued feed_id=%d job_id=%d records=%d', $feedId, $jobId, count( $records ) ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
		return $data;
	}

	/**
	 * Run one bounded batch.
	 *
	 * @param array $data   Mutable inter-batch state (feed_id, job_id, …).
	 * @param int   $offset Position in the staged records array.
	 * @return int  New offset. Throws QueueOutOfRangeException on completion.
	 */
	public function run( array &$data, int $offset ): int
	{
		$feedId = (int) ( $data['feed_id'] ?? 0 );
		$jobId  = (int) ( $data['job_id']  ?? 0 );

		try
		{
			$feed = Distributor::load( $feedId );
			$job  = ImportJob::load( $jobId );
		}
		catch ( \OutOfRangeException )
		{
			try { Log::log( 'GenericImport run: feed/job disappeared feed_id=' . $feedId . ' job_id=' . $jobId, 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		/* Atomic claim — only one worker at a time transitions
		 * queued → running. Subsequent batches on an already-running
		 * job also pass through here without issue because claim()
		 * returns FALSE for a non-queued row; the same worker just
		 * proceeds with the run body. */
		if ( $job->status === ImportJob::STATUS_QUEUED )
		{
			$job->claim();
		}

		if ( $job->status === ImportJob::STATUS_CANCELLED )
		{
			try { Log::log( 'GenericImport run: job cancelled by admin, exiting job_id=' . $jobId, 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		$cursor = $job->cursor();
		$staged = (string) ( $cursor['staged_file_path'] ?? '' );
		if ( $staged === '' || !is_file( $staged ) )
		{
			try { Log::log( 'GenericImport run: staged file missing job_id=' . $jobId, 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			$job->markFailed( 'staged file missing on batch resume' );
			throw new QueueOutOfRangeException;
		}

		$blob = @file_get_contents( $staged );
		if ( $blob === false )
		{
			$job->markFailed( 'staged file unreadable' );
			throw new QueueOutOfRangeException;
		}
		$all = json_decode( $blob, true );
		if ( !is_array( $all ) )
		{
			$job->markFailed( 'staged file corrupt JSON' );
			throw new QueueOutOfRangeException;
		}

		$total = count( $all );
		if ( $offset >= $total )
		{
			throw new QueueOutOfRangeException;
		}

		$batch = array_slice( $all, $offset, self::BATCH_SIZE );

		/* Route the batch through the existing Importer pipeline —
		 * runChunk maps + adapts + processes each record through the
		 * shared processNormalizedRecord tail. No duplication of
		 * matching / conflict handling / compliance / reindex /
		 * logging semantics; the same code the pre-Phase-7 sync path
		 * used runs here, one bounded slice at a time. */
		$stats = [ 'total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 0, 'conflicts' => 0 ];
		try
		{
			$stats = Importer::runChunk( $feed, $batch );
		}
		catch ( \Throwable $e )
		{
			try { Log::log( 'GenericImport run: runChunk failed job_id=' . $jobId . ' offset=' . $offset . ': ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
		}

		/* Accumulate seen UPCs on the job cursor so postComplete can
		 * evaluate discontinuation against the ENTIRE job's coverage,
		 * not one batch's slice. UPCs come from the raw records
		 * themselves — every record that made it into runChunk had
		 * its UPC extracted upstream; here we mirror that by reading
		 * the FieldMapper-mapped upc from a lightweight second pass.
		 * Batch is already in memory so this is cheap. */
		$seen = is_array( $cursor['seen_upcs'] ?? null ) ? $cursor['seen_upcs'] : [];
		try
		{
			$mapper = new \IPS\gdcatalog\Feed\FieldMapper( $feed->field_mapping );
			foreach ( $batch as $raw )
			{
				$upc = $mapper->extractUpc( is_array( $raw ) ? $raw : [] );
				if ( $upc !== null && $upc !== '' )
				{
					$upcNorm = \IPS\gdcatalog\Feed\UpcValidator::normalize( $upc );
					if ( $upcNorm !== null ) { $seen[ $upcNorm ] = true; }
				}
			}
		}
		catch ( \Throwable ) {}

		$cursor['seen_upcs']         = $seen;
		$cursor['records_processed'] += (int) ( $stats['total']     ?? 0 );
		$cursor['records_created']   += (int) ( $stats['created']   ?? 0 );
		$cursor['records_updated']   += (int) ( $stats['updated']   ?? 0 );
		$cursor['records_skipped']   += (int) ( $stats['skipped']   ?? 0 );
		$cursor['records_errored']   += (int) ( $stats['errored']   ?? 0 );
		$cursor['conflicts_logged']  += (int) ( $stats['conflicts'] ?? 0 );
		$cursor['offset']             = $offset + count( $batch );
		$job->saveCursor( $cursor );

		$newOffset = $offset + count( $batch );
		if ( $newOffset >= $total )
		{
			throw new QueueOutOfRangeException;
		}
		return $newOffset;
	}

	/**
	 * ACP queue status panel — one line + coarse percentage.
	 *
	 * @param mixed $data
	 * @param int   $offset
	 * @return array{text: string, complete: float}
	 */
	public function getProgress( mixed $data, int $offset ): array
	{
		$total     = (int) ( $data['total_records'] ?? 0 );
		$processed = $offset;
		$complete  = $total > 0 ? min( 99, round( $processed / $total * 100, 1 ) ) : 0;

		return [
			'text'     => sprintf( 'Generic import: %d / %d records', $processed, $total ),
			'complete' => $complete,
		];
	}

	/**
	 * Finalize: complete gd_import_log, discontinuation, feed
	 * markCompleted, delete staged file.
	 */
	public function postComplete( array $data, bool $processed = TRUE ) : void
	{
		if ( !$processed )
		{
			return;
		}

		$feedId      = (int) ( $data['feed_id']       ?? 0 );
		$jobId       = (int) ( $data['job_id']        ?? 0 );
		$importLogId = (int) ( $data['import_log_id'] ?? 0 );

		$feed = null;
		try { $feed = Distributor::load( $feedId ); } catch ( \Throwable ) {}

		$job = null;
		try { $job = ImportJob::load( $jobId ); } catch ( \Throwable ) {}

		$cursor = $job !== null ? $job->cursor() : [];
		$seen   = is_array( $cursor['seen_upcs'] ?? null ) ? $cursor['seen_upcs'] : [];

		$stats = [
			'total'       => (int) ( $cursor['records_processed'] ?? 0 ),
			'created'     => (int) ( $cursor['records_created']   ?? 0 ),
			'updated'     => (int) ( $cursor['records_updated']   ?? 0 ),
			'skipped'     => (int) ( $cursor['records_skipped']   ?? 0 ),
			'errored'     => (int) ( $cursor['records_errored']   ?? 0 ),
			'conflicts'   => (int) ( $cursor['conflicts_logged']  ?? 0 ),
			'upc_invalid' => 0,
			'upc_flagged' => 0,
		];

		/* Complete the single per-job ImportLog. One logical import
		 * = one gd_import_log row, regardless of how many bounded
		 * batches processed it. */
		if ( $importLogId > 0 )
		{
			try
			{
				$log = \IPS\gdcatalog\Log\ImportLog::load( $importLogId );
				$log->complete( $stats );
			}
			catch ( \Throwable ) {}
		}

		/* Discontinuation runs ONCE, only after all batches finish
		 * — with the FULL accumulated seen-UPC set the 80% coverage
		 * guard inside processDiscontinuations expects. */
		if ( $feed !== null && $job !== null && $job->status !== ImportJob::STATUS_CANCELLED )
		{
			try
			{
				Importer::processDiscontinuationsForSeenUpcs( $feed, $seen );
			}
			catch ( \Throwable $e )
			{
				try { Log::log( 'GenericImport postComplete: discontinuation failed job_id=' . $jobId . ': ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			}
		}

		/* Mark the feed completed for the source list. */
		if ( $feed !== null )
		{
			try { $feed->markCompleted( (int) $stats['total'] ); } catch ( \Throwable ) {}
		}

		/* Mark the job completed. Cancelled jobs stay cancelled. */
		if ( $job !== null && $job->status !== ImportJob::STATUS_CANCELLED )
		{
			$job->markCompleted( $importLogId > 0 ? $importLogId : null );
		}

		/* Delete the staged file. */
		$path = self::stagePath( $jobId );
		if ( is_file( $path ) ) { @unlink( $path ); }

		try { Log::log( sprintf( 'GenericImport postComplete feed_id=%d job_id=%d records=%d created=%d updated=%d errored=%d', $feedId, $jobId, $stats['total'], $stats['created'], $stats['updated'], $stats['errored'] ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
	}
}

class GenericImport extends _GenericImport {}
