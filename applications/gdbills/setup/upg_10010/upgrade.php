<?php
/**
 * @brief  GD Bills — upgrade 1.0.10
 *
 * ACP styling fix: every gdbills ACP page now uses native IPS 5.0.18 panel
 * chrome (ipsBox + ipsBox_body + ipsPad) and the double-dash BEM modifier
 * button/message classes (ipsButton--primary, ipsMessage--warning, etc.)
 * verified against gdcatalog/products.php on this install. The previous
 * single-underscore classes (ipsButton_primary, ipsMessage_warning) don't
 * exist in 5.0.18's ACP CSS, so the pages were rendering unstyled.
 *
 * Code/template only — no schema/lang/data change. Cache + opcache clear
 * so the new controller bodies replace the cached old ones.
 *
 * Self-contained per rule #79 (supersedes upg_10009). Defensive
 * seedExistingLaws re-run guards a skipped-release upgrader.
 */

namespace IPS\gdbills\setup\upg_10010;

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
			try { \IPS\Log::log( 'upg_10010 seedExistingLaws: ' . json_encode( $res ), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10010 seedExistingLaws: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
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
