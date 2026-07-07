<?php
/**
 * @brief  GD Reviews — upgrade 1.0.5 (Stage 3: reusable renderer).
 *
 * WHAT SHIPS IN 1.0.5:
 *
 *   * Product::renderSection() — public static that returns the
 *     full reviews-section HTML (scoped CSS + aggregate header +
 *     submit area + review list) for any UPC + viewer. gdsearch's
 *     product-page Reviews tab (Stage 3) calls this so both
 *     surfaces render identical markup with a single renderer.
 *   * Product::aggregate() — public static { count, rating } used
 *     by gdsearch for the tab-badge count.
 *   * The standalone /product-reviews/product/{upc} page now
 *     delegates to renderSection() too — one source of truth,
 *     no duplicated markup.
 *   * Form action URLs accept a base64-encoded `return` param so
 *     submitting from the gdsearch tab lands back on the tab.
 *     Redirects are validated against `\IPS\Settings::i()->base_url`
 *     before being followed (open-redirect guard).
 *
 * Pure controller / renderer refactor — no schema, lang, settings,
 * or route change. Cache clear only.
 */

namespace IPS\gdreviews\setup\upg_10005;

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
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
