<?php
/**
 * @brief       GD Master Catalog — ReconcileImportJobs Scheduled Task
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Phase 9 self-healing maintenance.
 *
 * Runs hourly. Two responsibilities:
 *
 *   1. Reconcile stale active jobs — gd_import_jobs rows whose
 *      status is queued/running but whose updated_at is older than
 *      ImportJob::STALE_THRESHOLD_SECONDS. The exact "stale"
 *      definition lives on the model (isStale + reconcile). Each
 *      stale job is marked failed with an audit note, the feed's
 *      running flag is cleared, the ImportLog is finalised, and
 *      the staged file is deleted unless the job is still
 *      Resumable() (Phase 8 retry-resume path).
 *
 *   2. Clean orphan staged files — anything in
 *      uploads/gdcatalog_job_*.json whose associated gd_import_jobs
 *      row is terminal (completed / cancelled / failed-and-not-
 *      resumable) or missing entirely. A resumable failed job's
 *      staged file is NEVER touched (Phase 8 semantics).
 *
 * NON-behaviours (asserted by Phase 9 tests):
 *   - No source-endpoint fetch (no Http\Url::external / curl_exec
 *     / ftp_connect).
 *   - No product create/update, no Importer::run/runChunk/
 *     processNormalizedRecord.
 *   - No ConflictResolver invocation, no OpenSearch queue write,
 *     no discontinuation.
 *
 * Schedule cadence: PT1H (hourly). Faster would risk killing a
 * legitimately slow batch (STALE_THRESHOLD_SECONDS is already 1h);
 * slower would leave broken state visible for too long.
 *
 * Rule #1: dual class wrapper. Rule #24-ish (task/queue reg): the
 * task key must be added to data/tasks.json for both fresh install
 * and upgrade.
 */

namespace IPS\gdcatalog\tasks;

use IPS\gdcatalog\Feed\ImportJob;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ReconcileImportJobs extends \IPS\Task
{
	/**
	 * @return string|null  Message to log, or NULL
	 */
	public function execute(): mixed
	{
		$reconciled  = 0;
		$stagesFreed = 0;
		$errors      = [];

		/* -------- Part 1: reconcile stale active jobs -------- */
		$staleCutoff = time() - ImportJob::STALE_THRESHOLD_SECONDS;
		try
		{
			$stale = \IPS\Db::i()->select(
				'*', 'gd_import_jobs',
				[
					'status IN (?,?) AND (updated_at IS NULL OR updated_at < ?)',
					ImportJob::STATUS_QUEUED, ImportJob::STATUS_RUNNING,
					$staleCutoff,
				]
			);
			foreach ( $stale as $row )
			{
				try
				{
					$job = ImportJob::constructFromData( $row );
					if ( $job->reconcile() ) { $reconciled++; }
				}
				catch ( \Throwable $e )
				{
					$errors[] = 'reconcile job=' . (int) ( $row['id'] ?? 0 ) . ': ' . $e->getMessage();
				}
			}
		}
		catch ( \Throwable $e )
		{
			$errors[] = 'stale select: ' . $e->getMessage();
		}

		/* -------- Part 2: clean orphan staged files -------- */
		$uploadsDir = \IPS\ROOT_PATH . '/uploads';
		$pattern    = $uploadsDir . '/gdcatalog_job_*.json';
		try
		{
			$paths = glob( $pattern ) ?: [];
			foreach ( $paths as $path )
			{
				if ( !is_file( $path ) ) { continue; }
				$name = basename( $path );
				if ( !preg_match( '/^gdcatalog_job_(\d+)\.json$/', $name, $m ) )
				{
					continue;
				}
				$jobId = (int) $m[1];

				/* Ownership decision:
				 *   - job missing              → delete
				 *   - job completed            → delete
				 *   - job cancelled            → delete
				 *   - job failed + resumable   → KEEP (Phase 8)
				 *   - job failed + not-resume  → delete
				 *   - job queued/running       → KEEP (worker still owns it) */
				$job = null;
				try { $job = ImportJob::load( $jobId ); } catch ( \Throwable ) {}

				if ( $job === null )
				{
					if ( @unlink( $path ) ) { $stagesFreed++; }
					continue;
				}
				$status = (string) $job->status;
				if ( in_array( $status, ImportJob::ACTIVE_STATUSES, true ) )
				{
					continue;
				}
				if ( $status === ImportJob::STATUS_FAILED && $job->isResumable() )
				{
					continue;
				}
				if ( @unlink( $path ) ) { $stagesFreed++; }
			}
		}
		catch ( \Throwable $e )
		{
			$errors[] = 'stage cleanup: ' . $e->getMessage();
		}

		if ( !empty( $errors ) )
		{
			try { \IPS\Log::log( implode( "\n", $errors ), 'gdcatalog_reconcile' ); } catch ( \Throwable ) {}
		}

		if ( $reconciled === 0 && $stagesFreed === 0 && empty( $errors ) )
		{
			return null;
		}

		$parts = [];
		if ( $reconciled  > 0 ) { $parts[] = "reconciled {$reconciled} stale job(s)"; }
		if ( $stagesFreed > 0 ) { $parts[] = "cleaned {$stagesFreed} orphan staged file(s)"; }
		if ( !empty( $errors ) ) { $parts[] = \count( $errors ) . ' error(s)'; }
		return implode( ', ', $parts );
	}
}
class ReconcileImportJobs extends _ReconcileImportJobs {}
