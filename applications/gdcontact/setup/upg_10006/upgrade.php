<?php
/**
 * @brief  GD Contact — upgrade 1.0.6 (reset scaffolding + wrapper CSS).
 *
 * Rule #79 — one upg_* dir per app. Rule #27 — dual class
 * wrapper, guard header.
 *
 * WHY v1.0.6 EXISTS:
 *   v1.0.3–v1.0.5 kept escalating: v1.0.3 wrapped the form in
 *   a custom navy-header .gdcontact-card; v1.0.5 tried to hide
 *   IPS's default outer content panel by neutralising
 *   .ipsBox / #ipsLayout_mainArea via a body.gdcontact-page
 *   scope + :has(.gdcontact-wrap). The neutralisation ended up
 *   hiding the page's WIDGETS (sidebar / footer blocks) too.
 *
 *   v1.0.6 resets that path. The form now renders as a plain
 *   .gdcontact-form-area block that sits inside IPS's normal
 *   content area (widgets and all). What was removed:
 *     * \IPS\Output::i()->bodyClasses = [ …, 'gdcontact-page' ]
 *     * \IPS\Output::i()->sidebar['enabled'] = FALSE
 *     * The navy .gdcontact-card / .gdcontact-header markup
 *       and its inline SVG mail icon.
 *     * The .gdcontact-wrap / gdcontact-page wrapper class.
 *     * All contact.css rules targeting #ipsLayout_mainArea /
 *       .ipsBox / .ipsPad / :has(.gdcontact-wrap) — those
 *       were the ones that hid the widgets.
 *
 *   What still works:
 *     * Custom-HTML field rendering from gd_contact_fields.
 *     * CSRF (hidden csrfKey + \IPS\Session::i()->csrfCheck()).
 *     * CAPTCHA (\IPS\Helpers\Form\Captcha standalone).
 *     * Honeypot (off-screen, silent-reject).
 *     * Server-side validation + preserved values on error.
 *     * Recipient routing + \IPS\Email::buildFromContent path.
 *     * ACP field builder (no ACP change).
 *
 *   Field styling stays clean: 44 px inputs, 1.5 px #cbd5e1
 *   border, 8 px radius, focus turns #0f6e56, help-text
 *   #64748b, red required marker, green Send button. Just no
 *   card chrome around them anymore.
 *
 * No schema, no lang changes.
 */

namespace IPS\gdcontact\setup\upg_10006;

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
		   next hit. */
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
