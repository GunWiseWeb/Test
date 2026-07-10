<?php
/**
 * @brief  GD Contact — upgrade 1.0.9 (email TYPE + captcha OFF gate).
 *
 * Rule #79 — one upg_* dir per app. Rule #27 — dual class
 * wrapper, guard header.
 *
 * WHY v1.0.9 EXISTS:
 *   Two critical runtime bugs, both surfaced in prod logs:
 *
 *   1. Contact-form email failed at
 *      \IPS\Email::buildFromContent( $subject, $html, $plain )
 *      with "The email type must be specified when calling
 *      buildFromContent()". IPS 5's Email::buildFromContent()
 *      REQUIRES an email-type argument (TYPE_TRANSACTIONAL for
 *      one-off admin/dealer messages, TYPE_LIST for bulk).
 *      Contact-form messages are transactional. Our sendEmail()
 *      caught the throw silently, returned FALSE, and the
 *      visitor saw the generic "couldn't send" error.
 *      contact.php now passes \IPS\Email::TYPE_TRANSACTIONAL
 *      as the 4th arg — the same constant used across
 *      gddealer for every transactional notification.
 *
 *   2. Captcha validation could still fire even when the app's
 *      gdcontact_captcha_enabled setting was OFF. Render/
 *      instantiation already skipped when off, but the
 *      validate branch only checked `$captcha` (the object),
 *      not `$captchaOn` (the app setting). The validate gate
 *      is now `if ( !$errors && $captchaOn && $captcha )` so
 *      the app setting is authoritative in every code path.
 *
 * No schema, no lang, no template changes. contact.php ships
 * updated. All step1() does is bust caches so the new PHP
 * dispatches on the next hit.
 */

namespace IPS\gdcontact\setup\upg_10009;

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
		/* Cache purge — settings + module dispatchers need to
		   re-resolve so the new controller is served. */
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
