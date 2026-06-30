<?php
/**
 * @brief  GD Bills — upgrade 1.0.4
 *
 * Port of the WordPress plugin's parse_bill_from_get_bill logic into
 * sources/LegiScan.php::parseBill:
 *   - LegiScan status_code (1-6) → coarse bill_type/status/progress_stage
 *     classification (4=enacted, 5=vetoed, 6=failed; 1-3=pending).
 *   - History-text refinement (advance-only) lifts progress_stage to
 *     passed_senate / passed_house / to_governor when the action text
 *     matches, but never downgrades a became_law/vetoed/failed result.
 *   - CRITICAL signed-detection guard rejects "assigned"/"reassigned"/
 *     "designated" so a bill that was merely re-assigned to committee
 *     can never be mis-flagged as signed into law.
 *   - Primary sponsor is the entry with sponsor_type_id == 1 (was
 *     sponsors[0] which could be a co-sponsor).
 *
 * Code-only fix — no schema/lang/template change. Just clear caches and
 * opcache so the new parseBill body lands. Existing rows keep their
 * stored bill_type/status/progress_stage until a re-sync overwrites
 * them with the corrected classification (status_code wasn't captured
 * separately so backfill isn't possible without the original payload).
 *
 * Self-contained per rule #79 (exactly one upg dir; supersedes upg_10003).
 */

namespace IPS\gdbills\setup\upg_10004;

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
