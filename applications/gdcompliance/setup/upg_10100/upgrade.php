<?php
/**
 * @brief  GD Compliance — upgrade 1.1.0 (Phase 2: CA roster matching)
 *
 *  - Creates the two new tables (gd_compliance_ca_roster + gd_compliance_review).
 *    Both ALTERs are guarded with information_schema check + \Throwable catch
 *    so re-runs are no-ops.
 *  - Re-seeds dev/lang.php into core_sys_lang_words for every language so
 *    the new gdcompliance_acp_roster_* + _review_* keys land.
 *  - Registers the new "roster" + "review" ACP menu entries.
 *  - Cache + opcache clear.
 *
 * Does NOT auto-fetch the CA roster (the fetch costs ~30 HTTP requests
 * against a slow Drupal site and writes ~954 rows). Derrick clicks
 * "Refresh CA Roster" when ready.
 */

namespace IPS\gdcompliance\setup\upg_10100;

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
		$prefix = (string) \IPS\Db::i()->prefix;

		/* (1) CREATE gd_compliance_ca_roster — guarded. */
		try
		{
			$exists = false;
			try
			{
				$exists = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.TABLES', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
					$prefix . 'gd_compliance_ca_roster',
				] )->first();
			}
			catch ( \Throwable ) {}

			if ( !$exists )
			{
				\IPS\Db::i()->query( "CREATE TABLE " . $prefix . "gd_compliance_ca_roster (
					id                INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
					manufacturer      VARCHAR(120) NOT NULL DEFAULT '',
					manufacturer_norm VARCHAR(120) NOT NULL DEFAULT '',
					model_raw         VARCHAR(255) NOT NULL DEFAULT '',
					model_core        VARCHAR(255) NOT NULL DEFAULT '',
					model_sku         VARCHAR(120) NULL DEFAULT NULL,
					gun_type          VARCHAR(20) NULL DEFAULT NULL,
					barrel            VARCHAR(40) NULL DEFAULT NULL,
					caliber           VARCHAR(60) NULL DEFAULT NULL,
					caliber_norm      VARCHAR(40) NULL DEFAULT NULL,
					expired_date      DATE NULL DEFAULT NULL,
					is_current        TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
					boland_added      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
					fetched_at        INT(10) UNSIGNED NULL DEFAULT NULL,
					PRIMARY KEY (id),
					KEY idx_manufacturer (manufacturer_norm),
					KEY idx_model_core (model_core(100)),
					KEY idx_current (is_current)
				) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10100 CREATE ca_roster: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (2) CREATE gd_compliance_review — guarded. */
		try
		{
			$exists = false;
			try
			{
				$exists = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.TABLES', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
					$prefix . 'gd_compliance_review',
				] )->first();
			}
			catch ( \Throwable ) {}

			if ( !$exists )
			{
				\IPS\Db::i()->query( "CREATE TABLE " . $prefix . "gd_compliance_review (
					id               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
					upc              VARCHAR(50) NOT NULL DEFAULT '',
					manufacturer     VARCHAR(120) NULL DEFAULT NULL,
					model_title      VARCHAR(255) NULL DEFAULT NULL,
					caliber          VARCHAR(60) NULL DEFAULT NULL,
					suggested_status VARCHAR(40) NOT NULL DEFAULT 'unmatched_review',
					candidates_json  TEXT NULL,
					resolved         TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
					resolved_status  VARCHAR(20) NULL DEFAULT NULL,
					resolved_by      INT(10) UNSIGNED NULL DEFAULT NULL,
					resolved_at      INT(10) UNSIGNED NULL DEFAULT NULL,
					created_at       INT(10) UNSIGNED NULL DEFAULT NULL,
					PRIMARY KEY (id),
					KEY idx_upc (upc),
					KEY idx_resolved (resolved)
				) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10100 CREATE review: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (3) Register the two new ACP menu controllers as permissioned. */
		foreach ( [ 'roster', 'review' ] as $ctrl )
		{
			try
			{
				$has = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_admin_permission_rows',
					[ 'app=? AND `key`=?', 'gdcompliance', 'compliance_manage' ] )->first();
				if ( !$has )
				{
					/* The base permission row is seeded by install.php; if it's missing,
					   recreate it. compliance_manage is shared by all four controllers. */
					\IPS\Db::i()->insert( 'core_admin_permission_rows', [
						'app' => 'gdcompliance',
						'key' => 'compliance_manage',
						'tab' => 'gdcompliance',
					] );
				}
			}
			catch ( \Throwable ) {}
		}

		/* (4) Re-seed dev/lang.php into core_sys_lang_words for every language. */
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

		/* (5) Caches + opcache so the new controllers + menu entries land. */
		try { unset( \IPS\Data\Store::i()->settings ); }     catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpmenu ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }            catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
