<?php
/**
 * @brief  GD Loadout — upgrade 1.0.66.
 *
 * WHAT SHIPS IN 1.0.66 — fix accessory-slot picker returning
 * everything.
 *
 *   Slots.php SLOT_CATEGORIES maps three accessory slots with
 *     `'field' => 'category'`  — magazine, holster, optic
 *   and search() was building filters['category'] = name from
 *   the mapping. But gd_catalog has no `category` column, and
 *   gdsearch's Searcher only reads filters['category_id'] (an
 *   int). So the name-based filter was silently ignored and
 *   the slot pickers returned the entire catalog — the mag
 *   slot surfaced holsters, the holster slot surfaced optics,
 *   etc.
 *
 *   v1.0.66 resolves the mapping's category NAME to its numeric
 *   id via a READ-ONLY SELECT on gd_categories, then passes it
 *   as filters['category_id']. gdsearch's Searcher OR-matches
 *   term:category_id / term:top_category_id, so every child
 *   category (Handgun/Rifle/Shotgun/Drum/Extended Magazines
 *   under Magazines; IWB/OWB/Ankle/Duty/etc. under Holsters &
 *   Carry; Night Vision/Thermal under Optics) is naturally
 *   caught via top_category_id — no child-id enumeration
 *   needed at this layer.
 *
 *   Name lookups are cached per request in a static array. If
 *   a name fails to resolve the search returns empty (rather
 *   than falling back to the entire catalog).
 *
 *   Subcategory-based slots (Barrels, Triggers, Stocks, etc.)
 *   still work exactly as before — the subcategory column
 *   exists and the Searcher facet was always fine.
 *
 * gd_catalog + gd_categories remain READ-ONLY. save() /
 * delete() / search() outer flow / hub logic byte-identical.
 * Cache purge + interface_files bust.
 */

namespace IPS\gdloadout\setup\upg_10066;

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
