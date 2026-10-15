<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.350
 *         Admin tier edit now syncs member groups + paid tier wins
 *         over founding in syncTierFromGroups.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 * Rule #33 (standing session): do NOT call CanonicalTemplates::ensure().
 *
 * WHAT SHIPS IN 1.0.350
 *   Admin reported: changing Defense Depot from Max → Pro/Basic via
 *   the admin Dealers edit form made the pill flip to "Enterprise"
 *   instead of the selected tier. Root cause had two layers:
 *
 *   1. The admin edit flow saved subscription_tier to the DB but
 *      never updated the member's groups. The dealer kept accumulated
 *      tier groups (max, enterprise, ...) in mgroup_others from
 *      prior tier changes.
 *
 *   2. The gddealer listener onProfileUpdate fires on any later
 *      member group change and calls Dealer::syncTierFromGroups(),
 *      which iterated $tierPrecedence [founding, max, enterprise,
 *      pro, basic] and returned the first-matching group — so a
 *      dealer with stale max/enterprise groups left in mgroup_others
 *      got pinned to whichever was highest, overriding the admin's
 *      new Pro/Basic choice.
 *
 *      It ALSO contained an isFoundingMember() override that pinned
 *      tier='founding' for anyone in the founding group, hiding the
 *      actual paid tier they chose.
 *
 *   Fixes:
 *
 *     - sources/Dealer/Dealer.php::tierForGroups()
 *       Resolves the highest matching PAID tier first (max,
 *       enterprise, pro, basic). Falls back to 'founding' only when
 *       no paid-tier group is present (classic trial founder with
 *       no paid tier picked yet). Founder recognition flows through
 *       isFoundingMember() and the Founder pill independently —
 *       it's a badge, not a plan state.
 *
 *     - sources/Dealer/Dealer.php::syncTierFromGroups()
 *       Removed the "if isFoundingMember then force tier='founding'"
 *       override. A founder who picked a paid tier keeps that paid
 *       tier in the DB.
 *
 *     - modules/admin/dealers/dealers.php::removeDealersGroup()
 *       Added 'max' to the tier list (was missing — Max groups were
 *       never stripped). NEW $keepFounding parameter defaulting to
 *       false — the destructive callers (permanentDelete,
 *       transferOwnership) keep their nuke-everything behaviour.
 *
 *     - modules/admin/dealers/dealers.php (admin edit save)
 *       After $dealer->save(), load the member, call
 *       removeDealersGroup(member, keepFounding=true) to strip stale
 *       paid-tier groups, then assignDealersGroup(member, newTier)
 *       to add the group matching the admin's choice. Founding group
 *       is never stripped by this path — Founder recognition stays
 *       permanent per v1.0.346. Later listener-driven
 *       syncTierFromGroups now agrees with the admin's choice
 *       because the stale groups are gone.
 *
 *   NO schema change. NO extension change. NO new lang key.
 *   NO template change. Source-only.
 *
 *   Post-deploy for Defense Depot specifically: re-save the tier
 *   via admin Dealers → edit → set tier → save. That triggers the
 *   group cleanup for this one dealer. New admin tier changes
 *   going forward self-maintain.
 *
 * Rule #79: upg_10349 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10350;

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
		$version = '1.0.350';
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
						try { \IPS\Log::log( 'upg_10350 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10350' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10350 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10350' ); } catch ( \Throwable ) {}
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
