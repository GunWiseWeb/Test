<?php
/**
 * @brief  GD Compliance — upgrade 1.4.0 (Phase 5: frontend restriction display)
 *
 * Self-contained per rule #79. Carries every migration from the v1.1.0
 * baseline forward:
 *
 *   Phase 3 (v1.2.0 + v1.2.1):
 *     - Rename gd_compliance_ca_roster → gd_compliance_roster
 *     - Add roster_state, blanket, date_approved, list_type,
 *       blanket_caliber, source_label, as_of_date, source columns
 *     - Backfill roster_state='CA' + list_type='approved'
 *     - Add roster_state to gd_compliance_review + backfill
 *     - Seed MA/MD roster URL + DC derive settings
 *
 *   Phase 4 (v1.3.0):
 *     - CREATE gd_compliance_overrides (UNIQUE upc+state_code) — at the
 *       CORRECTED VARCHAR(20) width (v1.3.1 fix baked in from CREATE).
 *
 *   Phase 4 patch (v1.3.1):
 *     - Guarded MODIFY overrides.action → VARCHAR(20) on any install
 *       where the narrow column slipped through.
 *
 *   Phase 5 (v1.4.0 — NEW):
 *     - Seed gdcompliance_front_enabled / show_reasons / disclaimer
 *     - Purge canonical_templates cache so the new front partials load
 *
 *   Every version:
 *     - Re-seed dev/lang.php → core_sys_lang_words
 *     - Cache clear + opcache reset
 *
 * All ALTERs guarded (information_schema check + \Throwable catch) so
 * re-runs are no-ops. Never auto-fetches any roster.
 */

