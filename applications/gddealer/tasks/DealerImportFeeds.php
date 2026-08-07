<?php
/**
 * @brief       GD Dealer Manager — Scheduled Feed Import Task
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 *
 * Runs every 15 minutes (tasks.json). Each invocation loads all dealers
 * whose next-import window has elapsed given their subscription tier
 * schedule, then runs Importer::run() for each one.
 */

namespace IPS\gddealer\tasks;

use IPS\gddealer\Dealer\Dealer;
use IPS\gddealer\Feed\Importer;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _DealerImportFeeds extends \IPS\Task
{
	/** Fallback per-run cap when the ACP setting is empty/unset. */
	const DEFAULT_MAX_PER_RUN = 5;

	/**
	 * v1.0.338 — per-run cap + due-order prioritization.
	 *
	 * Previously: processed ALL due dealers sequentially every tick,
	 * unbounded. As dealer count grows, a cluster of simultaneously-
	 * due dealers could take minutes to work through in one run.
	 *
	 * Now: reads gddealer_import_max_per_run (default 5), and
	 * Dealer::loadDueForImport() returns the list ordered by
	 * MOST OVERDUE FIRST (dealers never run come first; then oldest
	 * last_run wins). Subsequent 1-min ticks pick up the remainder.
	 * Overlap safety is handled by IPS core Task.php's own
	 * atomic-conditional-UPDATE lock on core_tasks.running — a
	 * long run that spans multiple ticks naturally serializes;
	 * subsequent ticks see running=1 and skip. No duplicate-dealer
	 * races possible.
	 *
	 * @return string|null  Log line, or null when no dealers ran
	 */
	public function execute(): mixed
	{
		$capSetting = 0;
		try { $capSetting = (int) ( \IPS\Settings::i()->gddealer_import_max_per_run ?? 0 ); } catch ( \Throwable ) {}
		$cap = $capSetting > 0 ? $capSetting : self::DEFAULT_MAX_PER_RUN;

		/* Fetch up to $cap most-overdue due dealers. */
		$due = Dealer::loadDueForImport( $cap );
		$batchCount = count( $due );
		if ( $batchCount === 0 )
		{
			return null;
		}

		$ran = 0;
		$ok  = 0;
		foreach ( $due as $dealer )
		{
			$log = Importer::run( $dealer );
			$ran++;
			if ( $log->status === 'completed' )
			{
				$ok++;
			}
		}

		/* Backlog visibility — how many dealers were left due but
		   not processed this run. If we filled the cap ($ran ===
		   $cap), there may be more; recount to know for sure.
		   If we processed less than the cap, the backlog is 0
		   (loadDueForImport ran out of due dealers before the cap). */
		$stillDue = 0;
		if ( $ran >= $cap )
		{
			$stillDue = max( 0, Dealer::countDueForImport() );
			/* countDueForImport re-includes the ones we JUST ran (their
			   last_run has been bumped inside Importer::run), so it's
			   already the remaining backlog — no subtraction needed. */
		}

		$suffix = $stillDue > 0
			? "; {$stillDue} still due, will process next run(s)"
			: '';

		return "DealerImportFeeds ran {$ran} dealer(s); {$ok} completed{$suffix}";
	}

	/**
	 * Background task timeout override — feeds can be large.
	 */
	public function cleanup()
	{
		/* Nothing to clean — listings and logs are managed by Importer::run */
	}
}

class DealerImportFeeds extends _DealerImportFeeds {}
