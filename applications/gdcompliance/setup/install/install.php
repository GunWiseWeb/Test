<?php
/**
 * @brief  GD Compliance — Install routine
 *
 * Runs after schema.json creates the gd_compliance_* tables. Seeds:
 *   - the canonical ruleset via Seeder::seedMissingRules() — IDEMPOTENT
 *     and NON-DESTRUCTIVE, so a re-install (or a crash-and-retry) never
 *     wipes edits Derrick made, and always converges the base laws to
 *     the full 19-row set.
 *   - dev/lang.php → core_sys_lang_words per language
 *   - compliance_manage permission row
 *
 * Never writes to gd_catalog. NEVER truncates or deletes rows from
 * gd_compliance_rules or gd_compliance_overrides — those are permanent
 * reference data.
 */

if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { exit; }

require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Seeder.php';
require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/AwbModels.php';
require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/PicaModels.php';

/* -------------------------------------------------------------------------
 * SEED RULESET — mid-2026 verified state magazine-capacity laws.
 * NON-DESTRUCTIVE per-row insert-only; safe on reinstall / partial crash.
 * ------------------------------------------------------------------------- */
try
{
	\IPS\gdcompliance\Seeder::seedMissingRules();
}
catch ( \Throwable $e )
{
	try { \IPS\Log::log( 'gdcompliance install rule seed: ' . $e->getMessage(), 'gdcompliance_install' ); } catch ( \Throwable ) {}
}

/* Seed the multi-state AWB named-model lists (IL PICA + CA + NY) AND
   the per-state feature-test config. Non-destructive per-row — existing
   rows preserved on reinstall or admin edit. */
try
{
	\IPS\gdcompliance\AwbModels::seedMissingRules();
	\IPS\gdcompliance\AwbModels::seedMissingModels();
}
catch ( \Throwable $e )
{
	try { \IPS\Log::log( 'gdcompliance install AWB seed: ' . $e->getMessage(), 'gdcompliance_install' ); } catch ( \Throwable ) {}
}

/* Lang seed — every key in dev/lang.php into core_sys_lang_words per language. */
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

/* Notification defaults — v1.6.24 report resolved/dismissed. */
try
{
	foreach ( [ 'gdcompliance_report_resolved', 'gdcompliance_report_dismissed' ] as $nkey )
	{
		try
		{
			\IPS\Db::i()->replace( 'core_notification_defaults', [
				'notification_app' => 'gdcompliance',
				'notification_key' => $nkey,
				'default'          => '["inline","email"]',
			] );
		}
		catch ( \Throwable ) {}
	}
}
catch ( \Throwable ) {}

/* ACP permission row. */
try
{
	$has = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_admin_permission_rows',
		[ 'app=? AND `key`=?', 'gdcompliance', 'compliance_manage' ] )->first();
	if ( !$has )
	{
		\IPS\Db::i()->insert( 'core_admin_permission_rows', [
			'app' => 'gdcompliance',
			'key' => 'compliance_manage',
			'tab' => 'gdcompliance',
		] );
	}
}
catch ( \Throwable ) {}

try { unset( \IPS\Data\Store::i()->acpmenu ); } catch ( \Throwable ) {}
try { \IPS\Data\Store::i()->clearAll(); }       catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); }       catch ( \Throwable ) {}