namespace IPS\gdcompliance\setup\upg_10400;

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

		/* ============================================================
		 * PHASE 3 MIGRATIONS — carried forward.
		 * ============================================================ */

		/* (1) Rename gd_compliance_ca_roster → gd_compliance_roster. */
		try
		{
			$old = false;
			$new = false;
			try
			{
				$old = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.TABLES', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', $prefix . 'gd_compliance_ca_roster',
				] )->first();
			}
			catch ( \Throwable ) {}
			try
			{
				$new = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.TABLES', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', $prefix . 'gd_compliance_roster',
				] )->first();
			}
			catch ( \Throwable ) {}

			if ( $old && !$new )
			{
				\IPS\Db::i()->query( "RENAME TABLE " . $prefix . "gd_compliance_ca_roster TO " . $prefix . "gd_compliance_roster" );
			}
			elseif ( !$old && !$new )
			{
				\IPS\Db::i()->query( "CREATE TABLE " . $prefix . "gd_compliance_roster (
					id                INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
					roster_state      CHAR(2) NOT NULL DEFAULT 'CA',
					list_type         VARCHAR(12) NOT NULL DEFAULT 'approved',
					manufacturer      VARCHAR(120) NOT NULL DEFAULT '',
					manufacturer_norm VARCHAR(120) NOT NULL DEFAULT '',
					model_raw         VARCHAR(255) NOT NULL DEFAULT '',
					model_core        VARCHAR(255) NOT NULL DEFAULT '',
					model_sku         VARCHAR(120) NULL DEFAULT NULL,
					blanket           TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
					blanket_caliber   TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
					gun_type          VARCHAR(20) NULL DEFAULT NULL,
					barrel            VARCHAR(40) NULL DEFAULT NULL,
					caliber           VARCHAR(60) NULL DEFAULT NULL,
					caliber_norm      VARCHAR(40) NULL DEFAULT NULL,
					expired_date      DATE NULL DEFAULT NULL,
					date_approved     DATE NULL DEFAULT NULL,
					is_current        TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
					boland_added      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
					source            VARCHAR(12) NOT NULL DEFAULT 'pdf',
					source_label      VARCHAR(60) NULL DEFAULT NULL,
					as_of_date        DATE NULL DEFAULT NULL,
					fetched_at        INT(10) UNSIGNED NULL DEFAULT NULL,
					PRIMARY KEY (id),
					KEY idx_state (roster_state),
					KEY idx_manufacturer (roster_state, manufacturer_norm),
					KEY idx_model_core (roster_state, model_core(100)),
					KEY idx_current (is_current),
					KEY idx_blanket (blanket),
					KEY idx_list_type (roster_state, list_type)
				) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10400 RENAME/CREATE roster: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (2) Guarded ALTERs — all Phase-3 columns. */
		$rosterColumns = [
			'roster_state'    => "CHAR(2) NOT NULL DEFAULT 'CA'",
			'list_type'       => "VARCHAR(12) NOT NULL DEFAULT 'approved'",
			'blanket'         => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
			'blanket_caliber' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
			'date_approved'   => "DATE NULL DEFAULT NULL",
			'source'          => "VARCHAR(12) NOT NULL DEFAULT 'pdf'",
			'source_label'    => "VARCHAR(60) NULL DEFAULT NULL",
			'as_of_date'      => "DATE NULL DEFAULT NULL",
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
						$prefix . 'gd_compliance_roster', $col,
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
				try { \IPS\Log::log( 'upg_10400 ALTER roster.' . $col . ': ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
			}
		}

		/* (3) Backfill. */
		try { \IPS\Db::i()->update( 'gd_compliance_roster', [ 'roster_state' => 'CA' ], [ "roster_state='' OR roster_state IS NULL" ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->update( 'gd_compliance_roster', [ 'list_type'    => 'approved' ], [ "list_type='' OR list_type IS NULL" ] ); } catch ( \Throwable ) {}

		/* (4) Review table — add roster_state if not yet present. */
		try
		{
			$has = false;
			try
			{
				$has = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.COLUMNS', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
					$prefix . 'gd_compliance_review', 'roster_state',
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
			try { \IPS\Log::log( 'upg_10400 ALTER review.roster_state: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}
		try { \IPS\Db::i()->update( 'gd_compliance_review', [ 'roster_state' => 'CA' ], [ "roster_state='' OR roster_state IS NULL" ] ); } catch ( \Throwable ) {}

		/* ============================================================
		 * PHASE 4 (v1.3.0) — gd_compliance_overrides at CORRECTED width.
		 * ============================================================ */

		try
		{
			$has = false;
			try
			{
				$has = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.TABLES', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', $prefix . 'gd_compliance_overrides',
				] )->first();
			}
			catch ( \Throwable ) {}

			if ( !$has )
			{
				\IPS\Db::i()->query( "CREATE TABLE " . $prefix . "gd_compliance_overrides (
					id          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
					upc         VARCHAR(50) NOT NULL DEFAULT '',
					state_code  CHAR(2) NOT NULL DEFAULT '',
					action      VARCHAR(20) NOT NULL DEFAULT 'force_restrict',
					reason      VARCHAR(255) NULL DEFAULT NULL,
					created_by  INT(10) UNSIGNED NULL DEFAULT NULL,
					created_at  INT(10) UNSIGNED NULL DEFAULT NULL,
					PRIMARY KEY (id),
					UNIQUE KEY uq_upc_state (upc, state_code),
					KEY idx_upc (upc),
					KEY idx_state (state_code)
				) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10400 CREATE overrides: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * PHASE 4 PATCH (v1.3.1) — widen overrides.action if narrow.
		 * ============================================================ */

		try
		{
			$len = 0;
			try
			{
				$row = \IPS\Db::i()->select(
					'CHARACTER_MAXIMUM_LENGTH',
					'information_schema.COLUMNS',
					[
						'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
						$prefix . 'gd_compliance_overrides', 'action',
					]
				)->first();
				$len = (int) $row;
			}
			catch ( \Throwable ) {}

			if ( $len && $len < 20 )
			{
				\IPS\Db::i()->query(
					'ALTER TABLE ' . $prefix . 'gd_compliance_overrides '
					. "MODIFY action VARCHAR(20) NOT NULL DEFAULT 'force_restrict'"
				);
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10400 MODIFY overrides.action: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * SETTINGS SEED — Phase 3 URLs + Phase 5 frontend toggles.
		 * ============================================================ */

		foreach ( [
			[ 'gdcompliance_ma_roster_url',      'https://www.mass.gov/doc/approved-handgun-roster-april-2026/download', 'none' ],
			[ 'gdcompliance_md_roster_url',      'https://dlslibrary.state.md.us/publications/Exec/MDSP/PS5-405(a)_2026(1).pdf', 'none' ],
			[ 'gdcompliance_md_disapproved_url', 'https://mdsp.maryland.gov/media/594', 'none' ],
			[ 'gdcompliance_dc_derive',          '1', 'full' ],
			[ 'gdcompliance_front_enabled',      '1', 'full' ],
			[ 'gdcompliance_front_show_reasons', '1', 'full' ],
			[ 'gdcompliance_front_disclaimer',   'Restrictions are provided as guidance and may not reflect the most current law; verify before purchase.', 'none' ],
		] as [ $k, $v, $r ] )
		{
			try
			{
				\IPS\Db::i()->replace( 'core_sys_conf_settings', [
					'conf_key'     => $k,
					'conf_value'   => $v,
					'conf_default' => $v,
					'conf_app'     => 'gdcompliance',
					'conf_report'  => $r,
				] );
			}
			catch ( \Throwable ) {}
		}

		/* ============================================================
		 * LANG RESEED — 6-column schema (rule #43).
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
		 * CACHE CLEAR + PURGE canonical_templates + OPCACHE.
		 * ============================================================ */

		try { unset( \IPS\Data\Store::i()->settings ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpmenu ); }          catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }     catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->widgets ); }          catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
