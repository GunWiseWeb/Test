<?php
/**
 * @brief  GD Loadout — upgrade 1.0.65.
 *
 * WHAT SHIPS IN 1.0.65 — AJAX state selector; no more navigation.
 *
 *   The compliance panel's state form was a POST <form> whose
 *   submit button ("Check this build" / Apply) navigated the
 *   user OFF the builder every time they changed state. This
 *   version replaces it with an AJAX handler:
 *
 *     1. The panel is now a plain <div> — no <form>, no submit
 *        button. The Apply control is <button type="button">.
 *     2. setComplianceState detects AJAX (Request::isAjax()) and
 *        returns a small JSON payload instead of redirecting.
 *        Non-AJAX callers still get the redirect (back-compat).
 *     3. A new complianceCheck endpoint returns per-UPC compliance
 *        for a batch of UPCs against a chosen state. gd_compliance_
 *        flags is SELECT-only; per-UPC try/catch so a missing
 *        gdcompliance install cannot fail the batch.
 *     4. builder.js listens on the <select id="gdlc-state">
 *        change event AND the Apply button. On fire: POST the
 *        new state to setStateUrl, then batch-recheck compliance
 *        for every filled slot's UPC via complianceCheckUrl,
 *        patch slots[].compliance in place, and re-render slot
 *        cards + summary. No page navigation at any step.
 *
 *   gd_compliance_flags and gd_catalog remain READ-ONLY across
 *   every code path. save() / delete() / search() / hub logic
 *   byte-identical.
 *
 * Cache purge + interface_files bust so IPS re-serves the
 * updated builder.js. No schema, lang, or route changes (both
 * setComplianceState and complianceCheck are `do=` action
 * routes on the existing builder controller).
 */

namespace IPS\gdloadout\setup\upg_10065;

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
