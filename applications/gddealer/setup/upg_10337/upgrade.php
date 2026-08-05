<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.337 (Traffic Sources own layout + detail breakdown).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.337
 *   The v1.0.336 Traffic Sources dashboard section reused the
 *   .gdGeoRow layout (single-line flex grid built for 2-letter
 *   state codes) for long labels like "Direct / No Referrer" and
 *   "External — reddit.com" — which bunched the label against the
 *   bar with no room to breathe.
 *
 *   Three changes:
 *
 *   1. dev/html/front/dealers/analytics.phtml — Traffic Sources
 *      block now uses a dedicated .gdSourceRow layout with the
 *      label + stats stacked ABOVE a full-width bar. Renders an
 *      always-visible sub-list of the top 5 details per bucket
 *      (top hostnames for External/Search/Social; top URL paths
 *      for Internal). Direct/No-Referrer bucket has no details
 *      (referrer empty by definition).
 *
 *   2. dev/css/front/dealer.css — new .gdSourceList /
 *      .gdSourceRow{__head,__label,__stats,__bar,__fill,__details,
 *      __detailLabel,__detailCount} rules matching the app's
 *      --gd-* custom-property palette (--gd-brand,
 *      --gd-border-subtle, --gd-text, --gd-text-subtle) so it
 *      looks native, not bolted-on. Existing .gdGeoRow rules
 *      UNCHANGED — geo section is unaffected.
 *
 *   3. modules/front/dealers/dashboard.php analytics() — now
 *      computes a top-5 details sub-list per traffic bucket
 *      (Internal → path; External/Search/Social → hostname).
 *      Direct bucket skipped (nothing meaningful to show —
 *      referrer is empty by definition). New helper
 *      _detailLabelForReferrer() alongside _categorizeReferrer().
 *      Same source column (gd_click_log.referrer) — no schema
 *      change needed.
 *
 *   Implementation approach note (per ticket's "acceptable simpler
 *   alternative" option): details always-visible under each bar
 *   as a small sub-list, NOT click-to-expand. Simpler to
 *   implement correctly in one shot; the sub-lists are capped at
 *   5 rows per bucket so visual density stays under control.
 *
 * WHAT THIS UPGRADE DOES
 *   Cache / template / opcache purge so the updated PHP + new
 *   template body + new CSS load on the next request. gddealer
 *   serves dev/css/front/dealer.css via IPS 5's native
 *   importDevCss + compileCss pipeline (rule #60) — reruns both
 *   here so the new .gdSourceRow rules reach the browser without
 *   a manual admin CSS rebuild.
 *
 * NO schema change (referrer column already exists from v1.0.336).
 * NO lang change. Rule #79: upg_10336 removed, exactly one upg
 * dir per app.
 */

namespace IPS\gddealer\setup\upg_10337;

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
		/* 1. Re-import dev CSS into core_theme_css (rule #60 pattern). */
		try
		{
			if ( class_exists( '\\IPS\\Theme\\Dev\\Theme' ) )
			{
				\IPS\Theme\Dev\Theme::importDevCss( 'gddealer', 0 );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10337 importDevCss: ' . $e->getMessage(), 'gddealer_upg_10337' ); } catch ( \Throwable ) {}
		}

		/* 2. Recompile dealer.css so the new .gdSourceRow rules
		     reach the browser without a manual admin rebuild. */
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
			try { \IPS\Log::log( 'upg_10337 compileCss: ' . $e->getMessage(), 'gddealer_upg_10337' ); } catch ( \Throwable ) {}
		}

		/* 3. Cache purge. */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
