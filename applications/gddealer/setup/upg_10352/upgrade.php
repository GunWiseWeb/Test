<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.352
 *         Listing cap + import schedule follow the chosen paid tier,
 *         not the enterprise founding floor.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 * Rule #33 (standing session): do NOT call CanonicalTemplates::ensure().
 *
 * WHAT SHIPS IN 1.0.352
 *   Admin reported: after flipping Defense Depot from Max to Pro,
 *   the Overview page still showed a 10,000 listing cap. v1.0.347's
 *   getListingCap() used max(enterprise_cap, chosen_tier_cap) for
 *   any founder, so Pro's 5,000 was overridden by the 10,000
 *   enterprise floor during the founding trial.
 *
 *   Clarified intent (from the admin): Founder is a permanent
 *   recognition badge only. Cap and sync frequency should always
 *   follow the chosen paid tier. The founder-trial "perks floor"
 *   doesn't apply to dealers who have picked a real paid tier.
 *
 *   Fix:
 *
 *     - sources/Dealer/Dealer.php::getListingCap()
 *       Dropped the max(enterprise_cap, chosen_tier_cap) floor.
 *       Paid tiers always return their own cap. Only the
 *       subscription_tier='founding' placeholder (classic trial
 *       founder with no paid tier yet) still gets the enterprise
 *       cap while trial active, 0 after it expires (no paid plan
 *       = no service).
 *
 *     - sources/Dealer/Dealer.php::getEffectiveImportSchedule()
 *       Same model. Paid tier controls sync frequency; the
 *       founding tier placeholder gets enterprise sync during
 *       trial, 'none' after.
 *
 *     - dev/html/front/dealers/subscription.phtml
 *       Trial notice banner updated. For founders on a paid tier
 *       (basic/pro/enterprise/max), the banner now reads
 *       "Founding member — you're not billed on your <Tier> plan
 *       until <date>. Your Founder badge is permanent." For
 *       founders still on the 'founding' tier placeholder it
 *       keeps the "enterprise-level sync and listings until <date>"
 *       language, which is still accurate for that case.
 *       isFoundingMember() checks (which drive the Founder badge
 *       on Overview + public profile + subscription page) are
 *       UNCHANGED — the badge is still permanent regardless of
 *       tier choice.
 *
 *   NO schema change. NO extension change. NO new lang key.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Walks dev/html/*.phtml and replaces every row in
 *      core_theme_templates.
 *   2. Full datastore / template-store / opcache purge + rotate
 *      set_cache_key so compiled template classes rebuild.
 *
 * Rule #79: upg_10351 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10352;

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
		$version = '1.0.352';
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
						try { \IPS\Log::log( 'upg_10352 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10352' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10352 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10352' ); } catch ( \Throwable ) {}
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
