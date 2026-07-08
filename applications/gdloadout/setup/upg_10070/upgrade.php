<?php
/**
 * @brief  GD Loadout — upgrade 1.0.70.
 *
 * WHAT SHIPS IN 1.0.70 — dealer picker visual polish.
 *
 *   v1.0.67's dealer list used .gdld-row{display:flex} in a
 *   horizontal name+meta+actions layout. Inside the narrow
 *   slot-column panel the row wrapped ugly and cramped the
 *   buttons. v1.0.70 replaces each dealer with a compact
 *   STACKED CARD:
 *
 *     - Top line: dealer name (bold, wrapping) + price
 *       (prominent, right-aligned, no-wrap). "Selected" or
 *       "Cheapest" pill next to the name where applicable.
 *     - Detail line (muted): shipping · condition · stock
 *       (stock keeps green/red).
 *     - Actions row: [Select] (primary, flex:1) + [View]
 *       (secondary, flex:1) as a full-width button pair.
 *     - Preferred / cheapest-default card gets a green tint.
 *     - "Reset to cheapest" is a full-width secondary button
 *       under the list.
 *
 *   Select / Reset click wiring is unchanged (data-gdld-select
 *   / data-gdld-key), and the v1.0.69 String() coercion in
 *   escapeAttr / escapeHtml is preserved.
 *
 * Pure JS + CSS visual change. No schema, lang, or route
 * changes. Cache purge + interface_files bust so IPS re-serves
 * the updated builder.js. Old upg_10069 rotated out per rule
 * #79.
 */

namespace IPS\gdloadout\setup\upg_10070;

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
