<?php
/**
 * @brief  GD Loadout — upgrade 1.0.77 (trendingLoadouts widget → native IPS markup).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.77
 *   The trendingLoadouts widget was using an entirely custom class
 *   family (.gdlo-widget, .gdlo-widget-title, .gdlo-widget-list,
 *   .gdlo-widget-item, ...) that had NO backing CSS (confirmed via
 *   grep of dev/css/front/loadouts.css — zero rules for those
 *   selectors), so the widget rendered visually disconnected from
 *   the rest of the site's widgets (which all use IPS's native
 *   ipsWidget__header / ipsWidget__content / ipsData markup and
 *   inherit the site's theme automatically).
 *
 *   Rebuilt on the same native markup pattern as gddeals'
 *   gdRecentCoupons widget (the reference the ticket confirmed
 *   as visually correct):
 *     * ipsWidget__header + <h3>
 *     * ipsWidget__content wrapper
 *     * <i-data> holding an <ol class='ipsData ipsData--table'>
 *     * ipsData__item / ipsLinkPanel / ipsData__content /
 *       ipsData__main / ipsData__title / ipsData__meta /
 *       ipsData__extra structure
 *   Same "return '' when empty" convention as gdRecentCoupons —
 *   the widget disappears entirely instead of rendering an empty
 *   container.
 *
 *   No new CSS is needed for the base look; the .ipsData--gd-loadouts
 *   marker class is available for any future loadout-specific
 *   tweak (following the .gd-widget__off / .gd-widget__badge
 *   convention already established in gddeals' deals.css).
 *
 *   Schema-verified: template references $loadout['name'] which
 *   matches the actual gd_loadouts.name column per data/schema.json
 *   (NOT title). No renames.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Reseeds the trendingLoadouts template row in
 *      core_theme_templates with the new native-markup body
 *      (idempotent via \IPS\Db::i()->replace()). Existing installs
 *      immediately get the new render on next request; fresh
 *      installs pull the same body from install.php's $templates
 *      array (kept in sync per rule #52's paired-install/upgrade
 *      requirement).
 *   2. Purges theme/template/module caches so IPS drops the
 *      compiled version of the old body.
 *
 * NO schema change. Old .gdlo-widget-* CSS classes had no rules
 * to remove (confirmed clean). Rule #79: upg_10076 removed,
 * exactly one upg dir per app.
 */

namespace IPS\gdloadout\setup\upg_10077;

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
		/* 1. Reseed the trendingLoadouts template row. Body kept
		     byte-identical to install.php's $templates entry so
		     fresh installs and upgrades converge on the same body. */
		$body = <<<'TEMPLATE_EOT'
{{if !empty($loadouts)}}
<header class='ipsWidget__header'>
	<h3><i class="fa-solid fa-fire"></i> {lang="gdloadout_trending"}</h3>
</header>
<div class='ipsWidget__content'>
	<i-data>
		<ol class='ipsData ipsData--table ipsData--gd-loadouts'>
			{{foreach $loadouts as $loadout}}
			<li class='ipsData__item'>
				<a href='{$loadout['view_url']}' class='ipsLinkPanel' tabindex="-1" aria-hidden="true"><span>{$loadout['name']}</span></a>
				<div class='ipsData__content'>
					<div class='ipsData__main'>
						<h4 class='ipsData__title'><a href='{$loadout['view_url']}'>{$loadout['name']}</a></h4>
						<p class='ipsData__meta'>{$loadout['owner_name']}</p>
					</div>
					<div class='ipsData__extra'>
						<span class='gd-widget__off'><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span>
					</div>
				</div>
			</li>
			{{endforeach}}
		</ol>
	</i-data>
</div>
{{endif}}
TEMPLATE_EOT;

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'        => 1,
				'template_app'           => 'gdloadout',
				'template_location'      => 'front',
				'template_group'         => 'widgets',
				'template_name'          => 'trendingLoadouts',
				'template_data'          => '$loadouts',
				'template_content'       => $body,
				'template_updated'       => time(),
				'template_version'       => '1.0.77',
				'template_master_key'    => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdloadout upg_10077 reseed trendingLoadouts: ' . $e->getMessage(), 'gdloadout' ); } catch ( \Throwable ) {}
		}

		/* 2. Cache purge so the compiled old body is dropped. */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); }    catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
