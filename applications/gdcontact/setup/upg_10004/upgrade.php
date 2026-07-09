<?php
/**
 * @brief  GD Contact — upgrade 1.0.4 (custom-HTML field rendering).
 *
 * Rule #79 — one upg_* dir per app. Rule #27 — dual class
 * wrapper, guard header.
 *
 * WHY v1.0.4 EXISTS:
 *   v1.0.3's cosmetic pass dropped IPS's \IPS\Helpers\Form
 *   output (with its ipsForm / ipsFieldRow / ipsSubmit chrome)
 *   into the custom .gdcontact-card. No amount of !important
 *   CSS could reliably strip IPS's own nested boxes across
 *   every theme + skin combination, so /contact-us/ still
 *   looked like a box-in-a-box with no field spacing.
 *
 *   v1.0.4 stops using IPS Form for the visible fields
 *   entirely:
 *     * modules/front/contact/contact.php loops the enabled
 *       gd_contact_fields rows and emits ONE
 *         <div class="gdcontact-field">
 *           <label class="gdcontact-label">…</label>
 *           <input class="gdcontact-input" …>
 *         </div>
 *       block per row. Every input type (text / email / phone
 *       / number / textarea / select / checkbox) has its own
 *       branch. All values are escaped and re-filled on
 *       validation errors so users don't retype.
 *     * CSRF still validates — a hidden csrfKey input goes
 *       in the form and \IPS\Session::i()->csrfCheck() runs
 *       on POST.
 *     * CAPTCHA is still IPS's — a stand-alone
 *         new \IPS\Helpers\Form\Captcha
 *       is instantiated once so both html() (for render) and
 *       getValue() + validate() (for submit) see the same
 *       instance.
 *     * Honeypot injection + silent-reject and sendEmail()
 *       routing / Reply-To / IPS Email path are preserved
 *       byte-identical from v1.0.2 / v1.0.0.
 *     * interface/contact.css drops the ipsForm chrome resets
 *       — nothing IPS-shaped renders inside .gdcontact-body
 *       anymore. Palette / dimensions match the approved
 *       mockup exactly.
 *
 * ACP field builder + gd_contact_fields schema are unchanged.
 * No new lang keys.
 */

namespace IPS\gdcontact\setup\upg_10004;

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
		/* Cache purge — new contact.css URL + updated controller
		   markup need to re-resolve on the front dispatcher's
		   next request. */
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
