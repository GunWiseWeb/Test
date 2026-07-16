<?php
/**
 * @brief  GD Compliance — upgrade 1.6.50
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.6.50 — Lowers classifier default fix.
 *
 *   sources/Lowers.php: cat154 rows whose title confirms they
 *   are a lower receiver (LOWER_TITLE_GATE_KEYWORDS: "lower
 *   receiver", "stripped lower", "assembled lower", "complete
 *   lower") and which have passed every non-AWB / rimfire /
 *   bolt-model / exclusion gate now default to FLAG instead of
 *   the ambiguous-review default (Layer 7).
 *
 *   Restores the header-documented intent: "cat154 lowers flag
 *   unless there's a clear signal they're a non-AWB action
 *   (bolt/lever/pump/rimfire hunting rifle)". v1.6.13 flipped
 *   the default to review to keep Tandemkross MK-series .22
 *   pistol lowers off the flag list; those are already caught
 *   by the rimfire->review path at Layer 3b (their caliber
 *   reads .22 LR), so the more permissive default is safe.
 *
 *   Cat69 (frames & receivers junk) stays conservative — its
 *   category is noisy, so it still needs a positive signal to
 *   flag. Curated overrides (Layer 1) still win in every case.
 *
 * NO schema, NO lang, NO seeded data. Only the PHP classifier
 * changes.
 *
 * NOTE (manual recompute — NOT run in this upgrade):
 *   Derrick must re-run computeFlags on Derrick's install to
 *   regenerate gd_compliance_flags with the new classifier
 *   verdicts (~76 min CLI). Existing rows for these UPCs are
 *   currently in gd_compliance_review; a recompute wipes+
 *   rebuilds via the crash-safe stage swap. This upgrade does
 *   NOT trigger the recompute — that's an admin action.
 *
 * NO CanonicalTemplates::ensure().
 */

namespace IPS\gdcompliance\setup\upg_10650;

use function defined;
use function function_exists;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\gdcompliance\Lowers::clearCache(); }            catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
