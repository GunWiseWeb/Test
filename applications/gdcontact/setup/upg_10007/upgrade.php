<?php
/**
 * @brief  GD Contact — upgrade 1.0.7 (bg tint + breadcrumb lang).
 *
 * Rule #79 — one upg_* dir per app. Rule #27 — dual class
 * wrapper, guard header.
 *
 * WHY v1.0.7 EXISTS:
 *   Two small tweaks:
 *     1. interface/contact.css now recolours the content-area
 *        background (.ipsContentWrap / .ipsLayout__primary-
 *        column) to hsl(0 0% 99%) — background-COLOR only,
 *        scoped via :has(.gdcontact-form-area). Other pages
 *        are untouched; the sidebar aside is not a matched
 *        ancestor so widgets stay visible.
 *     2. IPS's breadcrumb was rendering the raw lang key
 *          module__gdcontact_contact
 *        because the row didn't exist in core_sys_lang_words.
 *        This upgrade seeds the missing key (plus a small set
 *        of adjacent module__* labels IPS 5 might resolve
 *        against depending on which controller renders the
 *        breadcrumb).
 *
 * No schema, no template changes.
 */

namespace IPS\gdcontact\setup\upg_10007;

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
		/* ------------------------------------------------------------
		 * Seed the module-label lang words per language (rules
		 * #43 6-col shape / #44 per-row try/catch). The v1.0.7
		 * additions are:
		 *   module__front_contact       — IPS 5 front-module key
		 *   module__gdcontact_contact   — the one Derrick observed
		 *                                 rendering raw in the
		 *                                 breadcrumb
		 *   module__gdcontact_manage    — ACP-side variant
		 * ------------------------------------------------------------ */
		$strings = [
			'module__front_contact'     => 'Contact',
			'module__gdcontact_contact' => 'Contact',
			'module__gdcontact_manage'  => 'Contact',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $strings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdcontact',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		/* ------------------------------------------------------------
		 * Cache purge — the new contact.css URL + the lang cache
		 * both need to re-resolve on the next request so the
		 * breadcrumb + tint appear immediately.
		 * ------------------------------------------------------------ */
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
