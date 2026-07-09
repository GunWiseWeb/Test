<?php
/**
 * @brief  GD Contact — upgrade 1.0.5 (strip outer IPS content panel).
 *
 * Rule #79 — one upg_* dir per app. Rule #27 — dual class
 * wrapper, guard header.
 *
 * WHY v1.0.5 EXISTS:
 *   Cosmetic-only pass. IPS wraps every non-app-templated
 *   controller output in the default content template — a
 *   full-width white .ipsBox / .ipsBox_body. Our custom
 *   .gdcontact-card was rendering INSIDE that outer white box,
 *   so /contact-us/ had two visible backgrounds stacked (a
 *   stretched-white outer panel + the centered boxed card).
 *
 *   v1.0.5 kills the outer panel FOR THIS PAGE ONLY:
 *     * modules/front/contact/contact.php now sets
 *         \IPS\Output::i()->bodyClasses[] = 'gdcontact-page';
 *       and also disables the sidebar with
 *         \IPS\Output::i()->sidebar['enabled'] = FALSE;
 *       so contact.css can scope its neutralisation rules to
 *       exactly this page and the card gets the full viewport
 *       width. Both writes are wrapped in try/catch — a
 *       hardened theme could redefine either property away.
 *     * The wrapper div also carries the `gdcontact-page`
 *       class as a defensive fallback in case some third-party
 *       theme drops the body classes on template compile.
 *     * interface/contact.css adds a scoped
 *         body.gdcontact-page #ipsLayout_mainArea > .ipsBox,
 *         body.gdcontact-page .ipsBox,
 *         body.gdcontact-page .ipsBox_body,
 *         body.gdcontact-page .ipsPad,
 *         .ipsBox:has( .gdcontact-wrap ),
 *         …
 *       reset (background transparent, border 0, padding 0,
 *       margin 0). Two independent scopes — body class + :has()
 *       — so the fix works even if one path fails.
 *
 * Every other page's content panel is untouched — without
 * .gdcontact-page on <body> AND without .gdcontact-wrap inside
 * a box, none of the selectors match.
 *
 * No schema, no lang changes.
 */

namespace IPS\gdcontact\setup\upg_10005;

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
