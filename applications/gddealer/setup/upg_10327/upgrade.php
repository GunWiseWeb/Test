<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.327
 *
 * WHAT SHIPS IN 1.0.327 — critical regression fix.
 *
 *   v1.0.326 added a FURL entry named "dealers_login" with the
 *   friendly path "login". That segment collided with the IPS
 *   core /login/ route and site-wide login broke (users hit
 *   "page not found" on the sign-in form). The block was
 *   removed from prod's furl.json by hand to restore login,
 *   but the git copy still had it — the next tarball would
 *   have rebuilt furl.json and re-broken login. This version
 *   removes the entry from the source of truth.
 *
 *   The dealer login endpoint itself is UNCHANGED and still
 *   works at index.php?app=gddealer&module=dealers&controller=join&do=login
 *   (four-way redirect from v1.0.326). Only the FURL entry is
 *   gone — the controller action stays.
 *
 *   Rule reinforced: never use FURL friendly paths that overlap
 *   IPS core segments (login, profile, members, settings,
 *   search, register, etc.).
 *
 * Cache + FURL datastore clear so the rebuilt furl.json (now
 * without the collision) takes effect immediately. No schema,
 * no lang, no settings, no canonical template touch.
 */

namespace IPS\gddealer\setup\upg_10327;

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
		/* FURL datastore MUST clear so IPS re-parses furl.json
		   without the removed entry — otherwise the collided
		   route lingers in cache and login stays broken. */
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
