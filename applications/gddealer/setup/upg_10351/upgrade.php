<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.351
 *         Overview + public profile pills: show actual paid tier +
 *         secondary Founder badge (matches Subscription page UX).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 * Rule #33 (standing session): do NOT call CanonicalTemplates::ensure().
 *
 * WHAT SHIPS IN 1.0.351
 *   Admin reported: Subscription page showed "Basic" + "FOUNDER"
 *   correctly (v1.0.348), but the Overview page still showed just
 *   "FOUNDER DEALER" — stale compared to what the Subscription
 *   page said. Public dealer profile had the same pattern.
 *
 *   v1.0.346 had made DealerShellTrait::prepareDealerData() return
 *   tier_label = 'Founder' for any founding member — correct for
 *   permanence, but it hid the paid tier the admin picked and
 *   made the Overview inconsistent with the Subscription page
 *   (which already shows the paid tier + a secondary Founder
 *   badge per v1.0.348).
 *
 *   Fix:
 *
 *   - sources/Traits/DealerShellTrait.php::prepareDealerData()
 *     tier_label = ucfirst( actual_tier ) when a paid tier is
 *     picked (basic/pro/enterprise/max); 'Founder' only when
 *     subscription_tier is literally 'founding'. tier_color
 *     drops its founding override. Two NEW fields in the returned
 *     data: is_founder (bool from isFoundingMember()) and
 *     founder_color (settings.gddealer_founding_badge_color).
 *     Templates can now render the Founder badge separately when
 *     is_founder is true.
 *
 *   - dev/html/front/dealers/overview.phtml
 *     Primary pill shows the plan tier as before. Secondary
 *     Founder pill rendered when is_founder=true AND the primary
 *     isn't already 'founding' (classic trial founders keep a
 *     single Founder pill). Matches the Subscription page hero.
 *
 *   - dev/html/front/dealers/dealerProfile.phtml
 *     Same treatment on the public dealer profile — tier badge
 *     stays the primary, Founder badge sits next to it.
 *
 *   dealerSidebar and dealerShell render tier_label in small
 *   compact spots where a secondary badge would clutter. They
 *   now show the paid tier text (e.g. "Basic dealer") which is
 *   more accurate; the Founder recognition surfaces prominently
 *   on the Overview, Subscription, and public profile.
 *
 *   NO schema change. NO extension change. NO new lang key. NO
 *   CSS change (gdTierBadge--founding already defined).
 *
 * WHAT THIS UPGRADE DOES
 *   1. Walks dev/html/*.phtml and replaces every row in
 *      core_theme_templates.
 *   2. Full datastore / template-store / opcache purge + rotate
 *      set_cache_key so compiled template classes rebuild.
 *
 * Rule #79: upg_10350 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10351;

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
		$version = '1.0.351';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

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
						try { \IPS\Log::log( 'upg_10351 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10351' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10351 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10351' ); } catch ( \Throwable ) {}
			}
		}

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
