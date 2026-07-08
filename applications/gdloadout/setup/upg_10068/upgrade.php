<?php
/**
 * @brief  GD Loadout — upgrade 1.0.68.
 *
 * WHAT SHIPS IN 1.0.68 — Choose-dealer click hijack fix.
 *
 *   v1.0.67 rendered a "Choose dealer" toggle inside the filled
 *   slot card. The toggle's own click handler called
 *   e.stopPropagation(), but the CARD's click handler only
 *   short-circuited on `.gdlo-card-remove` — so any click that
 *   didn't reach the toggle handler bubbled up to openPicker(),
 *   which fires do=search. Users saw the Network tab hit
 *   do=search instead of do=dealers, and the dealer panel
 *   never rendered.
 *
 *   v1.0.68 expands the card click's early-return set to
 *   include `.gdld-toggle` and `.gdld-panel` in BOTH card
 *   renderers (createSlotCard and the extra-slot renderer).
 *   The card handler is the last line of defense: even if the
 *   per-element toggle listener fails to bind on a re-render,
 *   the click on the dealer UI does not open the search picker.
 *
 * Pure JS wiring fix. No schema, lang, or route changes. The
 * do=dealers endpoint from v1.0.67 was already correct; only
 * the client-side click routing changed.
 *
 * Cache purge + interface_files bust so IPS re-serves the
 * updated builder.js. Old upg_10067 rotated out per rule #79.
 */

namespace IPS\gdloadout\setup\upg_10068;

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
