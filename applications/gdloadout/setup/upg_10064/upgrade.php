<?php
/**
 * @brief  GD Loadout — upgrade 1.0.64.
 *
 * WHAT SHIPS IN 1.0.64 — compliance polish:
 *
 *   1. Filled slot cards in the JS builder now show the same
 *      restriction badge that search results already showed. The
 *      pick-time compliance sub-object is carried into slots[key]
 *      on assignProduct(); initFromExisting() picks it up from
 *      manage()'s per-item enrichment (same shape server-side).
 *   2. The old server-rendered per-item compliance cards are
 *      removed — they duplicated the slot badges. The state
 *      selector is kept; the summary spot now hosts a client-
 *      side compact banner written by updateAllSummaries() so it
 *      reflects add/remove instantly with no server round trip.
 *   3. Refactor: renderComplianceBadge(c) is a single renderer
 *      used by BOTH search results and slot cards, with a
 *      matching slotTintClass(c) helper. New CSS classes
 *      .gdlo-card--restricted / --advisory tint the parent
 *      slot card the same red / amber the search cards use.
 *
 *   gd_compliance_flags and gd_catalog remain READ-ONLY across
 *   every code path (SELECT only). save() / delete() / search() /
 *   suggest / hub-topic flows are byte-identical — this is JS +
 *   display polish only. No new endpoints; the state selector
 *   still uses the v1.0.62 setComplianceState action which
 *   redirects, so filled slots naturally re-evaluate to the new
 *   state on page reload.
 *
 * Cache purge + interface_files bust so IPS re-serves the
 * updated builder.js. No schema, lang, or route changes.
 */

namespace IPS\gdloadout\setup\upg_10064;

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
