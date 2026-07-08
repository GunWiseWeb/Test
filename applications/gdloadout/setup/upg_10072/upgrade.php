<?php
/**
 * @brief  GD Loadout — upgrade 1.0.72.
 *
 * WHAT SHIPS IN 1.0.72 — dealer picker top-line cleanup.
 *
 *   The "Cheapest" / "Selected" pill lived on the dealer
 *   card's top line next to the dealer name. Because the
 *   name truncates with ellipsis in the narrow slot column,
 *   the pill overlapped / covered the name.
 *
 *   v1.0.72 removes the pill from the top line entirely:
 *
 *     - The top line is now just name (truncating, left) +
 *       price (right). No pill span.
 *     - A small green "Cheapest" / "Selected" tag prepends the
 *       muted detail line (shipping · condition · stock)
 *       instead. New .gdld-flag class.
 *     - The card's green .gdld-card--preferred tint still
 *       carries the primary "this is the one" signal.
 *
 *   Cleaned up: .gdld-pill and .gdld-name-wrap CSS + markup
 *   are removed. .gdld-name is now a direct child of .gdld-top
 *   so the ellipsis truncation reads cleanly.
 *
 * Cosmetic only — no server, schema, lang, or route changes.
 * Cache purge + interface_files bust so IPS re-serves the
 * updated builder.js. Old upg_10071 rotated out per rule #79.
 */

namespace IPS\gdloadout\setup\upg_10072;

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
