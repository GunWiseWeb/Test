<?php
/**
 * @brief  GD Compliance — upgrade 1.6.42
 *
 * WHAT SHIPS IN 1.6.42 — beginner-friendly install directions on
 * the mykey page's per-platform snippet tabs.
 *
 *   The four snippet tabs (Generic HTML / BigCommerce / Shopify /
 *   WooCommerce) had one-line notes ("Add to your product page
 *   template (Stencil)…"). Not enough for a non-technical dealer
 *   to actually follow. Replaced each with a numbered step list,
 *   easiest no-code method first per platform:
 *     * BigCommerce: Script Manager as the easy path, theme-file
 *       edit as the precise path.
 *     * Shopify: Online Store → Themes → Edit code, common
 *       template filenames, variant.barcode reminder.
 *     * WooCommerce: Code Snippets plugin (no file editing) first,
 *       child-theme functions.php second, meta-key adjustment
 *       note.
 *     * Generic HTML: where to put div vs. script + how to fill
 *       PRODUCT_UPC.
 *   Snippet bodies (the code with the pre-filled publishable key)
 *   are unchanged.
 *
 *   Added a green reassurance box above the tabs:
 *     "Pasting this code cannot break your store. Read-only —
 *      never changes cart, prices, checkout, or product data.
 *      If placed where the UPC can't be read, it simply shows
 *      nothing."
 *
 *   New scoped styles: .gdak-steps (numbered lists) and
 *   .gdak-reassure (the green banner).
 *
 * PURE CONTENT/COPY EXPANSION. No schema, no lang keys, no
 * settings, no logic change.
 */

namespace IPS\gdcompliance\setup\upg_10642;

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
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
