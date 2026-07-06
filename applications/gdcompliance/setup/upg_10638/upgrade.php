<?php
/**
 * @brief  GD Compliance — upgrade 1.6.38
 *
 * WHAT SHIPS IN 1.6.38 — one-line CSS readability fix.
 *
 *   modules/front/api/api.php mykeyStyles() had the .gdak-key
 *   API-key display rendering pale yellow (#fef3c7) on dark navy —
 *   hard to read. Swapped to near-white (#f8fafc) so the key /
 *   token is high-contrast and clean. Nothing else touched.
 *
 * PURE CSS TWEAK. No schema, no lang, no settings, no FURL.
 */

namespace IPS\gdcompliance\setup\upg_10638;

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
