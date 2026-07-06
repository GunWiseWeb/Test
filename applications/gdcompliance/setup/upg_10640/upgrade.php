<?php
/**
 * @brief  GD Compliance — upgrade 1.6.40
 *
 * WHAT SHIPS IN 1.6.40 — two small mykey display fixes:
 *
 *   FIX 1: mykey key-block color forced with !important. The
 *          .gdak-key rule was setting background:#0f172a and
 *          color:#f8fafc correctly, but a generic `code` style
 *          (from the theme wrapper or the parent embed context)
 *          was cascading over both properties, so the key
 *          rendered white text on a light-gray theme background —
 *          unreadable. Added !important to both properties. Near-
 *          white on dark navy is now forced.
 *
 *   FIX 2: <base target="_parent"> removed from the bare embed
 *          shell — carried forward from v1.6.39. (Re-verified in
 *          this ship's grep checks so a downgrade path stays
 *          clean.) The parent-window base target was hijacking
 *          the mykey form's POST out of the dashboard iframe.
 *
 * PURE CONTROLLER TWEAK. No schema, no lang, no settings.
 */

namespace IPS\gdcompliance\setup\upg_10640;

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
