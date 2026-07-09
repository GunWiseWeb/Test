<?php
/**
 * @brief  GD Search — upgrade 1.0.83 (Stage 3 FFL finder embed).
 *
 * WHAT SHIPS IN 1.0.83:
 *
 *   * Product page (product.phtml) template accepts a new
 *     $fflPanelHtml parameter and outputs it at the bottom of
 *     the "Prices" tab, directly below the dealer offers card.
 *   * results.php product() reads the FFL panel HTML via a
 *     triple-guarded call to \IPS\gdffl\Finder\Panel::render()
 *     (class_exists + method_exists + try/catch) — mirrors the
 *     v1.0.82 gdreviews pattern exactly. A missing / broken /
 *     disabled gdffl app leaves $fflPanelHtml as '' and the
 *     product page renders unchanged.
 *   * Template reseed via the existing templates_10046.php
 *     overlay (rule #53 convention).
 *
 * No new lang keys. No schema. All price-comparison / sort /
 * chart / alerts / wishlist / reviews-tab / restriction rows
 * from v1.0.82 are byte-identical.
 *
 * Rule #79 — the app has exactly ONE upg_* dir at a time; the
 * v1.0.82 dir was removed as part of this bump.
 */

namespace IPS\gdsearch\setup\upg_10083;

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
		   dev/html/front/search/product.phtml (which now
		   declares $fflPanelHtml in its <ips:template
		   parameters=…> header and outputs it at the tail of
		   the prices tab). templates_10046.php is the
		   canonical overlay for the product template — see
		   rule #53 for the pattern. */
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
			try { \IPS\Log::log( 'gdsearch upg_10083 template reseed: ' . $e->getMessage(), 'gdsearch' ); } catch ( \Throwable ) {}
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
