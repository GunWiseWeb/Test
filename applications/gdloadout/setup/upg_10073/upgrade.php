<?php
/**
 * @brief  GD Loadout — upgrade 1.0.73.
 *
 * WHAT SHIPS IN 1.0.73 — Feature B2: loadout-level preferred
 * dealer + auto-select + "Build from {dealer}" summary.
 *
 *   The user can pick one dealer for the whole build. Items
 *   that dealer carries auto-source from them; items they
 *   don't carry get flagged with a fallback-to-cheapest note.
 *   A single-dealer item auto-resolves to that dealer. A
 *   per-item explicit pick (v1.0.67) still wins over the
 *   loadout preference.
 *
 *   Schema: gd_loadouts gains preferred_dealer_id INT UNSIGNED
 *   NULL (guarded ALTER via checkForColumn; NULL default
 *   preserves the v1.0.72 cheapest-per-item behavior for
 *   every existing row). schema.json also updated so fresh
 *   installs get it.
 *
 *   New endpoints (both are `do=` actions on the existing
 *   builder controller — no route file change):
 *     do=setLoadoutDealer  — POST, CSRF, ownership-verified
 *                            UPDATE of gd_loadouts.
 *                            Returns { ok, preferred_dealer_id }.
 *     (The existing do=dealers endpoint is unchanged. The
 *     private dealersForUpc() helper it was refactored to use
 *     is reused by manage() so every filled item's dealer
 *     list ships in initData without a client round trip.)
 *
 *   READ-ONLY guarantees preserved: gd_dealer_listings,
 *   gd_dealer_feed_config, gd_catalog are SELECT-only across
 *   every code path (guarded). Only gd_loadouts gets a new
 *   column, and only builder-owned INSERT/UPDATE writes it.
 *
 * Cache purge + interface_files bust so IPS re-serves the
 * updated builder.js. No lang or route changes.
 */

namespace IPS\gdloadout\setup\upg_10073;

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
			if ( \IPS\Db::i()->checkForTable( 'gd_loadouts' )
				&& !\IPS\Db::i()->checkForColumn( 'gd_loadouts', 'preferred_dealer_id' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_loadouts', [
					'name'       => 'preferred_dealer_id',
					'type'       => 'INT',
					'length'     => 10,
					'unsigned'   => TRUE,
					'allow_null' => TRUE,
					'default'    => NULL,
					'comment'    => 'gd_dealer_feed_config.dealer_id — loadout-level preferred dealer; NULL = cheapest per item',
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10073 addColumn gd_loadouts.preferred_dealer_id: ' . $e->getMessage(), 'gdloadout' ); } catch ( \Throwable ) {}
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
