<?php
/**
 * @brief  GD Bills — upgrade 1.0.16 (widen .gd-bills to 1446px).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.16
 *   interface/bills.css `.gd-bills { max-width }` goes 1200 → 1446
 *   to match the site's Theme Editor content-width setting. This
 *   completes the site-wide sync (gddealer 1.0.331, gdloadout
 *   1.0.76, gdrebates 1.0.14, gddeals 1.0.56 all bumped to the
 *   same 1446px value in a prior deploy this session) so custom
 *   app pages stop looking narrower / "boxed" compared to the
 *   rest of the site. No shared CSS variable exists — see the
 *   inline "matches site Theme Editor content width setting
 *   (1446px)" CSS comment above each affected rule as the sync
 *   anchor.
 *
 * WHAT THIS UPGRADE DOES
 *   Cache / datastore clear so the updated CSS reaches the browser
 *   on the next hit. gdbills serves CSS from applications/gdbills/
 *   interface/bills.css directly (no dev/css/front/ copy exists —
 *   confirmed via find), so nothing to import/compile — the tar
 *   overwrite + cache clear IS the delivery mechanism.
 *
 * NO schema change. NO template touched.
 * Rule #79: upg_10015 removed, exactly one upg dir per app.
 */

namespace IPS\gdbills\setup\upg_10016;

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
