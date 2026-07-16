<?php
/**
 * @brief  GD Loadout — upgrade 1.0.75
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.75 — remove redundant PHP compliance banner.
 *
 *   modules/front/loadouts/builder.php renderCompliancePanel()
 *   was emitting a server-side .gdlc-sum--{info|danger|warn|ok}
 *   compliance banner at page render, on top of the JS banner
 *   that interface/builder.js writes into #gdlc-summary. Users
 *   saw two contradictory messages — including one that
 *   printed a literal "{state}" because the server-side
 *   render didn't always have a resolved state name to
 *   substitute.
 *
 *   The JS is the single source of truth for the banner
 *   (interface/builder.js ~540-580): it updates live on the
 *   state dropdown, escapes the state name correctly, and
 *   handles every branch (empty build / no state / restricted
 *   / advisory / clear) with its own .gdlo-banner-- styles.
 *   builder.php's server-side .gdlc-sum block is deleted; the
 *   #gdlc-summary mount div, the state <select>, and the
 *   caller signature all remain untouched.
 *
 *   No behavior changes to the JS — this is a pure PHP-side
 *   removal so the double-message never renders again.
 *
 * NO schema, NO lang, NO seeded data. Only the PHP controller
 * changes. Cache clear so the module dispatcher re-resolves
 * the new builder.php on the next hit.
 *
 * NO CanonicalTemplates::ensure().
 */

namespace IPS\gdloadout\setup\upg_10075;

use function defined;
use function function_exists;

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
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); }    catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
