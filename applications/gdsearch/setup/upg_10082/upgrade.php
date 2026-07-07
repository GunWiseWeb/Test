<?php
/**
 * @brief  GD Search — upgrade 1.0.82 (Stage 3 reviews tab).
 *
 * WHAT SHIPS IN 1.0.82:
 *
 *   * Product page (product.phtml) wraps the pre-existing offers
 *     card in a "Prices" tab and adds a "Reviews (N)" tab whose
 *     panel outputs a $reviewsHtml string produced by gdreviews'
 *     shared Product::renderSection().
 *   * results.php product() reads the reviews section + aggregate
 *     via a triple-guarded call (class_exists + method_exists +
 *     try/catch) — a missing / broken gdreviews can never break
 *     the price page.
 *   * Two new lang keys (gdsearch_tab_prices, gdsearch_tab_reviews).
 *
 * The existing price-comparison markup / logic / sort / chart /
 * alerts / wishlist / restriction rows are byte-identical — the
 * change wraps them in a tab panel, nothing else. Cheapest total
 * sort and everything else from v1.0.81 is intact.
 *
 * Template reseed via the existing templates_10046.php overlay
 * (rule #53 convention) + cache clear. No schema.
 */

namespace IPS\gdsearch\setup\upg_10082;

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
		   and overwrites core_theme_templates). */
		try { require_once \IPS\ROOT_PATH . '/applications/gdsearch/setup/templates_10046.php'; }
		catch ( \Throwable ) {}

		/* Lang re-seed for the two new tab labels — per rules
		   #43/#44 (6-col schema, per-row try/catch). */
		$v1082 = [
			'gdsearch_tab_prices'  => 'Prices',
			'gdsearch_tab_reviews' => 'Reviews',
		];
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $v1082 as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdsearch',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
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
