<?php
/**
 * @brief  GD Loadout — upgrade 1.0.71.
 *
 * WHAT SHIPS IN 1.0.71 — dealer picker scale + overflow polish.
 *
 *   Two fixes on top of v1.0.70's stacked cards:
 *
 *   1) Long dealer name no longer shoves the price out of the
 *      card. The top line's flex layout now has min-width:0
 *      and the name gets text-overflow:ellipsis so it truncates
 *      cleanly. Price is flex:0 0 auto so it never shrinks.
 *      The Cheapest / Selected pill moved outside the name
 *      span (into a name-wrap sibling) so the pill isn't
 *      caught by the truncation.
 *
 *   2) Scale — a UPC with dozens of dealers previously ran
 *      down the page. Now the list renders the cheapest 8
 *      inside a scrollable container (max-height 320px). A
 *      count header at the top reads "N dealers · cheapest
 *      first"; a "Show all N dealers" button appends the
 *      remaining cards into the same scroll container and
 *      then removes itself.
 *
 *   Bindings — Select / Reset click handlers use per-button
 *   binding with a data-gdld-bound marker so repeat opens of
 *   the panel and Show-all reveals don't compound listeners.
 *
 * Cosmetic / UX only — no server, schema, lang, or route
 * changes. Cache purge + interface_files bust so IPS re-serves
 * the updated builder.js. Old upg_10070 rotated out per rule
 * #79.
 */

namespace IPS\gdloadout\setup\upg_10071;

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
