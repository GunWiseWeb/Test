<?php
/**
 * @brief  GD Bills — upgrade 1.0.8
 *
 * Layout-only reorder of dev/html/front/bills/page.phtml:
 * hero → map (moved up) → controls group (back-link + last-updated +
 * filter bar + "Showing N") → results sections.
 *
 * No data/lang/schema change. Cache + opcache clear so the new template
 * body lands. Re-runs the existing-laws seed defensively in case a
 * deployer is upgrading from a pre-v1.0.5 state.
 *
 * Self-contained per rule #79 (supersedes upg_10007).
 */

namespace IPS\gdbills\setup\upg_10008;

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
		try
		{
			$res = \IPS\gdbills\LegiScan::seedExistingLaws();
			try { \IPS\Log::log( 'upg_10008 seedExistingLaws: ' . json_encode( $res ), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10008 seedExistingLaws: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

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
