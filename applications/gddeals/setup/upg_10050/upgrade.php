<?php
/**
 * @brief  GD Deals — upgrade 1.0.50 (browse-page grid/pagination spacing).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHY v1.0.50 EXISTS:
 *   applications/gddeals/dev/html/front/deals/browse.phtml
 *   renders <div class="gd-deal-grid"> immediately followed
 *   by {$pagination|raw}. deals.css had no margin between
 *   them, so the pagination hugged the last deal row.
 *
 *   v1.0.50 adds 32 px of breathing room via two lines in
 *   dev/css/front/deals.css:
 *     .gd-deal-grid                { margin-bottom: 32px; }
 *     .gd-deals-wrap .ipsPagination { margin-top: 32px; }
 *   Grid margin is primary; the pagination margin is belt-
 *   and-suspenders in case the grid margin collapses under a
 *   sibling with its own margin.
 *
 * No PHP, no template, no schema, no lang changes. Just cache
 * clear so the compiled CSS URL re-resolves on next hit.
 */

namespace IPS\gddeals\setup\upg_10050;

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
		/* Cache purge — the CSS file map must re-resolve so
		   the updated deals.css URL propagates on the next
		   request. */
		try { unset( \IPS\Data\Store::i()->applications ); }    catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }        catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }          catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }               catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }               catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); }           catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
