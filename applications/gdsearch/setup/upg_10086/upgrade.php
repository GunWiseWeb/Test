<?php
/**
 * @brief  GD Search — upgrade 1.0.86 (expose handgun_types in ACP facet UI).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.86
 *   Adds `'handgun_types' => 'Handgun Type'` to the "Firearm"
 *   group in modules/admin/search/facets.php's hardcoded $groups
 *   array so the new facet (added in v1.0.85) shows up as a
 *   toggleable row in ACP → GD Search → Facet Settings.
 *
 *   Without this row, Derrick had no ACP UI to hide the facet on
 *   the main catalog page — every other facet has one. The
 *   underlying gd_facet_settings.hidden mechanism is unchanged
 *   (already wired by facets.php's save handler); this bump is
 *   purely surfacing the toggle.
 *
 * WHAT THIS UPGRADE DOES
 *   Cache / datastore / opcache clear so the updated facets.php
 *   controller (with the new row) loads on the next ACP request.
 *
 * NO schema change. NO template touched. NO lang change.
 * Rule #79: upg_10085 removed, exactly one upg dir per app.
 */

namespace IPS\gdsearch\setup\upg_10086;

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
		try { unset( \IPS\Data\Store::i()->modules_admin ); }        catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }        catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->gdsearch_hidden_facets ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                    catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                    catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
