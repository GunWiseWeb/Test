<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.332 (stockactions Add-new fatal).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.332
 *   modules/admin/dealers/stockactions.php form() method — fix
 *   an E_WARNING that triggered on the "Add new" branch of ACP →
 *   Dealers → Stock Actions → Add.
 *
 *   $existing is deliberately null on the create path (only
 *   populated inside the `if ( $isEdit )` branch). Every other
 *   field in the $formData build used the null-safe `?? default`
 *   coalesce, but new_assignee used a raw `$existing[key] !==
 *   null` comparison — which accesses the offset BEFORE the null
 *   check, throwing E_WARNING "Trying to access array offset on
 *   value of type null" and short-circuiting the form render.
 *
 *   Fix: use isset( $existing['new_assignee'] ), which is
 *   null-safe against a null root and returns false cleanly.
 *   Also defensively guard the `enabled` field with `?? true`
 *   inside its edit branch so an $existing row missing that
 *   column can't crash the form. Every other unguarded $existing
 *   access in this file was audited via `grep '!==\s*null'`;
 *   only these two needed fixing (line 57's `$r['new_assignee']
 *   !== null` is on a fetched row inside a loop — guaranteed
 *   array — different context, no bug).
 *
 * WHAT THIS UPGRADE DOES
 *   Cache / datastore clear so the updated PHP loads next request.
 *
 * NO schema change. NO template touched. NO CanonicalTemplates
 * ensure() call (standing project rule this session).
 * Rule #79: upg_10331 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10332;

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
