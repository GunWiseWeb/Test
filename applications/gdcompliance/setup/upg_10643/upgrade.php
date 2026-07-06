<?php
/**
 * @brief  GD Compliance — upgrade 1.6.43
 *
 * WHAT SHIPS IN 1.6.43 — one-line attribute fix on the mykey docs
 * cross-link. It had no target, so from inside the dealer
 * dashboard iframe it loaded the full docs page — sidebar TOC,
 * multiple sections, expected wide layout — inside the frame.
 * Docs are a reference document: they belong in their own full
 * browser tab. Added target="_blank" rel="noopener".
 *
 * PURE ANCHOR-ATTRIBUTE TWEAK. No schema, no lang, no settings.
 */

namespace IPS\gdcompliance\setup\upg_10643;

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
