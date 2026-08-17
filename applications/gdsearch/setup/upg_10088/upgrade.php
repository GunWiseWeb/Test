<?php
/**
 * @brief  GD Search — upgrade 1.0.88 (re-seed ALL lang strings from data/lang.xml so __app_gdsearch stops rendering as raw fallback).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.88
 *   The Applications page in ACP was rendering the gdsearch entry
 *   as literal "__app_gdsearch" — IPS's fallback rendering when
 *   the language key isn't present in core_sys_lang_words. Both
 *   data/lang.xml and dev/lang.php contain the key already:
 *
 *     <word key="__app_gdsearch">GD Search</word>
 *     <word key="menutab__gdsearch">GD Search</word>
 *     <word key="menutab__gdsearch_icon">magnifying-glass</word>
 *
 *   ...but the install-time XML importer never wrote them to the
 *   DB (root cause unknown — this class of "lang.xml keys exist
 *   in code but not in DB" has been observed enough times in this
 *   session that a durable re-seed is warranted).
 *
 *   Fix: walk data/lang.xml, and for every <word> element seed
 *   the key into core_sys_lang_words for every lang_id (Rule
 *   #43/#44 — 6-col schema, per-row try/catch, so one bad row
 *   doesn't abort the loop).
 *
 * WHAT THIS UPGRADE DOES
 *   1. Loads data/lang.xml via SimpleXML (with LIBXML_NONET per
 *      rule #4). For every <word key="..."> element under
 *      <app key="gdsearch">, replaces the row in
 *      core_sys_lang_words for every lang_id in core_sys_lang.
 *   2. Language cache purge so freshly-seeded keys are picked up
 *      on the next request.
 *
 * NO schema change. NO template touched. NO CSS change.
 * Rule #79: upg_10087 removed, exactly one upg dir per app.
 */

namespace IPS\gdsearch\setup\upg_10088;

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
		$app     = 'gdsearch';
		$langXml = \IPS\ROOT_PATH . '/applications/' . $app . '/data/lang.xml';

		if ( is_readable( $langXml ) )
		{
			$strings = [];
			try
			{
				$raw = (string) @file_get_contents( $langXml );
				if ( $raw !== '' )
				{
					$xml = @simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NONET );
					if ( $xml )
					{
						foreach ( $xml->xpath( '//word' ) as $w )
						{
							$key = (string) $w['key'];
							$val = (string) $w;
							if ( $key !== '' )
							{
								$strings[ $key ] = $val;
							}
						}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10088 xml parse: ' . $e->getMessage(), 'gdsearch_upg_10088' ); } catch ( \Throwable ) {}
			}

			if ( !empty( $strings ) )
			{
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
									'word_app'     => $app,
									'word_key'     => (string) $key,
									'word_default' => (string) $val,
									'word_js'      => 0,
									'word_export'  => 1,
								] );
							}
							catch ( \Throwable $e )
							{
								try { \IPS\Log::log( 'upg_10088 lang (' . $key . '): ' . $e->getMessage(), 'gdsearch_upg_10088' ); } catch ( \Throwable ) {}
							}
						}
					}
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'upg_10088 lang loop: ' . $e->getMessage(), 'gdsearch_upg_10088' ); } catch ( \Throwable ) {}
				}
			}
		}

		/* Cache / datastore / opcache purge — specifically for lang. */
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }             catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_cache' ); }         catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
