<?php
/**
 * @brief  GD Compliance — upgrade 1.6.5 (bulk INSERT for compute; dashboard fix bake)
 *
 * Code-only release. No schema, no data touched.
 *
 * What ships:
 *   - Engine::bulkInsert() helper. computeFlags now bulk-inserts the
 *     flag stage build + review queue in chunks of 1,500 as a single
 *     parameterized multi-row INSERT per chunk. Pre-v1.6.5 the code
 *     used \IPS\Db::i()->insert($table, $arrayOfRows) which internally
 *     issued ONE INSERT per row (32k round-trips ≈ 300s of wall-clock
 *     — 92% of the 323s compute).
 *   - AWB States dashboard + Restrictions Browser converted from raw
 *     preparedQuery (which returned a mysqli_stmt whose ->fetch_assoc()
 *     throws "undefined method") to IPS-native
 *     select([table, alias])->join([table, alias], condition, 'LEFT')
 *     — same pattern gddealer/gddeals use for their joins.
 *
 * upg_10605 just reseeds lang + purges caches.
 */

namespace IPS\gdcompliance\setup\upg_10605;

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
		$langFile = \IPS\ROOT_PATH . '/applications/gdcompliance/dev/lang.php';
		if ( is_readable( $langFile ) )
		{
			$lang = [];
			include $langFile;
			if ( is_array( $lang ) && !empty( $lang ) )
			{
				try
				{
					foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
					{
						foreach ( $lang as $key => $val )
						{
							try
							{
								\IPS\Db::i()->replace( 'core_sys_lang_words', [
									'lang_id'      => (int) $langId,
									'word_app'     => 'gdcompliance',
									'word_key'     => (string) $key,
									'word_default' => (string) $val,
									'word_js'      => 0,
									'word_export'  => 1,
								] );
							}
							catch ( \Throwable ) {}
						}
					}
				}
				catch ( \Throwable ) {}
			}
		}

		try { unset( \IPS\Data\Store::i()->settings ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpmenu ); }              catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); }  catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                    catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                    catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
