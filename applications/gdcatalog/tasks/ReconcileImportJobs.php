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

use IPS\gdcatalog\Feed\Distributor;
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

		/* -------- Part 3 (Phase 12): clean abandoned Sports South seen-UPCs files. --------
		 *
		 * SportsSouthImport writes uploads/gdcatalog_ss_seen_upcs_<feed_id>.jsonl
		 * during a full queued import (Phase 10). preQueueData purges the file
		 * on the next enqueue for the same feed, and postComplete deletes it
		 * after discontinuation runs. Files can still leak when:
		 *   - the feed is deleted mid-run
		 *   - the queue tick dies between chunks and no new SS import for the
		 *     same feed is ever queued
		 *   - the feed's auth_type is changed away from sportssouth
		 *
		 * Ownership decision (per file):
		 *   feed missing            → delete (owner gone)
		 *   feed auth_type != SS    → delete (owner changed type)
		 *   feed running            → keep  (active queue owns it)
		 *   completion-flag set +
		 *     file recent           → keep  (postComplete may be about to consume)
		 *   file age > threshold    → delete + clear stale completion flag
		 *   file recent (else)      → keep  (probably active, err on the safe side)
		 *
		 * All decisions are local — no network calls. Directly hitting SS's
		 * own queue rows is fragile (IPS queue data is a serialised PHP blob),
		 * so we rely on the trio: Distributor row + Distributor::isRunning()
		 * + core_store completion flag + file mtime. Threshold matches
		 * ImportJob::STALE_THRESHOLD_SECONDS for consistency with the
		 * generic-job cleanup above. */
		$ssStagesFreed = 0;
		$ssPattern     = $uploadsDir . '/gdcatalog_ss_seen_upcs_*.jsonl';
		$staleCutoffTs = time() - ImportJob::STALE_THRESHOLD_SECONDS;
		try
		{
			$paths = glob( $ssPattern ) ?: [];
			foreach ( $paths as $path )
			{
				if ( !is_file( $path ) ) { continue; }
				$name = basename( $path );
				if ( !preg_match( '/^gdcatalog_ss_seen_upcs_(\d+)\.jsonl$/', $name, $m ) )
				{
					/* Malformed filename — ignore (do not delete something we
					 * cannot map to a feed_id). */
					continue;
				}
				$feedId = (int) $m[1];

				$feed = null;
				try { $feed = Distributor::load( $feedId ); } catch ( \Throwable ) {}

				if ( $feed === null )
				{
					/* Feed row is gone — nothing owns this file. */
					if ( @unlink( $path ) ) { $ssStagesFreed++; }
					try { unset( \IPS\Data\Store::i()->{'gdcatalog_ss_completed_naturally_' . $feedId} ); } catch ( \Throwable ) {}
					continue;
				}

				if ( (string) ( $feed->auth_type ?? '' ) !== 'sportssouth' )
				{
					/* Feed still exists but is no longer a Sports South feed —
					 * the SS queue will never re-run for it. Safe to delete. */
					if ( @unlink( $path ) ) { $ssStagesFreed++; }
					try { unset( \IPS\Data\Store::i()->{'gdcatalog_ss_completed_naturally_' . $feedId} ); } catch ( \Throwable ) {}
					continue;
				}

				/* If the Distributor is actively running, an SS queue worker
				 * is presumed to own this file — never touch. */
				try
				{
					if ( $feed->isRunning() ) { continue; }
				}
				catch ( \Throwable ) {}

				/* Completion flag present + file recent: the SS queue is
				 * either in postComplete right now, or was seconds ago.
				 * Do not race with it — keep. */
				$flagPresent = false;
				try
				{
					$flag = \IPS\Data\Store::i()->{'gdcatalog_ss_completed_naturally_' . $feedId} ?? null;
					$flagPresent = ( (int) $flag ) === 1;
				}
				catch ( \Throwable ) {}

				$mtime = (int) ( @filemtime( $path ) ?: 0 );
				$isStale = $mtime > 0 && $mtime < $staleCutoffTs;

				if ( $flagPresent && !$isStale )
				{
					continue;
				}

				if ( !$isStale )
				{
					/* Recent file, feed not running, no flag → probably a
					 * queue tick between chunks. Give it another cycle
					 * before removing. */
					continue;
				}

				/* Stale + feed idle → abandoned. Remove file and clear the
				 * lingering completion flag if any. */
				if ( @unlink( $path ) ) { $ssStagesFreed++; }
				try { unset( \IPS\Data\Store::i()->{'gdcatalog_ss_completed_naturally_' . $feedId} ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			$errors[] = 'ss accumulator cleanup: ' . $e->getMessage();
		}

		if ( !empty( $errors ) )
		{
			try { \IPS\Log::log( implode( "\n", $errors ), 'gdcatalog_reconcile' ); } catch ( \Throwable ) {}
		}

		if ( $reconciled === 0 && $stagesFreed === 0 && $ssStagesFreed === 0 && empty( $errors ) )
		{
			return null;
		}

		$parts = [];
		if ( $reconciled    > 0 ) { $parts[] = "reconciled {$reconciled} stale job(s)"; }
		if ( $stagesFreed   > 0 ) { $parts[] = "cleaned {$stagesFreed} orphan staged file(s)"; }
		if ( $ssStagesFreed > 0 ) { $parts[] = "cleaned {$ssStagesFreed} abandoned SS accumulator file(s)"; }
		if ( !empty( $errors ) )  { $parts[] = \count( $errors ) . ' error(s)'; }
		return implode( ', ', $parts );
	}
}
class ReconcileImportJobs extends _ReconcileImportJobs {}
