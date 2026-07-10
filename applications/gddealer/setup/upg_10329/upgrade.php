<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.329 (dashboard SQL literal fix).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHY v1.0.329 EXISTS:
 *   The dealer analytics dashboard's "Unique clicks" tile
 *   always showed 0 — even for dealers with hundreds of
 *   real clicks logged in gd_click_log. Root cause verified
 *   from core_log rows in category `gddealer_analytics`:
 *
 *     [error] Unknown column '|' in 'SELECT'
 *
 *   modules/front/dealers/dashboard.php ~line 1596 built the
 *   unique-visitor key as:
 *
 *     COUNT(DISTINCT CONCAT(upc, "|", CASE ... END))
 *
 *   The DB is running in ANSI_QUOTES mode. In that mode
 *   double-quoted tokens inside SQL are IDENTIFIERS (column
 *   names) — so `"|"`, `"m"`, `"i"`, `"r"`, and the empty-
 *   string test `"" ` all read as columns that don't exist.
 *   The query threw immediately, the surrounding try/catch
 *   caught it and logged to `gddealer_analytics`, and the
 *   $uniqueClicks counter stayed at its initial 0.
 *
 *   FIX (dashboard.php only):
 *     * Every SQL string literal in the unique-clicks SELECT
 *       is now single-quoted. Outer PHP delimiter switched
 *       from single- to double-quote so the inner SQL
 *       single-quotes read cleanly.
 *     * The catch/log is preserved so if any future SQL
 *       breaks it surfaces in the same log category rather
 *       than hiding silently.
 *   All other click_log queries in dashboard.php already
 *   use `?` placeholders — no double-quoted SQL literals
 *   remained anywhere else in the file (grep-verified across
 *   the whole gddealer tree).
 *
 * DO NOT invoke the canonical-templates re-seeder here — a
 * prior version's identical call wiped the dealer dashboard.
 * No templates need re-seeding in v1.0.329.
 *
 * No schema. No lang. dashboard.php ships updated.
 * step1() just busts caches so the new PHP dispatches.
 */

namespace IPS\gddealer\setup\upg_10329;

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
		/* Cache purge — module dispatcher must re-resolve so the
		   new dashboard.php PHP file is loaded on the next hit. */
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
