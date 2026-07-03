<?php
/**
 * @brief  GD Compliance — upgrade 1.6.9
 *
 * Two new component-match paths land in the compute engine (no schema
 * change required — both write to the existing gd_compliance_flags with
 * new firearm_type values):
 *
 *   1. awb_lower — AR/AK-pattern LOWER RECEIVERS. cat154 (Lower
 *      Receivers, clean) + cat69 (Frames & Receivers, JUNK — title-
 *      gated only) evaluated via the new Lowers classifier. A
 *      serialized AR/AK-pattern lower IS the assault weapon — no
 *      feature test needed. Cat154 rows with no platform keyword hit
 *      route to REVIEW instead of a hard flag (conservative). Emits
 *      one awb_lower row per enabled rifle-class AWB state.
 *
 *   2. magazine — STANDALONE MAGAZINES. cat38 (Magazines) parsed via
 *      Engine::parseCapacity (leading integer — NEVER LIKE). Compared
 *      to each state's LOWEST magazine ceiling across handgun/rifle/
 *      shotgun/all rules. Emits a capacity-typed magazine flag per
 *      exceeded state. cat58 is game calls / accessories and is NEVER
 *      treated as magazines.
 *
 * Overrides continue to run AFTER these passes (Override::applyAll,
 * Engine.php:719) and operate on gd_compliance_flags by (upc,
 * state_code) regardless of firearm_type — force_clear / force_restrict
 * on awb_lower or magazine rows survive recompute.
 *
 * Pin-remedy language ("FFL can pin the magazine to comply") is now
 * gated in the frontend popup on ftype !== 'magazine' — pinning a
 * loose LCM doesn't cure the restriction. That fix ships in gdsearch
 * v1.0.79.
 *
 * NO SCHEMA CHANGE. NO AUTO-COMPUTE — Derrick recomputes to populate
 * the new flag rows.
 */

namespace IPS\gdcompliance\setup\upg_10609;

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
		/* Warm the Lowers helper autoload path so any admin request in
		   the same PHP process as this upgrade gets the class without
		   an extra lookup. Non-fatal if the file isn't reachable — the
		   Engine require_once inside computeFlags handles the real
		   load path. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Lowers.php';
			\IPS\gdcompliance\Lowers::clearCache();
		}
		catch ( \Throwable ) {}

		/* Cache purges — the Flag row shape gained firearm_type in this
		   version and existing per-request caches may hold pre-shape
		   arrays. canonical_templates purge lets the paired gdsearch
		   v1.0.79 product.phtml (data-ftype attr + gated pin remedy)
		   recompile on next front-end render. */
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
