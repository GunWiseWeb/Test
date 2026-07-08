<?php
/**
 * @brief  GD Loadout — upgrade 1.0.69.
 *
 * WHAT SHIPS IN 1.0.69 — escape-helper TypeError fix.
 *
 *   v1.0.67's dealer-picker render passed d.dealer_id
 *   (a NUMBER) to escapeAttr(), which does
 *     return (str || '').replace(...)
 *   Numbers have no .replace(), so this threw
 *     TypeError: (str || "").replace is not a function
 *   and the dealer panel silently failed to render — the fetch
 *   returned 200 with correct JSON, but the render crashed on
 *   the first row.
 *
 *   v1.0.69 coerces to String at the source in both escapeHtml
 *   and escapeAttr:
 *     String(str == null ? '' : str).replace(...)
 *   so numbers / booleans / 0 / false never crash the caller
 *   and null / undefined still yield ''. This prevents the
 *   whole class of bug, not just this one call site.
 *
 * Pure JS fix. No schema, lang, or route changes. Cache purge
 * + interface_files bust so IPS re-serves the updated
 * builder.js. Old upg_10068 rotated out per rule #79.
 */

namespace IPS\gdloadout\setup\upg_10069;

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
