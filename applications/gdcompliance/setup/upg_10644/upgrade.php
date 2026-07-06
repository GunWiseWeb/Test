<?php
/**
 * @brief  GD Compliance — upgrade 1.6.44
 *
 * WHAT SHIPS IN 1.6.44 — copy + card-order clarification on mykey.
 *
 *   The two key-type cards were rendering in the wrong order
 *   (secret first, then publishable) and used developer-speak
 *   descriptions. Most dealers only need the publishable key for
 *   the website widget. v1.6.44:
 *     * Reordered — publishable card renders FIRST, secret second.
 *     * Publishable heading + body rewritten as "for the website
 *       widget — this is the only key you need if you just want
 *       compliance info to appear on your product pages."
 *     * Secret heading changed to "advanced — direct API access"
 *       with a plain-English "most stores don't need this / keep
 *       it private" description explaining WHO needs it.
 *     * renderKeyCard() title + typeHint updated to match, so the
 *       populated (post-generate) cards read the same way.
 *
 *   BOTH KEY TYPES REMAIN FULLY FUNCTIONAL. No auth logic, no
 *   endpoint behavior, no form actions changed.
 *
 * PURE COPY / RENDER-ORDER CHANGE. No schema, no lang, no settings.
 */

namespace IPS\gdcompliance\setup\upg_10644;

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
