<?php
/**
 * @brief  GD Compliance — upgrade 1.6.2 (bundled PDF extractor + editable
 *          source URLs + manual PDF upload + CA setting)
 *
 * Code-shape change only. What lands with this version:
 *   - sources/Pdf.php: pure-PHP flate + text-op extractor (replaces the
 *     old "extractor: none" path for MD PDFs)
 *   - Roster::fetchMA/fetchMD/fetchMDDisapproved accept optional
 *     $bytesOverride so an admin upload feeds the same parse+insert
 *     pipeline as the auto-fetch
 *   - Roster::fetchAndParse reads the new gdcompliance_ca_roster_url
 *     setting (was hardcoded to self::ROSTER_URL)
 *   - roster ACP: editable source-URLs form + Upload PDF per PDF source
 *
 * upg_10602 just seeds the new setting + reseeds lang + purges caches.
 *
 * Data (rules, rosters, overrides, awb_models, awb_rules) is untouched.
 */

namespace IPS\gdcompliance\setup\upg_10602;

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
		/* ============================================================
		 * (1) Seed the new gdcompliance_ca_roster_url setting.
		 * ============================================================ */
		try
		{
			\IPS\Db::i()->replace( 'core_sys_conf_settings', [
				'conf_key'     => 'gdcompliance_ca_roster_url',
				'conf_value'   => 'https://oag.ca.gov/firearms/certified-handguns/search',
				'conf_default' => 'https://oag.ca.gov/firearms/certified-handguns/search',
				'conf_app'     => 'gdcompliance',
				'conf_report'  => 'none',
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10602 seed CA URL setting: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* Also refresh the three PDF settings so a re-install always
		   converges to the defaults; existing values kept if present. */
		foreach ( [
			[ 'gdcompliance_ma_roster_url',      'https://www.mass.gov/doc/approved-handgun-roster-april-2026/download' ],
			[ 'gdcompliance_md_roster_url',      'https://dlslibrary.state.md.us/publications/Exec/MDSP/PS5-405(a)_2026(1).pdf' ],
			[ 'gdcompliance_md_disapproved_url', 'https://mdsp.maryland.gov/media/594' ],
		] as [ $k, $default ] )
		{
			try
			{
				$exists = 0;
				try { $exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_sys_conf_settings', [ 'conf_key=?', $k ] )->first(); }
				catch ( \Throwable ) {}
				if ( ! $exists )
				{
					\IPS\Db::i()->insert( 'core_sys_conf_settings', [
						'conf_key'     => $k,
						'conf_value'   => $default,
						'conf_default' => $default,
						'conf_app'     => 'gdcompliance',
						'conf_report'  => 'none',
					] );
				}
			}
			catch ( \Throwable ) {}
		}

		/* ============================================================
		 * (2) LANG RESEED — picks up the new source-URL form labels +
		 * upload button labels.
		 * ============================================================ */
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

		/* ============================================================
		 * (3) CACHE / OPCACHE + canonical_templates purge.
		 * ============================================================ */
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
