<?php
/**
 * @brief  GD Compliance — upgrade 1.6.41
 *
 * WHAT SHIPS IN 1.6.41 — localize mykey Created / Last-used dates.
 *
 *   renderKeyCard() was printing the Created + Last-used dates
 *   via raw PHP date(), which uses the server timezone. The
 *   gunrack.deals server runs UTC (CLAUDE.md rule #66), so a
 *   Central-time dealer looking at their key after ~6-7pm local
 *   saw tomorrow's UTC date — wrong per their clock. Fix: use
 *   \IPS\DateTime::ts( $unix ) which renders in the viewing
 *   member's timezone (IPS handles the conversion + locale
 *   formatting).
 *
 *   Timestamps stay stored as UTC unix ints in
 *   gd_compliance_api_keys.created_at / last_used_at — this is a
 *   pure display fix.
 *
 *   The monthly-quota "Reset" line is DELIBERATELY not touched.
 *   The quota boundary is a real UTC month boundary shared by all
 *   dealers, so keeping it in UTC is correct (localizing it would
 *   be misleading — different dealers would see different reset
 *   times for the same event).
 *
 * PURE CONTROLLER TWEAK. No schema, no lang, no settings.
 */

namespace IPS\gdcompliance\setup\upg_10641;

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
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
