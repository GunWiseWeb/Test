<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.331 (widen .gdMain to 1446px).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.331
 *   dev/css/front/dealer.css `.gdMain { max-width }` goes 1400 → 1446
 *   to match the site's Theme Editor content-width setting. Every
 *   custom gd-app is being bumped to the same 1446px value in
 *   parallel (gdloadout 1.0.76, gdrebates 1.0.14, gddeals 1.0.56)
 *   so custom app pages stop looking narrower / "boxed" compared
 *   to the rest of the site. No shared CSS variable exists for
 *   this value (core theme CSS does not expose one), so the number
 *   is manually synchronized across four files — see the inline
 *   "matches site Theme Editor content width setting (1446px)"
 *   CSS comment above each affected rule as the sync anchor.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Re-imports dev/css/front/ into core_theme_css via
 *      \IPS\Theme\Dev\Theme::importDevCss (rule #60 pattern).
 *   2. Re-compiles dealer.css to its served URL via
 *      Theme::compileCss() so the new max-width value actually
 *      reaches the browser without a manual admin CSS rebuild.
 *   3. Clears theme / template / module caches + opcache.
 *
 * NO schema change. NO CanonicalTemplates::ensure() call (standing
 * project rule this session). NO template touched.
 * Rule #79: upg_10330 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10331;

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
		/* 1. Re-import dev CSS into core_theme_css (idempotent). */
		try
		{
			if ( class_exists( '\\IPS\\Theme\\Dev\\Theme' ) )
			{
				\IPS\Theme\Dev\Theme::importDevCss( 'gddealer', 0 );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10331 importDevCss failed: ' . $e->getMessage(), 'gddealer_upg_10331' ); } catch ( \Throwable ) {}
		}

		/* 2. Re-compile dealer.css into served URLs across every theme. */
		try
		{
			foreach ( \IPS\Theme::themes() as $theme )
			{
				try
				{
					if ( method_exists( $theme, 'compileCss' ) )
					{
						$theme->compileCss( 'gddealer', 'front', '.', 'dealer.css' );
					}
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10331 compileCss failed: ' . $e->getMessage(), 'gddealer_upg_10331' ); } catch ( \Throwable ) {}
		}

		/* 3. Cache purge — theme + template + module caches so the
		     recompiled CSS URL is served on the next hit. */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
