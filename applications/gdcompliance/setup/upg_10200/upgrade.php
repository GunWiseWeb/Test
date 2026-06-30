<?php
/**
 * @brief  GD Compliance — upgrade 1.2.0 (Phase 3: multi-state rosters)
 *
 *  - Renames gd_compliance_ca_roster → gd_compliance_roster, then adds
 *    roster_state, blanket, date_approved columns. Existing CA rows are
 *    migrated by stamping roster_state='CA'. All ALTERs are guarded
 *    (information_schema check + \Throwable catch) so re-runs no-op.
 *  - Adds roster_state to gd_compliance_review (defaulted to 'CA' for
 *    existing rows).
 *  - Seeds the two new settings (gdcompliance_ma_roster_url +
 *    gdcompliance_dc_derive) for installs without them yet.
 *  - Re-seeds dev/lang.php → core_sys_lang_words.
 *  - Cache + opcache clear.
 *
 * Does NOT auto-fetch MA or MD — Derrick clicks Refresh / Import when
 * ready (each refresh is a single HTTP round-trip or a single CSV
 * upload).
 *
 * Self-contained per rule #79 — supersedes upg_10100.
 */

namespace IPS\gdcompliance\setup\upg_10200;

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

		/* (1) Rename gd_compliance_ca_roster → gd_compliance_roster if the
		   v1.1.0 name is present and the v1.2.0 name isn't yet. */
		try
		{
			$old = false;
			$new = false;
			try
			{
				$old = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.TABLES', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
					$prefix . 'gd_compliance_ca_roster',
				] )->first();
			}
			catch ( \Throwable ) {}
			try
			{
				$new = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.TABLES', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
					$prefix . 'gd_compliance_roster',
				] )->first();
			}
			catch ( \Throwable ) {}

			if ( $old && !$new )
			{
				\IPS\Db::i()->query( "RENAME TABLE " . $prefix . "gd_compliance_ca_roster TO " . $prefix . "gd_compliance_roster" );
			}
			elseif ( !$old && !$new )
			{
				/* Fresh-from-1.0.0-with-skipped-1.1.0 path: create the
				   v1.2.0 shape directly. */
				\IPS\Db::i()->query( "CREATE TABLE " . $prefix . "gd_compliance_roster (
					id                INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
					roster_state      CHAR(2) NOT NULL DEFAULT 'CA',
					manufacturer      VARCHAR(120) NOT NULL DEFAULT '',
					manufacturer_norm VARCHAR(120) NOT NULL DEFAULT '',
					model_raw         VARCHAR(255) NOT NULL DEFAULT '',
					model_core        VARCHAR(255) NOT NULL DEFAULT '',
					model_sku         VARCHAR(120) NULL DEFAULT NULL,
					blanket           TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
					gun_type          VARCHAR(20) NULL DEFAULT NULL,
					barrel            VARCHAR(40) NULL DEFAULT NULL,
					caliber           VARCHAR(60) NULL DEFAULT NULL,
					caliber_norm      VARCHAR(40) NULL DEFAULT NULL,
					expired_date      DATE NULL DEFAULT NULL,
					date_approved     DATE NULL DEFAULT NULL,
					is_current        TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
					boland_added      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
					fetched_at        INT(10) UNSIGNED NULL DEFAULT NULL,
					PRIMARY KEY (id),
					KEY idx_state (roster_state),
					KEY idx_manufacturer (roster_state, manufacturer_norm),
					KEY idx_model_core (roster_state, model_core(100)),
					KEY idx_current (is_current),
					KEY idx_blanket (blanket)
				) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10200 RENAME ca_roster: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (2) Add new columns on the (now-)gd_compliance_roster table. */
		$rosterColumns = [
			'roster_state'  => "CHAR(2) NOT NULL DEFAULT 'CA'",
			'blanket'       => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
			'date_approved' => "DATE NULL DEFAULT NULL",
		];
		foreach ( $rosterColumns as $col => $type )
		{
			try
			{
				$has = false;
				try
				{
					$has = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.COLUMNS', [
						'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
						$prefix . 'gd_compliance_roster',
						$col,
					] )->first();
				}
				catch ( \Throwable ) {}

				if ( !$has )
				{
					\IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_roster ADD COLUMN ' . $col . ' ' . $type );
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10200 ALTER roster.' . $col . ': ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
			}
		}

		/* (3) Migrate ALL existing rows (which came from CA-only Phase 2) to
		   roster_state='CA'. Safe to re-run: the UPDATE matches the empty/
		   default literal that an ADD COLUMN may have left. */
		try
		{
			\IPS\Db::i()->update( 'gd_compliance_roster', [ 'roster_state' => 'CA' ], [ "roster_state='' OR roster_state IS NULL" ] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10200 backfill roster_state=CA: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (4) Add roster_state to gd_compliance_review + backfill 'CA'. */
		try
		{
			$has = false;
			try
			{
				$has = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.COLUMNS', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
					$prefix . 'gd_compliance_review',
					'roster_state',
				] )->first();
			}
			catch ( \Throwable ) {}

			if ( !$has )
			{
				\IPS\Db::i()->query( "ALTER TABLE " . $prefix . "gd_compliance_review ADD COLUMN roster_state CHAR(2) NOT NULL DEFAULT 'CA' AFTER upc, ADD KEY idx_state (roster_state)" );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10200 ALTER review.roster_state: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}
		try
		{
			\IPS\Db::i()->update( 'gd_compliance_review', [ 'roster_state' => 'CA' ], [ "roster_state='' OR roster_state IS NULL" ] );
		}
		catch ( \Throwable ) {}

		/* (5) Seed the two new settings (rule-#22 pattern) for installs that
		   don't have them yet. core_sys_conf_settings replace is harmless
		   on re-run. */
		try
		{
			\IPS\Db::i()->replace( 'core_sys_conf_settings', [
				'conf_key'     => 'gdcompliance_ma_roster_url',
				'conf_value'   => 'https://www.mass.gov/doc/approved-handgun-roster-april-2026/download',
				'conf_default' => 'https://www.mass.gov/doc/approved-handgun-roster-april-2026/download',
				'conf_app'     => 'gdcompliance',
				'conf_report'  => 'none',
			] );
		}
		catch ( \Throwable ) {}
		try
		{
			\IPS\Db::i()->replace( 'core_sys_conf_settings', [
				'conf_key'     => 'gdcompliance_dc_derive',
				'conf_value'   => '1',
				'conf_default' => '1',
				'conf_app'     => 'gdcompliance',
				'conf_report'  => 'full',
			] );
		}
		catch ( \Throwable ) {}

		/* (6) Re-seed dev/lang.php → core_sys_lang_words. */
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

		/* (7) Caches + opcache. */
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
