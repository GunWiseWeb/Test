<?php
/**
 * @brief  GD Search — upgrade 1.0.84 (Stage 3 FFL locator button).
 *
 * WHAT SHIPS IN 1.0.84:
 *
 *   * Product page (product.phtml) template parameter renamed
 *     $fflPanelHtml → $fflLocatorHtml. The BELOW-offers panel
 *     output from v1.0.83 is REMOVED; the button now floats
 *     top-right of the price-comparison chart header, next to
 *     the sort control. Everything else (offers table, sort,
 *     reviews tab, restrictions, chart, alerts, wishlist) is
 *     byte-identical to v1.0.83.
 *   * results.php product() reads the locator HTML via a
 *     triple-guarded call to
 *       \IPS\gdffl\Finder\Panel::renderButton( $upc )
 *     — mirrors the v1.0.82 gdreviews pattern exactly. A
 *     missing / broken / disabled gdffl leaves
 *     $fflLocatorHtml as '' and the product page renders
 *     unchanged (button simply absent).
 *   * Template reseed via the existing templates_10046.php
 *     overlay (rule #53 convention).
 *
 * Rule #79 — exactly one upg_* dir at a time; the v1.0.83 dir
 * was removed as part of this bump.
 */

namespace IPS\gdsearch\setup\upg_10084;

use function defined;
use function function_exists;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* Re-seed the product template from the current
		   dev/html/front/search/product.phtml (now declares
		   $fflLocatorHtml in its <ips:template parameters=…>
		   header and renders the button in the chart header). */
		try
		{
			$overlay = \IPS\ROOT_PATH . '/applications/gdsearch/setup/templates_10046.php';
			if ( is_readable( $overlay ) )
			{
				require_once $overlay;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdsearch upg_10084 template reseed: ' . $e->getMessage(), 'gdsearch' ); } catch ( \Throwable ) {}
		}

		/* Cache purge. */
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); }    catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); }              catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
