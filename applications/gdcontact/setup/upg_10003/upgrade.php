<?php
/**
 * @brief  GD Contact — upgrade 1.0.3 (single-card redesign).
 *
 * Rule #79 — one upg_* dir per app. Rule #27 — dual class
 * wrapper, guard header.
 *
 * WHY v1.0.3 EXISTS:
 *   Cosmetic-only pass. The form at /contact-us/ renders
 *   inside a full-width nested-panel layout (box-in-a-box)
 *   that reads as ugly. v1.0.3 replaces the outer wrapper
 *   with a SINGLE clean card matching the site's navy/teal
 *   palette (mirrors the FFL finder aesthetic):
 *     * .gdcontact-card    — one bordered container, 14 px
 *                            radius, overflow hidden.
 *     * .gdcontact-header  — navy #0f2740 band with a teal
 *                            #5dcaa5 mail icon, 21 px title,
 *                            13.5 px muted #9db4cc sub-line.
 *     * .gdcontact-body    — the IPS Form output drops here;
 *                            contact.css aggressively resets
 *                            IPS's default ipsForm / fieldset
 *                            / .ipsSubmit chrome so nothing
 *                            adds a second nested border. All
 *                            inputs pick up the same 44 px /
 *                            1.5 px #cbd5e1 / 8 px-radius
 *                            style with a #0f6e56 focus ring.
 *     * Submit button becomes full-width 48 px green with a
 *       send-icon glyph.
 *
 *   NO field-logic, submission, email, routing, or honeypot
 *   changes. The honeypot injection + off-screen container +
 *   silent-reject from v1.0.2 are preserved verbatim.
 *
 * No schema, no lang changes.
 */

namespace IPS\gdcontact\setup\upg_10003;

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
		/* Cache purge — new contact.css URL needs to re-resolve
		   on the front dispatcher's next hit. */
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); }    catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
