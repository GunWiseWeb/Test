<?php
/**
 * @brief  GD Compliance — upgrade 1.6.39
 *
 * WHAT SHIPS IN 1.6.39 — mykey embed-mode frame targeting fix.
 *
 *   v1.6.37 added `<base target="_parent">` to the bare embed
 *   shell so links "escape to the parent" — but this ALSO retarget-
 *   ed the mykey form submissions, hijacking the whole browser to
 *   the standalone embed URL when a dealer generated a key. That
 *   kicked them out of the dashboard.
 *
 *   Fix: remove the base tag entirely. Default behavior keeps forms
 *   and links inside the iframe, which is what we want. The post-
 *   save flow already redirects mykeyAct → mykeyRedirectUrl with
 *   embed=1 preserved (v1.6.37), so the iframe reloads in place.
 *
 * PURE CONTROLLER TWEAK. No schema, no lang, no settings.
 */

namespace IPS\gdcompliance\setup\upg_10639;

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
