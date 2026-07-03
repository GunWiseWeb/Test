<?php
/**
 * @brief  GD Search — upgrade 1.0.79
 *
 * Product-page restriction popup gains a firearm_type awareness so the
 * pin-remedy language ("if the restriction is due to magazine capacity,
 * this item may still be transferable if the receiving FFL pins or
 * blocks the magazine") is hidden for STANDALONE MAGAZINE (LCM) flags
 * — pinning a loose magazine isn't a real remedy; the mag itself is
 * the restricted item.
 *
 * Companion is gdcompliance v1.6.9 which emits the new firearm_type
 * values (awb_lower, magazine) from Engine::computeFlags and includes
 * firearm_type in Flag::forUpc's return shape so the chip carries a
 * data-ftype attribute.
 *
 * Template + JS only — re-runs templates_10046.php to reseed the
 * product template body into core_theme_templates.
 */

namespace IPS\gdsearch\setup\upg_10079;

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
		try { require_once \IPS\ROOT_PATH . '/applications/gdsearch/setup/templates_10046.php'; }
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }            catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }        catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }          catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                   catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                   catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
