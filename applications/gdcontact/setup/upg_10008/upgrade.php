<?php
/**
 * @brief  GD Contact — upgrade 1.0.8 (padding + bg on our own element).
 *
 * Rule #79 — one upg_* dir per app. Rule #27 — dual class
 * wrapper, guard header.
 *
 * WHY v1.0.8 EXISTS:
 *   v1.0.7's :has() rules on .ipsContentWrap and
 *   .ipsLayout__primary-column tinted the outer content area
 *   background but left our own .gdcontact-form-area with zero
 *   padding — content sat flush against the container edges.
 *   Also, touching IPS's ancestor containers was fragile and
 *   caused knock-on issues in the past (v1.0.5 hid widgets).
 *
 *   v1.0.8 fixes both cleanly:
 *     * interface/contact.css moves the background-color +
 *       padding onto our own .gdcontact-form-area element
 *       (28 px / 32 px, hsl(0 0% 99%), 12 px radius, 24 px
 *       bottom margin so it doesn't jam against the breadcrumb).
 *     * All :has() rules that targeted IPS ancestors are
 *       removed. Nothing outside our block is touched anymore.
 *
 *   Breadcrumb lang seed from v1.0.7 stays — those rows are
 *   already in core_sys_lang_words on Derrick's install and
 *   don't need re-seeding.
 *
 * No schema, no lang, no template changes.
 */

namespace IPS\gdcontact\setup\upg_10008;

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
