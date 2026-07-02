<?php
/**
 * @brief  GD Search — upgrade 1.0.78
 *
 * Product-page restriction popup gains a per-state exemption note
 * (rendered when gdcompliance surfaces one — currently CT AWB). The
 * chip carries a new data-exemption attribute; the popup gains a
 * yellow disclaimer block below the pin remedy. Companion is
 * gdcompliance v1.6.7 which supplies the exemption_note column
 * on gd_compliance_awb_rules and populates CT's default text.
 *
 * Template + JS only — re-runs templates_10046.php to reseed the
 * product template body into core_theme_templates so existing
 * installs pick up the new markup.
 */

namespace IPS\gdsearch\setup\upg_10078;

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
