<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.338 (DealerImportFeeds per-run cap + priority).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.338
 *   DealerImportFeeds task (1-min cron cycle) was processing EVERY
 *   due dealer sequentially in one run with no cap and no priority
 *   ordering. As dealer count grows, a cluster of simultaneously-
 *   due dealers could take minutes to work through in one tick,
 *   delaying freshness and risking task-timeout / overlap issues.
 *
 *   Two changes to fix this:
 *
 *   1. sources/Dealer/Dealer.php — loadDueForImport() gains an
 *      ORDER BY (last_run IS NULL) DESC, last_run ASC so due
 *      dealers come back MOST-OVERDUE FIRST (never-run dealers
 *      first, then oldest last_run). Method also accepts an
 *      optional $limit to early-terminate at the requested count.
 *      New sibling countDueForImport() returns the total-due count
 *      for backlog reporting.
 *
 *   2. tasks/DealerImportFeeds.php — reads a new gddealer_import_
 *      max_per_run setting (default 5, range 1-50), passes it to
 *      loadDueForImport() as the cap, processes the top-N most-
 *      overdue dealers, and appends "N still due, will process
 *      next run(s)" to the task log line when the backlog isn't
 *      empty. Subsequent 1-min ticks pick up the remainder.
 *
 *   IPS TASK OVERLAP INVESTIGATION FINDING (per ticket step 0):
 *
 *     IPS core system/Task/Task.php already provides overlap
 *     protection via an atomic conditional UPDATE against a
 *     `running` TINYINT flag on core_tasks:
 *
 *       UPDATE core_tasks
 *          SET next_run = time(), running = 1
 *        WHERE running = 0 AND id = ?
 *
 *     If affected_rows returns 0 the task is already locked by
 *     another process — the second invocation is skipped/deferred.
 *     A long DealerImportFeeds run that spans multiple 1-min
 *     ticks is therefore naturally serialized; subsequent ticks
 *     see running=1 and skip. Duplicate-dealer race is
 *     IMPOSSIBLE by construction. No custom lock table needed.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Seeds gddealer_import_max_per_run = 5 (default) via
 *      core_sys_conf_settings replace().
 *   2. Re-seeds the two new lang keys across every lang_id
 *      (Rule #43/#44 — 6-col core_sys_lang_words, per-row
 *      try/catch).
 *   3. Cache / module / opcache purge so the updated task and
 *      Dealer PHP + new ACP settings row all load next request.
 *
 * NO schema change (setting sits in core_sys_conf_settings; no
 * new tables — the IPS core_tasks.running lock is already
 * sufficient for overlap protection per the investigation above).
 * Rule #79: upg_10337 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10338;

use function defined;
use function function_exists;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* 1. Seed the setting default (idempotent — replace uses
		     conf_key as primary key). */
		try
		{
			\IPS\Db::i()->replace( 'core_sys_conf_settings', [
				'conf_key'     => 'gddealer_import_max_per_run',
				'conf_value'   => '5',
				'conf_default' => '5',
				'conf_app'     => 'gddealer',
				'conf_report'  => 'full',
			] );
		}
		catch ( \Throwable $e )
		{
			try
			{
				\IPS\Db::i()->replace( 'core_sys_conf_settings', [
					'conf_key'   => 'gddealer_import_max_per_run',
					'conf_value' => '5',
				] );
			}
			catch ( \Throwable $e2 ) { try { \IPS\Log::log( 'upg_10338 setting seed: ' . $e2->getMessage(), 'gddealer_upg_10338' ); } catch ( \Throwable ) {} }
		}

		/* 2. Re-seed new lang keys across every lang_id. */
		$strings = [
			'gddealer_import_max_per_run'      => 'Max feed imports per task run',
			'gddealer_import_max_per_run_desc' => 'The DealerImportFeeds task processes at most this many due dealers per invocation (most-overdue first). Subsequent 1-min cron ticks pick up the rest. Range 1&ndash;50. Default 5 &mdash; raise if the "still due" backlog in the task log is consistently &gt; 0 across many runs.',
		];
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $strings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gddealer',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10338 lang ' . $key . ': ' . $e->getMessage(), 'gddealer_upg_10338' ); } catch ( \Throwable ) {} }
				}
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10338 lang loop: ' . $e->getMessage(), 'gddealer_upg_10338' ); } catch ( \Throwable ) {} }

		/* 3. Cache purge. */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
