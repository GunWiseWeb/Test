<?php
/**
 * @brief  GD Deals — upgrade 1.0.59 (mobile-responsive .ipsPagination scoped to /deals/).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.59
 *   dev/css/front/deals.css gains a scoped mobile responsive rule
 *   for the /deals/ browse pagination. IPS's native .ipsPagination
 *   widget (First / Prev / up to ~11 numbered page links / Next /
 *   Last / page-jump dropdown) has NO baseline responsive CSS in
 *   the core front stylesheets — on narrow phones the ~13-element
 *   list overflows off-viewport with no wrap or scroll affordance,
 *   making pagination visually broken/unusable at ~375-414px.
 *
 *   Fix, added inside a fresh @media(max-width:600px) block at the
 *   end of deals.css and scoped strictly to `.gd-deals-wrap
 *   .ipsPagination` so nothing else on the site is affected:
 *
 *     - display:flex + flex-wrap:wrap + justify-content:center so
 *       the row wraps cleanly instead of overflowing.
 *     - gap:6px, margin-top:24px, list-style:none, padding:0 for a
 *       tidy centered row.
 *     - .ipsPagination--numerous ONLY: hide every
 *       .ipsPagination__page that isn't .ipsPagination__active.
 *       That leaves First/Prev, the current page number, Next/Last,
 *       and the page-jump dropdown visible — the dropdown is
 *       designed for exactly this "many pages" scenario, so any
 *       page remains one tap away.
 *
 *   No JS. No template touched. No IPS core file modified. The pre-
 *   existing .gd-deals-wrap .ipsPagination{margin-top:32px} desktop
 *   rule is untouched — desktop pagination renders exactly as
 *   before.
 *
 * NOTE (informational — NOT part of this fix):
 *   The core global/global/pagination.phtml template appears to
 *   contain a duplicated <li class='ipsPagination__page'> line
 *   inside its "pages after active" foreach loop. Flagged for
 *   awareness; out of scope for this gddeals-only fix, and IPS
 *   core files are never modified from this app's upgrade path.
 *
 * WHAT THIS UPGRADE DOES
 *   Cache / datastore / opcache purge so the updated deals.css
 *   reaches the browser on the next hit.
 *
 * NO schema change. NO template touched. NO lang change.
 * Rule #79: upg_10058 removed, exactly one upg dir per app.
 */

namespace IPS\gddeals\setup\upg_10059;

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
