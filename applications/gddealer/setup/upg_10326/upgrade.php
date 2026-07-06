<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.326
 *
 * WHAT SHIPS IN 1.0.326 — Dealer login entry point.
 *
 *   Adds a single URL (`/dealers/login`) that a "Dealer Login" link
 *   can point at from anywhere on the site. Behavior:
 *     * Not logged in         → IPS core login (with a `ref` back
 *                                to this endpoint so the dealer-
 *                                status redirect runs after auth).
 *     * Logged in + dealer    → dashboard.
 *     * Logged in + in group  → registration form.
 *     * Logged in + not one   → https://gunrack.deals/dealer-memberships/
 *
 *   ADDITIVE. Only touches:
 *     - modules/front/dealers/join.php (new login() action)
 *     - data/furl.json (new pages entry `dealers_login`,
 *       placed BEFORE the `join/{@do}` wildcard per rule #15)
 *
 * NO canonical template reseed. No schema. No lang. No settings.
 * Cache + FURL datastore clear only so IPS re-parses furl.json
 * and the new endpoint is routed on first request.
 */

namespace IPS\gddealer\setup\upg_10326;

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
		/* FURL datastore MUST be cleared so IPS re-parses furl.json
		   and picks up `dealers_login`; otherwise /dealers/login
		   404s until something else evicts the cache. */
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
