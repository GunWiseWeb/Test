<?php
/**
 * @brief  GD Loadout — upgrade 1.0.76 (widen wrappers to 1446px).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.76
 *   dev/css/front/loadouts.css two rules change:
 *     .gdlo-wiz       max-width 1400 → 1446
 *     .gr-beta-notice max-width 1400 → 1446
 *   Matches the site's Theme Editor content-width setting so the
 *   loadout builder + hub pages stop looking narrower / "boxed"
 *   compared to the rest of the site. Parallel bumps in gddealer
 *   1.0.331, gdrebates 1.0.14, and gddeals 1.0.56 sync the same
 *   1446px number across the four custom apps. No shared CSS
 *   variable exists — see the inline
 *   "matches site Theme Editor content width setting (1446px)"
 *   CSS comment above each affected rule as the sync anchor.
 *
 * WHAT THIS UPGRADE DOES
 *   Cache / datastore clear so the updated CSS reaches the browser
 *   on the next hit. Matches the pattern of every previous
 *   gdloadout CSS-touching upgrade (upg_10074, upg_10075) — this
 *   app serves loadouts.css via Theme::i()->css(..., 'interface')
 *   which does not require the importDevCss / compileCss pipeline
 *   that gddealer uses.
 *
 * NO schema change. NO template touched.
 * Rule #79: upg_10075 removed, exactly one upg dir per app.
 */

namespace IPS\gdloadout\setup\upg_10076;

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
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); }    catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
