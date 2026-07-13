<?php
/**
 * @brief  GD Deals — upgrade 1.0.51 (heat pill nowrap + byline split).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHY v1.0.51 EXISTS:
 *   Cosmetic. On the /deals/ browse cards, the heat pill
 *   ("Warm" / "Hot" / "Fire") was wrapping onto two lines
 *   ("War" / "m") because the long author-plus-timestamp
 *   byline shared a single flex row with the pill and
 *   squeezed it. The pill had no white-space:nowrap and no
 *   flex-shrink:0 so it was a shrinkable target.
 *
 *   Two-part fix:
 *     1. dev/css/front/deals.css
 *          .gd-heat-badge — added
 *            white-space: nowrap;
 *            flex-shrink: 0;
 *          .gd-card-foot-row — align-items now flex-start so
 *            the pill sits alongside a 2-line byline instead
 *            of vertically centering between them.
 *          Added .gd-card-byline / .gd-card-author /
 *            .gd-card-posted so the author is on line 1 and
 *            the date/expiry on line 2.
 *
 *     2. dev/html/front/deals/browse.phtml
 *          Split the byline span into a two-line block:
 *          <div class="gd-card-byline">
 *            <span class="gd-card-author">by Name</span>
 *            <span class="gd-card-posted">Date · Expires ...</span>
 *          </div>
 *          The heat pill remains a direct child of the flex
 *          row so `justify-content: space-between` pushes it
 *          to the right.
 *
 * No PHP controller changes, no schema, no lang, no template
 * body rewrite anywhere else. step1() clears the compiled-CSS
 * URL map + template cache so the new deals.css URL and the
 * new browse.phtml body re-resolve on the next hit.
 */

namespace IPS\gddeals\setup\upg_10051;

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
		/* Cache purge — CSS URL map + compiled template cache must
		   re-resolve so the updated deals.css URL and the new
		   browse.phtml body propagate on the next request. */
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
