<?php
/**
 * @brief  GD Compliance — upgrade 1.6.49
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.6.49 — Knives / Ammunition ACP page fixes.
 *
 *   Pure controller-side changes. No schema, no lang, no seeded
 *   data. modules/admin/compliance/knives.php +
 *   modules/admin/compliance/ammunition.php ship updated:
 *
 *   1. Flagged-products list restricted to THIS page's product
 *      category. Advisory flag rows share firearm_type='advisory'
 *      across firearms + ammo + knife; the Knives page was
 *      showing every advisory row site-wide (~45k rows / ~905
 *      pages). Added an ANSI_QUOTES-safe subselect:
 *        f.upc IN ( SELECT upc FROM {pre}gd_catalog
 *                   WHERE category_id IN ({class cats}) )
 *      Class categories:
 *        knives     -> 138, 150
 *        ammunition -> 23, 24, 25, 26, 27, 28, 29, 30
 *      The same restriction now applies to the summary tile
 *      (distinct-UPCs) and per-state count chips so those numbers
 *      also reflect only this class.
 *
 *   2. Rich pager: First / Prev / "Page N of M · X rows" / Next /
 *      Last, plus a "Jump to page" number input + Go button that
 *      GETs back to this controller with page + state preserved.
 *      Prev/next-only pager was unusable at 900+ pages. Per-page
 *      also raised 50 -> 100 to cut total page count.
 *
 *   The rules section (top of each page) is unchanged.
 *
 * NO CanonicalTemplates re-seed call. Cache clear only so the
 * module dispatcher picks up the new controller PHP on the next
 * request.
 */

namespace IPS\gdcompliance\setup\upg_10649;

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
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
