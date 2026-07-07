<?php
/**
 * @brief  GD Loadout — upgrade 1.0.63.
 *
 * WHAT SHIPS IN 1.0.63 — real-time compliance on search results.
 *
 *   Every result the loadout builder's search() returns now carries
 *   a `compliance` sub-array (state, restricted_here, advisory_here,
 *   reason_here, restricted_count, restricted_codes) read live from
 *   gd_compliance_flags for the buyer's persisted state. builder.js
 *   renders a matching badge on each result card BEFORE the buyer
 *   clicks add — restrictions are visible while browsing, not only
 *   after saving the loadout.
 *
 *   The v1.0.62 compliance panel stays as a final-review of the
 *   saved build.
 *
 *   All gd_compliance_flags reads are SELECT-only and wrapped in
 *   try/catch so gdcompliance being missing / a locked flags table
 *   can never fail a search. gd_catalog is unread here (already
 *   read for other fields; nothing new).
 *
 * Cache purge + interface asset bust (via `interface_files`
 * datastore clear) so IPS re-serves the updated builder.js on
 * first request.
 */

namespace IPS\gdloadout\setup\upg_10063;

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
