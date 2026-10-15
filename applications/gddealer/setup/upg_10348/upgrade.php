<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.348
 *         Subscription page shows actual paid tier + secondary Founder badge.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 * Rule #33 (standing session): do NOT call CanonicalTemplates::ensure().
 * Rule #60 — re-import dev/css + recompile dealer.css after any CSS edit.
 *
 * WHAT SHIPS IN 1.0.348
 *   Admin reported: on the Subscription page, the "Current Plan"
 *   showed "Founder" even after Defense Depot was bumped to Max
 *   tier. Monthly cost showed $399 (Max's price) but the plan
 *   name said Founder — misleading, since Founder is a permanent
 *   recognition badge, not a plan.
 *
 *   Fix: on the Subscription page, show the actual paid tier
 *   (Basic / Pro / Enterprise / Max) they picked, with the
 *   Founder badge as a SECONDARY indicator next to it. Fall back
 *   to "Founder" as the primary plan name only when the dealer
 *   literally hasn't picked a paid tier yet (subscription_tier =
 *   'founding'). The amber trial banner at the bottom of the
 *   page still fires via $sub['is_founding'] so founders see the
 *   trial countdown and "badge is permanent" reassurance.
 *
 *   Code:
 *     - modules/front/dealers/dashboard.php::subscription()
 *       $dashboardTierLabel = ucfirst( actual_tier ) when a paid
 *       tier is picked; 'Founder' only when stuck on 'founding'.
 *       $dashboardTierKey + $activePlanKey use the actual tier
 *       too, so the plans[].sync_label lookup resolves correctly
 *       (Max → "Enterprise-tier sync" etc.) and so the hero's
 *       gdPlanHero--{tier} CSS class picks up the right styling.
 *
 *     - dev/html/front/dealers/subscription.phtml
 *       Hero name row renders a secondary "Founder" badge
 *       whenever $sub['is_founding'] is true AND the primary
 *       tier isn't 'founding'. Classic trial founders (tier =
 *       founding) keep a single "Founder" badge as before.
 *
 *     - dev/css/front/dealer.css
 *       NEW .gdTierBadge--max (purple, matches max tier color).
 *       NEW .gdPlanHero--max::before (purple → amber gradient).
 *       Both tiers previously had no CSS so the Max badge/hero
 *       rendered unstyled.
 *
 *   NO schema change. NO extension change. NO new lang key.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Walks dev/html/*.phtml and replaces every row in
 *      core_theme_templates (same pattern as recent upgrades).
 *   2. Re-imports dev/css into core_theme_css and recompiles
 *      dealer.css to a served URL (rule #60).
 *   3. Full datastore / template-store / opcache purge + rotate
 *      set_cache_key so compiled classes rebuild.
 *
 * Rule #79: upg_10347 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10348;

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
		$app     = 'gddealer';
		$version = '1.0.348';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* v1.0.348: rule #60 — re-import dev/css and recompile
		 * dealer.css after the CSS additions in this version. */
		try
		{
			if ( class_exists( '\\IPS\\Theme\\Dev\\Theme' ) )
			{
				\IPS\Theme\Dev\Theme::importDevCss( 'gddealer', 0 );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10348 importDevCss: ' . $e->getMessage(), 'gddealer_upg_10348' ); } catch ( \Throwable ) {}
		}
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
			try { \IPS\Log::log( 'upg_10348 compileCss: ' . $e->getMessage(), 'gddealer_upg_10348' ); } catch ( \Throwable ) {}
		}

		if ( is_dir( $root ) )
		{
			try
			{
				$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
				foreach ( $it as $f )
				{
					if ( !$f->isFile() || strtolower( $f->getExtension() ) !== 'phtml' ) { continue; }
					$rel = trim( str_replace( $root, '', $f->getPathname() ), "/\\" );
					$parts = preg_split( '#[/\\\\]#', $rel );
					if ( count( $parts ) < 3 ) { continue; }
					$location = (string) $parts[0];
					$group    = (string) $parts[1];
					$name     = pathinfo( (string) end( $parts ), PATHINFO_FILENAME );
					$raw      = (string) @file_get_contents( $f->getPathname() );
					if ( $raw === '' ) { continue; }
					$params = '';
					if ( preg_match( '#<ips:template\s+parameters="([^"]*)"\s*/>#', $raw, $m ) )
					{
						$params = (string) $m[1];
					}
					$content = preg_replace( '#^\s*<ips:template[^>]*/>\s*\r?\n?#', '', $raw, 1 );

					try
					{
						\IPS\Db::i()->replace( 'core_theme_templates', [
							'template_set_id'   => 1,
							'template_app'      => $app,
							'template_location' => $location,
							'template_group'    => $group,
							'template_name'     => $name,
							'template_data'     => $params,
							'template_updated'  => time(),
							'template_version'  => $version,
							'template_content'  => (string) $content,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10348 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10348' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10348 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10348' ); } catch ( \Throwable ) {}
			}
		}

		/* Cache / datastore / opcache purge + rotate set_cache_key. */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $x ) { @unlink( $x ); }
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Db::i()->update( 'core_themes', [ 'set_cache_key' => md5( microtime() . mt_rand() ) ] ); } catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/theme_*' ) ?: [] as $x ) { @unlink( $x ); }
		try { \IPS\Theme::master()->recompileTemplates(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
