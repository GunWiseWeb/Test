<?php
/**
 * @brief  GD Bills — upgrade 1.0.12
 *
 * History-text matching precision: parseBill now uses filler-tolerant
 * regex instead of literal substrings for chamber-pass / to-governor /
 * signed-into-law detection. Fixes IL HB5136 ("Sent to the Governor"
 * with extra "the" between verb and "governor" — the old literal match
 * 'sent to governor' missed it, so the bar stalled at Senate).
 *
 * Critical signed-guard (excludes assigned/reassigned/designated) is
 * preserved exactly. Advance-only logic unchanged — became_law /
 * vetoed / failed never downgrade.
 *
 * Existing stored rows keep their stale stage until the next sync
 * re-parses them. The upgrade does NOT bulk re-parse (would need the
 * original API payload, which isn't retained).
 *
 * Self-contained per rule #79 (supersedes upg_10011). Cache + opcache
 * clear so the new method body lands.
 */

namespace IPS\gdbills\setup\upg_10012;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		try { unset( \IPS\Data\Store::i()->settings ); }     catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpmenu ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }            catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
