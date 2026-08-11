<?php
/**
 * @brief  GD Deals — upgrade 1.0.58 (browse perPage 24 → 25 for 5-col grid).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.58
 *   modules/front/deals/browse.php $perPage went 24 → 25. The
 *   /deals/ browse grid is CSS `grid-template-columns:
 *   repeat(auto-fill, minmax(270px, 1fr))` which lays out as 5
 *   columns at typical desktop widths. 24 ÷ 5 = 4 remainder 4, so
 *   every full page's last row rendered as 4 items instead of 5 —
 *   visually incomplete. 25 ÷ 5 = 5 exactly, so every full page
 *   now renders as a clean 5×5 grid.
 *
 *   The very last page of all deals may still legitimately show a
 *   partial final row when the total count doesn't divide evenly
 *   (there simply aren't 25 remaining deals to fill it) — that's
 *   expected and unaddressable without placeholder padding. This
 *   fix only guarantees every FULL page is a complete 5×5.
 *
 *   Pagination math (`$pages = ceil($total/$perPage)`, `$offset`)
 *   already references $perPage as a variable, so it adapts to
 *   the new value with no other code changes needed. Mobile
 *   breakpoint (`@media (max-width:600px)`) collapses the grid to
 *   a single column, so this change has no mobile impact — no
 *   short-row concept applies at 1-column.
 *
 * WHAT THIS UPGRADE DOES
 *   Cache / datastore clear so the updated browse.php loads on the
 *   next request. Matches the pattern of every previous gddeals
 *   controller-touching upgrade.
 *
 * NO schema change. NO template touched. NO lang change.
 * Rule #79: upg_10057 removed, exactly one upg dir per app.
 */

namespace IPS\gddeals\setup\upg_10058;

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
