<?php
/**
 * @brief  GD Bills — upgrade 1.0.3
 *
 * Two behavior fixes:
 *  - parseBill now prefers state_link (official state-legislature page) over
 *    LegiScan's hosted url, so newly-synced bills link to the authoritative
 *    source. Existing rows keep their stored url (state_link wasn't captured
 *    separately) until Derrick re-syncs.
 *  - The /bills/ landing view renders map-only; the bill list now only
 *    appears when a state is selected (deep-link /bills/?state=XX) or via
 *    the AJAX modal on tile click.
 *
 * Code + template change only — no schema/lang/data migration. Just clear
 * caches and opcache so the new method bodies + page template land.
 *
 * Self-contained per rule #79 (exactly one upg dir on disk; covers
 * everything since the previous shipped release).
 */

namespace IPS\gdbills\setup\upg_10003;

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
		try { unset( \IPS\Data\Store::i()->acpmenu ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }            catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
