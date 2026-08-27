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
	 * v1.0.124 (Phase 8): atomically create a new queued job for a
	 * feed. Uses INSERT ... SELECT WHERE NOT EXISTS so exactly one
	 * caller wins when two AdminCP Run Import clicks race against
	 * each other. Returns the newly-created ImportJob on success or
	 * null when another concurrent caller already inserted an
	 * active queued/running row for this feed.
	 *
	 * The Phase 7 two-step check-then-insert had a small race window
	 * that could produce orphan duplicate queued rows. This
	 * implementation eliminates that window: the MySQL server
	 * evaluates the NOT EXISTS subquery inside the same statement as
	 * the INSERT, and \IPS\Db::i()->affected_rows returns 0 on the
	 * loser.
	 */
	public static function enqueueFor( int $feedId ): ?ImportJob
	{
		$now    = time();
		$cursor = json_encode( [
			'stage_ready'        => false,
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
			'total_records'      => 0,
			'batch_retry_count'  => 0,
			'batch_last_error'   => '',
		] );
		try
		{
			$prefix = \IPS\Db::i()->prefix;
			\IPS\Db::i()->preparedQuery(
				"INSERT INTO {$prefix}gd_import_jobs (feed_id, status, cursor_data, started_at, updated_at)
				 SELECT ?, ?, ?, ?, ?
				 WHERE NOT EXISTS (
					SELECT 1 FROM {$prefix}gd_import_jobs
					WHERE feed_id=? AND status IN (?, ?)
				 )",
				[ $feedId, self::STATUS_QUEUED, $cursor, $now, $now, $feedId, self::STATUS_QUEUED, self::STATUS_RUNNING ]
			);
			$affected = 0;
			try { $affected = (int) \IPS\Db::i()->affected_rows; } catch ( \Throwable ) {}
			if ( $affected === 0 )
			{
				return null;
			}
			$newId = 0;
			try { $newId = (int) \IPS\Db::i()->insert_id; } catch ( \Throwable ) {}
			if ( $newId > 0 )
			{
				return ImportJob::load( $newId );
			}
			/* Fallback: fetch the queued row we just inserted. Safe
			 * because our INSERT succeeded (affected=1) and we hold
			 * the connection's insert scope; another caller could
			 * insert only AFTER our WHERE NOT EXISTS check passed
			 * (i.e. after this row exists). */
			$row = \IPS\Db::i()->select(
				'*', 'gd_import_jobs',
				[ 'feed_id=? AND status=?', $feedId, self::STATUS_QUEUED ],
				'id DESC', 1
			)->first();
			return ImportJob::constructFromData( $row );
		}
		catch ( \Throwable )
		{
			return null;
		}
	}

	/**
	 * v1.0.124 (Phase 8): reopen a failed job by resetting its
	 * status back to 'queued' and clearing the batch-retry error
	 * scratch. Used by feeds::retryImport() when the job has a
	 * usable checkpoint (staged file present + offset > 0). Returns
	 * true on success; false when the job is not in a state that
	 * can be reopened (running / queued / completed / cancelled).
	 * Atomic on status='failed' so a concurrent completion cannot
	 * lose data.
	 */
	public function reopen(): bool
	{
		try
		{
			$affected = (int) \IPS\Db::i()->update(
				'gd_import_jobs',
				[ 'status' => self::STATUS_QUEUED, 'last_error' => null, 'updated_at' => time() ],
				[ 'id=? AND status=?', (int) $this->id, self::STATUS_FAILED ]
			);
			if ( $affected !== 1 ) { return false; }
			$cursor = $this->cursor();
			$cursor['batch_retry_count'] = 0;
			$cursor['batch_last_error']  = '';
			$this->status     = self::STATUS_QUEUED;
			$this->last_error = null;
			$this->cursor_data = json_encode( $cursor );
			$this->updated_at  = time();
			try { $this->save(); } catch ( \Throwable ) {}
			return true;
		}
		catch ( \Throwable )
		{
			return false;
		}
	}

	/**
	 * v1.0.124 (Phase 8): utility used by cancelImport + Retry-with-
	 * fresh-job flows. Removes the staged records file that
	 * GenericImport::preQueueData wrote for this job. Idempotent —
	 * silently succeeds if the file was already deleted or never
	 * created (fetch-stage failures never wrote it).
	 */
	public function deleteStagedFile(): void
	{
		$cursor = $this->cursor();
		$path = (string) ( $cursor['staged_file_path'] ?? '' );
		if ( $path === '' )
		{
			$path = \IPS\ROOT_PATH . '/uploads/gdcatalog_job_' . (int) $this->id . '.json';
		}
		if ( is_file( $path ) )
		{
			@unlink( $path );
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
