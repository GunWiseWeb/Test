<?php
/**
 * @brief  GD Search — upgrade 1.0.76
 *
 * Product-page template edits + controller edit to bake in the server
 * hotfix for gd_compliance_flags query (the old flag_type/flag_value/
 * status columns are gone in gdcompliance v1.5.x). Adds clickable
 * state chips + reason/citation/pin-remedy popup driven by a static
 * interface/restrictpopup.js.
 *
 * No DB changes — just cache clears so the new product.phtml body
 * and restrictpopup.js pick up.
 */

namespace IPS\gdsearch\setup\upg_10076;

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
		/* Purge canonical_templates specifically so the product template
		   doesn't render from a stale .tpl. */
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                    catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                    catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
