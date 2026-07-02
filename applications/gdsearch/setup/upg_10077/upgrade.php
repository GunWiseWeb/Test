<?php
/**
 * @brief  GD Search — upgrade 1.0.77
 *
 * Template-only tweak: enlarge state-restriction chips on the product
 * page (min-width 34→40, padding 3px 9px→5px 11px, font-size .85em→1em,
 * gap 5→6). Re-runs the templates_10046 overlay so existing installs
 * pick up the new product.phtml body from core_theme_templates.
 *
 * No DB changes — just cache purges after the reseed.
 */

namespace IPS\gdsearch\setup\upg_10077;

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
		/* Reseed the product template (and any other overlay templates)
		   with the updated body — templates_10046.php holds the canonical
		   product.phtml write for existing installs. */
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
