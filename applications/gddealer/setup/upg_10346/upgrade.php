<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.346
 *         Founding perks are now a FLOOR not a ceiling; Founder pill
 *         stays styled correctly after a tier bump.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 * Rule #33 (standing session): do NOT call CanonicalTemplates::ensure().
 *
 * WHAT SHIPS IN 1.0.346
 *   Admin reported: bumped Defense Depot from "founding" to "max"
 *   subscription tier (via Manage Dealers + member group change).
 *   Overview still showed a 10,000 listing cap and the Founder
 *   pill lost its founder styling.
 *
 *   Prod diagnostic confirmed:
 *     - subscription_tier = max (saved correctly)
 *     - member in group 7 (= ACP gddealer_group_founding)
 *     - trial_expires_at in the future
 *     - foundingPerksActive() = YES
 *     - gddealer_cap_enterprise override = 10000
 *     - gddealer_cap_max = 0 (unlimited)
 *     - getListingCap() = 10000  ← pinned to enterprise tier
 *
 *   Two root causes:
 *
 *   1. getListingCap() short-circuited to tierCap('enterprise')
 *      the moment foundingPerksActive() returned true — ignoring
 *      any higher tier an admin explicitly configured. Founding
 *      perks were a CEILING. Admins couldn't bump a founding
 *      dealer up to Max without first removing their founding
 *      group membership.
 *
 *   2. DealerShellTrait::prepareDealerData() set tier_label and
 *      tier_color from the raw subscription_tier string. Flipping
 *      a founding dealer to 'max' tier made the pill text drop
 *      from "Founder" to "Max" and the color CSS flip from
 *      founder-amber to max-purple, even though the dealer was
 *      still an active founding member.
 *
 *   Fix:
 *
 *   - sources/Dealer/Dealer.php::getListingCap()
 *       Founding perks are now a FLOOR, not a ceiling. The method
 *       returns max( enterprise-cap, explicit-tier-cap ). A
 *       founding dealer on Max tier gets the Max cap (unlimited
 *       by default); a founding dealer still on 'founding' tier
 *       gets 0 (preserved for expired-founder-no-paid-tier case).
 *
 *   - sources/Traits/DealerShellTrait.php::prepareDealerData()
 *       Pill label uses $d->getBadgeLabel() so "Founder" sticks
 *       when isFoundingMember() is true, regardless of configured
 *       tier. Pill color uses the founder color for founding
 *       members. The data array's subscription_tier key is set
 *       to the pill-display key ('founding' when isFounder, else
 *       the real tier) because overview.phtml uses it as a CSS
 *       class modifier (gdTierBadge--{tier}) — DB tier is
 *       unchanged.
 *
 *   NO schema change. NO extension change. NO new lang key.
 *   Source-only — tarball ships the fix for both fresh install
 *   and upgrade. The dev/html template resync is included for
 *   belt-and-suspenders.
 *
 * Rule #79: upg_10345 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10346;

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
		$version = '1.0.346';
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
						try { \IPS\Log::log( 'upg_10346 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10346' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10346 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10346' ); } catch ( \Throwable ) {}
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
