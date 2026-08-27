<?php
/**
 * @brief    Background Task — Generic Structured Feed Import (Phase 7 + Phase 8)
 * @since    v1.0.123
 *
 * Resumable background execution for HTTP / FTP / manual-upload catalog
 * feeds (auth_type != 'sportssouth'). Sports South continues to use its
 * own SportsSouthImport queue extension with its own DailyItemUpdate
 * LastItem pagination — this extension explicitly refuses to process
 * auth_type='sportssouth' so the two paths never overlap.
 *
 * v1.0.124 (Phase 8) — LIFECYCLE CHANGE:
 *   preQueueData() no longer performs the source fetch/parse. It only
 *   validates the feed + confirms the queued ImportJob + starts the
 *   ImportLog + seeds a minimal cursor. IPS runs preQueueData
 *   SYNCHRONOUSLY inside Queue::queue(), so leaving the fetch there
 *   (as Phase 7 did) meant the ACP Run Import click stalled on the
 *   remote endpoint. Fetch/parse/stage now happen inside the FIRST
 *   background execution of run(), gated on cursor.stage_ready=false.
 *
 * Lifecycle in detail:
 *
 *   preQueueData()   [SYNCHRONOUS in the browser request that queues]
 *     - validates feed exists + is generic (auth_type != sportssouth)
 *     - validates the pre-created ImportJob is still queued
 *     - starts a single gd_import_log row via ImportLog::startRun
 *     - seeds the job cursor with stage_ready=false (no network yet)
 *
 *   run( &$data, $offset )   [ASYNCHRONOUS in the queue tick]
 *     - atomically claims queued → running via ImportJob::claim
 *     - if cursor.stage_ready === false:
 *         - Importer::fetchAndParse (single fetch)
 *         - file_put_contents uploads/gdcatalog_job_{id}.json (stage)
 *         - Distributor::markRunning (source list reflects real state)
 *         - cursor.stage_ready = true, cursor.total_records = N
 *         - return offset 0 → next tick begins processing batches
 *     - otherwise, read next BATCH_SIZE records from the staged file
 *     - hand the batch to Importer::runChunk (existing pipeline)
 *     - v1.0.124 BATCH-FAIL SEMANTICS:
 *         if runChunk throws, the batch did NOT process successfully.
 *         DO NOT advance offset. Increment cursor.batch_retry_count
 *         and record cursor.batch_last_error. Return SAME offset so
 *         the queue re-invokes run on the next tick with the same
 *         window. After MAX_BATCH_RETRIES consecutive failures for
 *         the same batch, mark the ImportJob failed with an explicit
 *         error, mark the Distributor failed, and abandon via
 *         QueueOutOfRangeException. Records in that batch are NOT
 *         silently skipped — the failed job retains the offset so
 *         an admin's Retry Import can pick up exactly where the
 *         failure occurred.
 *     - on success: reset batch_retry_count, accumulate seen UPCs
 *       and counters, advance offset by count(batch), save cursor.
 *     - throw QueueOutOfRangeException when the staged file is
 *       exhausted → postComplete runs.
 *
 *   getProgress( $data, $offset )
 *     - text + percentage from local cursor state; zero HTTP.
 *
 *   postComplete( $data, $processed )
 *     - marks the ImportJob completed IF the job did not already
 *       transition to failed/cancelled during run.
 *     - completes the ImportLog with the accumulated counters.
 *     - marks the Distributor completed.
 *     - runs Importer::processDiscontinuationsForSeenUpcs — ONLY
 *       when the job ended as completed (not failed / cancelled).
 *     - deletes the staged file.
 *
 * Concurrency:
 *   - ImportJob::enqueueFor is atomic (INSERT ... WHERE NOT EXISTS),
 *     so two concurrent Run Import clicks cannot both create active
 *     jobs — the loser sees enqueueFor return null.
 *   - ImportJob::claim() uses an atomic conditional UPDATE
 *     (status='queued' → 'running' only if still queued) so two
 *     workers cannot both enter run() for the same job.
 *   - preQueueData refuses to enqueue if the job is not in status
 *     'queued'.
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
	protected const BATCH_SIZE        = 500;

	/**
	 * v1.0.124 (Phase 8): bounded batch retry cap. After
	 * MAX_BATCH_RETRIES consecutive throws on the SAME cursor
	 * offset, the whole job is marked failed and the offset is
	 * preserved so an admin's Retry Import resumes at the failed
	 * batch. Keeps a transient network hiccup from failing a job
	 * outright while preventing an infinite retry loop.
	 */
	protected const MAX_BATCH_RETRIES = 3;

	protected static function stagePath( int $jobId ): string
	{
		return \IPS\ROOT_PATH . '/uploads/gdcatalog_job_' . $jobId . '.json';
	}

	/**
	 * Validate + start ImportLog. Do NOT fetch anything remote here
	 * — IPS runs preQueueData synchronously in the queue-insertion
	 * request. Return null to abort.
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

		if ( (string) ( $feed->auth_type ?? '' ) === 'sportssouth' )
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

		/* Start ONE gd_import_log row for the whole job — its stats
		 * accumulate across batches inside postComplete. Batch
		 * retries do NOT create a second log; the resume path
		 * reuses the same import_log_id. */
		$importLogId = 0;
		if ( (int) ( $job->import_log_id ?? 0 ) > 0 )
		{
			/* Reusing a checkpoint (Phase 8 retry resume) — keep
			 * the existing log so one logical import maps to one
			 * ImportLog. */
			$importLogId = (int) $job->import_log_id;
		}
		else
		{
			try
			{
				$log = \IPS\gdcatalog\Log\ImportLog::startRun( (int) $feed->id, (string) $feed->distributor );
				$importLogId = (int) $log->id;
			}
			catch ( \Throwable ) {}
		}

		$cursor = $job->cursor();
		/* Explicitly do NOT stage records here — Phase 8. The very
		 * first run() batch will do it, in the background, after
		 * IPS has returned from Queue::queue. */
		if ( !isset( $cursor['stage_ready'] ) ) { $cursor['stage_ready'] = false; }
		if ( !isset( $cursor['offset'] ) )       { $cursor['offset'] = 0; }
		if ( !isset( $cursor['batch_size'] ) )   { $cursor['batch_size'] = self::BATCH_SIZE; }
		if ( !isset( $cursor['seen_upcs'] ) )    { $cursor['seen_upcs'] = []; }
		if ( !isset( $cursor['batch_retry_count'] ) ) { $cursor['batch_retry_count'] = 0; }
		if ( !isset( $cursor['batch_last_error'] ) )  { $cursor['batch_last_error']  = ''; }
		if ( $importLogId > 0 )
		{
			$job->import_log_id = $importLogId;
		}
		$job->saveCursor( $cursor );

		$data['feed_id']       = $feedId;
		$data['job_id']        = $jobId;
		$data['import_log_id'] = $importLogId;

		try { Log::log( sprintf( 'GenericImport queued feed_id=%d job_id=%d (fetch deferred to first batch)', $feedId, $jobId ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
		return $data;
	}

	/**
	 * Run one bounded batch. First-tick handles fetch/parse/stage;
	 * subsequent ticks slice the staged file.
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

		/* Phase 8: first-tick fetch / parse / stage. The very first
		 * background execution of a fresh job (or a Phase 8 retry
		 * that reset stage_ready) does the actual source I/O here,
		 * AFTER Queue::queue has already returned to the browser. */
		if ( empty( $cursor['stage_ready'] ) )
		{
			try
			{
				$records = Importer::fetchAndParse( $feed );
			}
			catch ( \Throwable $e )
			{
				try { Log::log( 'GenericImport run: fetch/parse failed feed_id=' . $feedId . ': ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
				$job->markFailed( 'fetch/parse: ' . $e->getMessage() );
				try { $feed->markFailed(); } catch ( \Throwable ) {}
				throw new QueueOutOfRangeException;
			}

			if ( empty( $records ) )
			{
				$job->markFailed( 'Source returned zero records.' );
				try { $feed->markFailed(); } catch ( \Throwable ) {}
				try { Log::log( 'GenericImport run: zero records feed_id=' . $feedId, 'gdcatalog_queue' ); } catch ( \Throwable ) {}
				throw new QueueOutOfRangeException;
			}

			$path = self::stagePath( $jobId );
			try
			{
				@file_put_contents( $path, json_encode( $records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			}
			catch ( \Throwable $e )
			{
				$job->markFailed( 'stage write failed: ' . $e->getMessage() );
				try { $feed->markFailed(); } catch ( \Throwable ) {}
				throw new QueueOutOfRangeException;
			}
			if ( !is_file( $path ) )
			{
				$job->markFailed( 'stage write failed silently: ' . $path );
				try { $feed->markFailed(); } catch ( \Throwable ) {}
				throw new QueueOutOfRangeException;
			}

			try { $feed->markRunning(); } catch ( \Throwable ) {}

			$cursor['stage_ready']       = true;
			$cursor['staged_file_path']  = $path;
			$cursor['total_records']     = count( $records );
			$cursor['batch_retry_count'] = 0;
			$cursor['batch_last_error']  = '';
			$data['total_records']       = count( $records );
			$job->saveCursor( $cursor );
			return 0;
		}

		$staged = (string) ( $cursor['staged_file_path'] ?? '' );
		if ( $staged === '' || !is_file( $staged ) )
		{
			try { Log::log( 'GenericImport run: staged file missing job_id=' . $jobId, 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			$job->markFailed( 'staged file missing on batch resume' );
			try { $feed->markFailed(); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		$blob = @file_get_contents( $staged );
		if ( $blob === false )
		{
			$job->markFailed( 'staged file unreadable' );
			try { $feed->markFailed(); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}
		$all = json_decode( $blob, true );
		if ( !is_array( $all ) )
		{
			$job->markFailed( 'staged file corrupt JSON' );
			try { $feed->markFailed(); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		$total = count( $all );
		if ( $offset >= $total )
		{
			throw new QueueOutOfRangeException;
		}

		$batch = array_slice( $all, $offset, self::BATCH_SIZE );

		/* v1.0.124 (Phase 8): batch execution now protected by a
		 * retry cap. A thrown runChunk() means the WHOLE batch did
		 * not process successfully — leaving the offset advanced
		 * would silently skip up to BATCH_SIZE records. Do not
		 * advance. Increment retry count; on cap, mark job failed
		 * with the SAME offset so an admin retry resumes at the
		 * failed batch. */
		try
		{
			$stats = Importer::runChunk( $feed, $batch );
		}
		catch ( \Throwable $e )
		{
			$cursor['batch_retry_count'] = (int) ( $cursor['batch_retry_count'] ?? 0 ) + 1;
			$cursor['batch_last_error']  = mb_substr( $e->getMessage(), 0, 500 );
			$job->saveCursor( $cursor );

			try { Log::log( sprintf( 'GenericImport run: batch throw feed_id=%d job_id=%d offset=%d retry=%d/%d error=%s', $feedId, $jobId, $offset, $cursor['batch_retry_count'], self::MAX_BATCH_RETRIES, $e->getMessage() ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}

			if ( $cursor['batch_retry_count'] >= self::MAX_BATCH_RETRIES )
			{
				$job->markFailed( sprintf( 'Batch at offset %d failed %d times: %s', $offset, self::MAX_BATCH_RETRIES, $e->getMessage() ) );
				try { $feed->markFailed(); } catch ( \Throwable ) {}
				throw new QueueOutOfRangeException;
			}

			/* Same offset — queue re-invokes with the same window. */
			return $offset;
		}

		/* Batch succeeded — clear retry state, accumulate seen UPCs
		 * + counters, advance offset. */
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
		$cursor['batch_retry_count']  = 0;
		$cursor['batch_last_error']   = '';
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

		if ( $importLogId === 0 && $job !== null && (int) ( $job->import_log_id ?? 0 ) > 0 )
		{
			$importLogId = (int) $job->import_log_id;
		}

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

		/* Complete the single per-job ImportLog IF the job ran to
		 * a natural completion. For failed/cancelled jobs, leave the
		 * log in-progress state alone (the Distributor status +
		 * gd_import_jobs.status carry the real outcome; a partial
		 * log completion would misreport the batch that stopped). */
		$jobStatus = $job !== null ? (string) $job->status : '';
		$isTerminalFailure = in_array( $jobStatus, [ ImportJob::STATUS_FAILED, ImportJob::STATUS_CANCELLED ], true );

		if ( $importLogId > 0 && !$isTerminalFailure )
		{
			try
			{
				$log = \IPS\gdcatalog\Log\ImportLog::load( $importLogId );
				$log->complete( $stats );
			}
			catch ( \Throwable ) {}
		}
		if ( $importLogId > 0 && $jobStatus === ImportJob::STATUS_FAILED )
		{
			try
			{
				$log = \IPS\gdcatalog\Log\ImportLog::load( $importLogId );
				$log->fail( (string) ( $job->last_error ?? 'job failed' ) );
			}
			catch ( \Throwable ) {}
		}

		/* Discontinuation runs ONCE, and ONLY when the job ended
		 * as completed. Failed / cancelled / partial jobs must not
		 * mark unseen products discontinued — the accumulated
		 * seenUpcs set is incomplete. */
		if ( $feed !== null && $jobStatus !== ImportJob::STATUS_FAILED
			&& $jobStatus !== ImportJob::STATUS_CANCELLED )
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

		if ( $feed !== null )
		{
			if ( $jobStatus === ImportJob::STATUS_FAILED )
			{
				try { $feed->markFailed(); } catch ( \Throwable ) {}
			}
			else if ( $jobStatus === ImportJob::STATUS_CANCELLED )
			{
				try { $feed->resetRunningStatus(); } catch ( \Throwable ) {}
			}
			else
			{
				try { $feed->markCompleted( (int) $stats['total'] ); } catch ( \Throwable ) {}
			}
		}

		if ( $job !== null && $jobStatus === ImportJob::STATUS_RUNNING )
		{
			$job->markCompleted( $importLogId > 0 ? $importLogId : null );
			$jobStatus = ImportJob::STATUS_COMPLETED;
		}

		/* Staged file lifecycle (Phase 8):
		 *   completed  → delete
		 *   cancelled  → delete (idempotent)
		 *   failed     → KEEP so a Retry-with-resume can pick up
		 *                the checkpoint. The eventual Retry Import
		 *                (fresh path) deletes it before enqueueing;
		 *                a resume path keeps it. */
		if ( $jobStatus !== ImportJob::STATUS_FAILED && $job !== null )
		{
			$job->deleteStagedFile();
		}

		try { Log::log( sprintf( 'GenericImport postComplete feed_id=%d job_id=%d status=%s records=%d created=%d updated=%d errored=%d', $feedId, $jobId, $jobStatus, $stats['total'], $stats['created'], $stats['updated'], $stats['errored'] ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
	}
}

class GenericImport extends _GenericImport {}
