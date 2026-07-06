<?php
/**
 * @brief  GD Dealer — upgrade 1.0.325
 *
 * WHAT SHIPS IN 1.0.325 — polish for the v1.0.324 "API Access" tab:
 *
 *   * DealerShellTrait::sidebarNav() — API item icon changed from
 *     the invalid 'code' key to 'billing' (an existing icon key
 *     used by the "Subscription" item), so the tab now renders
 *     with a visible glyph.
 *   * dashboard.php::api() — iframe src gains ?embed=1 so
 *     gdcompliance's mykey page returns its bare (no-theme) shell.
 *     Iframe min-height reduced from 1400px to 1100px now that
 *     header/footer are gone.
 *
 * PURE CONTROLLER/VIEW TWEAK. No schema, no lang, no settings.
 * Template rows untouched. Cache clear only.
 */

namespace IPS\gddealer\setup\upg_10325;

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
		try { unset( \IPS\Data\Store::i()->modules_front ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }  catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }              catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }              catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
