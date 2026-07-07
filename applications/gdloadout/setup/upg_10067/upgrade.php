<?php
/**
 * @brief  GD Loadout — upgrade 1.0.67.
 *
 * WHAT SHIPS IN 1.0.67 — per-item dealer picker.
 *
 *   Each loadout item can now be pinned to a specific dealer's
 *   offer instead of always defaulting to the cheapest. Uses a
 *   new preferred_dealer_id column on gd_loadout_items, plus two
 *   new AJAX endpoints on the builder controller.
 *
 *   Schema — GUARDED addColumn (checkForColumn first) so a re-run
 *   is idempotent. NULL default preserves the v1.0.66 behavior
 *   (cheapest offer) for every existing loadout row.
 *
 *   Endpoints (no route file change — both are `do=` actions on
 *   the existing builder controller):
 *     do=dealers        — GET/POST: JSON list of every ACTIVE
 *                         dealer carrying a UPC. Joins
 *                         gd_dealer_listings and gd_dealer_feed_
 *                         config for dealer_name / dealer_slug.
 *                         SELECT-only. Result set capped at 50.
 *     do=setItemDealer  — POST: persists the choice on a single
 *                         gd_loadout_items row. CSRF-checked;
 *                         ownership verified by joining
 *                         gd_loadouts → member_id.
 *
 *   The user's choice also rides on the existing save() path via
 *   preferred_dealer_id on the JSON slot payload, so a chosen
 *   dealer on an unsaved loadout is persisted at the first save.
 *
 *   READ-ONLY guarantees preserved: gd_dealer_listings,
 *   gd_dealer_feed_config, gd_catalog are SELECT-only across
 *   every code path (guarded). Only gd_loadout_items gets a new
 *   column, and only builder-owned INSERT/UPDATE writes it.
 *
 * Cache purge + interface_files bust so IPS re-serves the updated
 * builder.js. No lang / route changes.
 */

namespace IPS\gdloadout\setup\upg_10067;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		try
		{
			if ( \IPS\Db::i()->checkForTable( 'gd_loadout_items' )
				&& !\IPS\Db::i()->checkForColumn( 'gd_loadout_items', 'preferred_dealer_id' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_loadout_items', [
					'name'       => 'preferred_dealer_id',
					'type'       => 'INT',
					'length'     => 10,
					'unsigned'   => TRUE,
					'allow_null' => TRUE,
					'default'    => NULL,
					'comment'    => 'gd_dealer_feed_config.dealer_id — NULL = cheapest offer default',
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10067 addColumn preferred_dealer_id: ' . $e->getMessage(), 'gdloadout' ); } catch ( \Throwable ) {}
		}

		try { unset( \IPS\Data\Store::i()->interface_files ); }    catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
