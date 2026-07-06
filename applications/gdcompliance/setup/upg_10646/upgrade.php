<?php
/**
 * @brief  GD Compliance — upgrade 1.6.46
 *
 * WHAT SHIPS IN 1.6.46 — ACP API-keys table width fix.
 *
 *   The ACP list at Compliance → API Keys was rendering with 9
 *   columns + a full 40-char api_key value, pushing the row
 *   action buttons (revoke / block / unblock) off the right
 *   edge so they were unreachable. v1.6.46:
 *     * Truncated api_key display to a 16-char prefix + ellipsis
 *       in the LIST view. Full key preserved in the DB and still
 *       surfaced once at creation.
 *     * Trimmed the column include set from 9 → 7 by dropping
 *       `allowed_domains` and `created_at` from the list view.
 *       Full data unchanged in the DB.
 *
 *   PURE LIST-DISPLAY CHANGE. No schema, no lang, no auth logic,
 *   no button behavior changes.
 */

namespace IPS\gdcompliance\setup\upg_10646;

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
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
