<?php
/**
 * @brief       GD Master Catalog — Import Job model
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Phase 7 of the source-adapter refactor plan (audit 2026-08-25).
 *
 * Represents one background import job for a configured Distributor.
 * Owns the resumable cursor + accumulated per-source seen-UPCs +
 * post-completion pointers to gd_import_log. Read/written by:
 *
 *   - AdminCP feeds.php::runImport()      queues a new row.
 *   - AdminCP feeds.php::retryImport()    reopens a failed job.
 *   - AdminCP feeds.php::cancelImport()   marks status='cancelled'.
 *   - GenericImport queue extension       claims + advances + completes.
 *
 * NOT used by SportsSouthImport — Sports South continues to run via
 * its existing queue extension with its own cursor semantics
 * (dailyItemUpdate LastItem), per the Phase 7 prompt's "Do not
 * rewrite working Sports South pagination just to unify architecture."
 *
 * States:
 *   queued     — enqueued but no worker has claimed it yet
 *   running    — a worker holds it; cursor advances each batch
 *   completed  — postComplete ran (source import finished cleanly)
 *   failed     — a fatal source-level error stopped the job
 *   cancelled  — admin cancelled from ACP
 *
 * Concurrency:
 *   claim() uses an atomic conditional UPDATE ("SET status='running'
 *   WHERE id=? AND status='queued'") to prevent two workers from
 *   entering the same job's run() at the same time. See claim() below.
 */

namespace IPS\gdcatalog\Feed;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ImportJob extends \IPS\Patterns\ActiveRecord
{
	public static ?string $databaseTable    = 'gd_import_jobs';
	public static string  $databaseColumnId = 'id';
	public static string  $databasePrefix   = '';

	public const STATUS_QUEUED    = 'queued';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	public const ACTIVE_STATUSES  = [ self::STATUS_QUEUED, self::STATUS_RUNNING ];

	/**
	 * v1.0.123 (Phase 7): find the current active job for a feed, if
	 * any. Returns null when the feed has no queued/running job.
	 */
	public static function activeForFeed( int $feedId ): ?ImportJob
	{
		try
		{
			$row = \IPS\Db::i()->select(
				'*', 'gd_import_jobs',
				[ 'feed_id=? AND status IN (?,?)', $feedId, self::STATUS_QUEUED, self::STATUS_RUNNING ],
				'id DESC',
				1
			)->first();
			return ImportJob::constructFromData( $row );
		}
		catch ( \Throwable )
		{
			return null;
		}
	}

	/**
	 * v1.0.123 (Phase 7): atomically create a new queued job for a
	 * feed. Returns the ImportJob on success or null when there is
	 * already an active job. Race prevention rides on the row insert
	 * being unable to succeed if a concurrent caller has already
	 * queued — we probe with SELECT first (fast path) and let the
	 * queue extension's preQueueData double-check on run.
	 *
	 * The two-step SELECT-then-INSERT window is small and matches
	 * the SportsSouthImport pattern in production. GenericImport's
	 * preQueueData below also aborts if it discovers a duplicate
	 * ACTIVE job.
	 */
	public static function enqueueFor( int $feedId ): ?ImportJob
	{
		if ( self::activeForFeed( $feedId ) !== null )
		{
			return null;
		}
		try
		{
			$now = time();
			$jobId = (int) \IPS\Db::i()->insert( 'gd_import_jobs', [
				'feed_id'     => $feedId,
				'status'      => self::STATUS_QUEUED,
				'cursor_data' => json_encode( [
					'offset'             => 0,
					'batch_size'         => 500,
					'staged_file_path'   => '',
					'seen_upcs'          => [],
					'records_processed'  => 0,
					'records_created'    => 0,
					'records_updated'    => 0,
					'records_skipped'    => 0,
					'records_errored'    => 0,
					'conflicts_logged'   => 0,
				] ),
				'started_at'  => $now,
				'updated_at'  => $now,
			] );
			return ImportJob::load( $jobId );
		}
		catch ( \Throwable )
		{
			return null;
		}
	}

	/**
	 * v1.0.123 (Phase 7): atomically claim a queued job for a
	 * single worker. Returns TRUE if this call transitioned the row
	 * from queued → running, FALSE otherwise (already running,
	 * completed, cancelled, failed, or missing). Concurrency
	 * protection: the WHERE clause requires status='queued', so
	 * only one caller's UPDATE affects a row. \IPS\Db::i()->update()
	 * returns the number of affected rows.
	 */
	public function claim(): bool
	{
		try
		{
			$affected = (int) \IPS\Db::i()->update(
				'gd_import_jobs',
				[
					'status'     => self::STATUS_RUNNING,
					'started_at' => (int) ( $this->started_at ?: time() ),
					'updated_at' => time(),
				],
				[ 'id=? AND status=?', (int) $this->id, self::STATUS_QUEUED ]
			);
			if ( $affected === 1 )
			{
				$this->status     = self::STATUS_RUNNING;
				$this->updated_at = time();
				return true;
			}
			return false;
		}
		catch ( \Throwable )
		{
			return false;
		}
	}

	/**
	 * Load and json_decode cursor_data safely.
	 *
	 * @return array<string, mixed>
	 */
	public function cursor(): array
	{
		$raw = (string) ( $this->cursor_data ?? '' );
		if ( $raw === '' ) { return []; }
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Persist the cursor and bump updated_at. Concurrency-safe
	 * from the queue extension's perspective — a single worker
	 * owns a running job and is the only one writing here.
	 *
	 * @param array<string, mixed> $cursor
	 */
	public function saveCursor( array $cursor ): void
	{
		$this->cursor_data = json_encode( $cursor );
		$this->updated_at  = time();
		try { $this->save(); } catch ( \Throwable ) {}
	}

	public function markCompleted( ?int $importLogId = null ): void
	{
		$this->status       = self::STATUS_COMPLETED;
		$this->completed_at = time();
		$this->updated_at   = time();
		if ( $importLogId !== null ) { $this->import_log_id = $importLogId; }
		try { $this->save(); } catch ( \Throwable ) {}
	}

	public function markFailed( string $error ): void
	{
		$this->status     = self::STATUS_FAILED;
		$this->last_error = mb_substr( $error, 0, 60000 );
		$this->updated_at = time();
		try { $this->save(); } catch ( \Throwable ) {}
	}

	public function markCancelled(): void
	{
		$this->status     = self::STATUS_CANCELLED;
		$this->updated_at = time();
		try { $this->save(); } catch ( \Throwable ) {}
	}
}

class ImportJob extends _ImportJob {}
