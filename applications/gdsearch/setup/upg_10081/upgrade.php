<?php
/**
 * @brief  GD Search — upgrade 1.0.81
 *
 * WHAT SHIPS IN 1.0.81 — new offers sort mode:
 * "Cheapest total (item + shipping)".
 *
 *   Product results table gets a fifth sort mode that adds
 *   parsed shipping to the item price and orders by the true
 *   total, so dealers with a slightly higher price but free
 *   shipping can rank above dealers with a lower price and
 *   expensive shipping (the actual cheapest deal).
 *
 *     * Searcher::parseShipping() turns free-text shipping_info
 *       ("Free", "$9.99", "Flat $12", "Call for quote", "")
 *       into a numeric shipping cost or NULL when unparseable.
 *     * getDealerListings() populates shipping_cost, total_cost,
 *       shipping_parsed for every row regardless of sort so the
 *       template can always display the total when computable.
 *     * $sort === 'total' triggers an in-PHP usort: cheapest
 *       total first, unparseable rows sink to the bottom.
 *     * results.php registers 'total' in the allowed-sort list
 *       and adds the label "Cheapest total (item + shipping)"
 *       to $sortOptions.
 *     * product.phtml offers table shows "Total $XX.XX w/ ship"
 *       under the item price for rows with a parsed total.
 *
 *   Existing modes (standard / price / rating / shipping) are
 *   unchanged. No schema. Cache clear + template reseed of the
 *   `product` row so existing installs get the new markup.
 */

namespace IPS\gdsearch\setup\upg_10081;

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
		/* Re-seed the product template via the existing overlay
		   (reads dev/html/front/search/product.phtml at run time
		   and overwrites the row in core_theme_templates). */
		try { require_once \IPS\ROOT_PATH . '/applications/gdsearch/setup/templates_10046.php'; }
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }            catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }        catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }          catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                   catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                   catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); }               catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
