<?php
/**
 * @brief  GD FFL Finder — install routine (Stage 1 of 3).
 *
 * IPS creates gd_ffl + gd_zip_geo automatically from
 * data/schema.json before this file runs. This install step:
 *   * Verifies both tables (gd_ffl, gd_zip_geo) exist as a
 *     defensive self-heal so a re-install or partial-crash
 *     retry can converge even if the schema.json pass was
 *     skipped.
 *   * Seeds dev/lang.php strings into core_sys_lang_words per
 *     language (rules #43/#44 shape — 6-column schema, per-row
 *     try/catch).
 *   * Clears the extension / application / acpMenu datastore
 *     caches so the two Queue extensions and the admin/manage
 *     module land on the first request after install.
 *
 * This app owns gd_ffl and gd_zip_geo. Nothing here writes to
 * another app's tables. The ATF CSV import + ZIP-centroid
 * population both run in the background queue extensions in
 * extensions/core/Queue/ — install.php never processes the full
 * ATF file itself.
 */

if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { exit; }

/* -------------------------------------------------------------------------
 * SELF-HEAL — verify gd_ffl and gd_zip_geo exist. schema.json creates
 * them, but if the JSON pass was somehow skipped we still land safely.
 * ------------------------------------------------------------------------- */
try
{
	if ( !\IPS\Db::i()->checkForTable( 'gd_ffl' ) )
	{
		try { \IPS\Log::log( 'gdffl install: gd_ffl missing after schema pass — check data/schema.json', 'gdffl_install' ); } catch ( \Throwable ) {}
	}
	if ( !\IPS\Db::i()->checkForTable( 'gd_zip_geo' ) )
	{
		try { \IPS\Log::log( 'gdffl install: gd_zip_geo missing after schema pass — check data/schema.json', 'gdffl_install' ); } catch ( \Throwable ) {}
	}
}
catch ( \Throwable ) {}

/* -------------------------------------------------------------------------
 * LANG SEED — dev/lang.php → core_sys_lang_words per language.
 * Per rules #43 (IPS 5.0.18 6-column schema) and #44 (per-row try/catch).
 * ------------------------------------------------------------------------- */
$langFile = \IPS\ROOT_PATH . '/applications/gdffl/dev/lang.php';
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
							'word_app'     => 'gdffl',
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

/* -------------------------------------------------------------------------
 * CACHE PURGE.
 * ------------------------------------------------------------------------- */
try { unset( \IPS\Data\Store::i()->applications ); }  catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->extensions ); }    catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->modules_admin ); } catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->settings ); }      catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->acpMenu ); }       catch ( \Throwable ) {}
try { \IPS\Data\Store::i()->clearAll(); }             catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}
if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
