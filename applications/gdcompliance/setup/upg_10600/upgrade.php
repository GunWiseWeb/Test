<?php
/**
 * @brief  GD Compliance — upgrade 1.6.0 (multi-state AWB framework)
 *
 * Generalizes the IL-only PICA layer into a per-state AWB engine.
 *
 * Key migration:
 *   1. RENAME gd_compliance_pica_models → gd_compliance_awb_models
 *   2. ADD COLUMN state_code CHAR(2) NOT NULL DEFAULT 'IL' to that table
 *      (all pre-existing rows are IL PICA patterns; default catches them)
 *   3. Drop the old UNIQUE(pattern_norm) index and add UNIQUE(state_code,
 *      pattern_norm) so the same pattern can be enabled per state
 *   4. CREATE gd_compliance_awb_rules — per-state feature-test config
 *   5. Seed IL + CA + NY named lists (non-destructive per state, pattern_norm)
 *   6. Seed IL + CA + NY awb_rules enabled; CT/NJ/MA/MD/WA/DC/DE/RI/VA
 *      seeded but with enabled=0 (RI+VA date-gated 2026-07-01, RI enabled)
 *
 * NEVER truncates gd_compliance_rules, gd_compliance_overrides,
 * gd_compliance_awb_models, or gd_compliance_awb_rules. Does NOT
 * auto-run compute.
 */

namespace IPS\gdcompliance\setup\upg_10600;

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
		 * (1) Ensure every canonical table exists (fresh install path
		 * for any prior version). CREATE IF NOT EXISTS is idempotent.
		 * ============================================================ */

		$creates = [
			"CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_rules (
				id             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				state_code     CHAR(2) NOT NULL DEFAULT '',
				firearm_type   VARCHAR(20) NOT NULL DEFAULT 'all',
				max_capacity   INT(10) UNSIGNED NOT NULL DEFAULT 0,
				rule_type      VARCHAR(50) NOT NULL DEFAULT 'sale_transfer',
				effective_date DATE NULL DEFAULT NULL,
				expires_date   DATE NULL DEFAULT NULL,
				enabled        TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
				source_note    VARCHAR(255) NULL DEFAULT NULL,
				updated_at     INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (id),
				KEY idx_state (state_code),
				KEY idx_enabled (enabled)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_flags (
				id              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				upc             VARCHAR(50) NOT NULL DEFAULT '',
				state_code      CHAR(2) NOT NULL DEFAULT '',
				firearm_type    VARCHAR(20) NOT NULL DEFAULT 'all',
				parsed_capacity INT(10) UNSIGNED NULL DEFAULT NULL,
				rule_id         INT(10) UNSIGNED NOT NULL DEFAULT 0,
				reason          VARCHAR(255) NULL DEFAULT NULL,
				citation        VARCHAR(255) NULL DEFAULT NULL,
				computed_at     INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (id),
				KEY idx_upc (upc),
				KEY idx_state (state_code),
				KEY idx_upc_state (upc, state_code)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_overrides (
				id         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				upc        VARCHAR(50) NOT NULL DEFAULT '',
				state_code CHAR(2) NOT NULL DEFAULT '',
				action     VARCHAR(20) NOT NULL DEFAULT 'force_restrict',
				reason     VARCHAR(255) NULL DEFAULT NULL,
				created_by INT(10) UNSIGNED NULL DEFAULT NULL,
				created_at INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_upc_state (upc, state_code),
				KEY idx_upc (upc),
				KEY idx_state (state_code)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_review (
				id               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				upc              VARCHAR(50) NOT NULL DEFAULT '',
				roster_state     CHAR(2) NOT NULL DEFAULT 'CA',
				manufacturer     VARCHAR(120) NULL DEFAULT NULL,
				model_title      VARCHAR(255) NULL DEFAULT NULL,
				caliber          VARCHAR(60) NULL DEFAULT NULL,
				suggested_status VARCHAR(40) NOT NULL DEFAULT 'unmatched_review',
				resolved         TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				resolved_status  VARCHAR(20) NULL DEFAULT NULL,
				resolved_by      INT(10) UNSIGNED NULL DEFAULT NULL,
				resolved_at      INT(10) UNSIGNED NULL DEFAULT NULL,
				created_at       INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (id),
				KEY idx_upc (upc),
				KEY idx_state (roster_state),
				KEY idx_resolved (resolved),
				KEY idx_upc_state (upc, roster_state)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_roster (
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
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_unparsed (
				id             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				capacity_value VARCHAR(100) NOT NULL DEFAULT '',
				count          INT(10) UNSIGNED NOT NULL DEFAULT 0,
				updated_at     INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			/* awb_models is the v1.6+ shape; if a legacy pica_models
			   table exists it's renamed below in step (2). */
			"CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_awb_models (
				id             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				state_code     CHAR(2) NOT NULL DEFAULT 'IL',
				pattern        VARCHAR(120) NOT NULL DEFAULT '',
				pattern_norm   VARCHAR(120) NOT NULL DEFAULT '',
				platform_group VARCHAR(40) NOT NULL DEFAULT '',
				citation       VARCHAR(255) NULL DEFAULT NULL,
				enabled        TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
				updated_at     INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_state_norm (state_code, pattern_norm),
				KEY idx_state (state_code),
				KEY idx_enabled (enabled)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			"CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_awb_rules (
				id                      INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				state_code              CHAR(2) NOT NULL DEFAULT '',
				firearm_class           VARCHAR(20) NOT NULL DEFAULT 'rifle',
				feature_count_threshold TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
				centerfire_only         TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
				max_overall_length_in   DECIMAL(6,2) NULL DEFAULT NULL,
				min_capacity_fixed      INT(5) UNSIGNED NULL DEFAULT NULL,
				citation                VARCHAR(255) NULL DEFAULT NULL,
				effective_date          DATE NULL DEFAULT NULL,
				expires_date            DATE NULL DEFAULT NULL,
				enabled                 TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
				notes                   VARCHAR(255) NULL DEFAULT NULL,
				updated_at              INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_state_class (state_code, firearm_class),
				KEY idx_state_enabled (state_code, enabled)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
		];
		foreach ( $creates as $sql )
		{
			try { \IPS\Db::i()->query( $sql ); }
			catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10600 CREATE: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {} }
		}

		/* ============================================================
		 * (2) MIGRATE from gd_compliance_pica_models → gd_compliance_awb_models.
		 *
		 * If the old table exists AND the new one is empty, copy every
		 * row across tagged state_code='IL'. Then drop the old table.
		 * (If the new one already has data, we assume the migration ran
		 * previously — skip the copy but still drop the legacy table.)
		 * ============================================================ */

		try
		{
			$oldExists = 0;
			try
			{
				$oldExists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.TABLES', [
					'TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', $prefix . 'gd_compliance_pica_models',
				] )->first();
			}
			catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10600 pica_models info_schema probe: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {} }

			if ( $oldExists )
			{
				$newRowCount = 0;
				try { $newRowCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_awb_models' )->first(); }
				catch ( \Throwable ) {}

				if ( $newRowCount === 0 )
				{
					/* Copy rows across, tag IL. Use INSERT IGNORE against
					   the (state_code, pattern_norm) unique so a duplicate
					   between legacy + fresh seed doesn't blow up. */
					try
					{
						\IPS\Db::i()->query(
							"INSERT IGNORE INTO " . $prefix . "gd_compliance_awb_models "
							. "(state_code, pattern, pattern_norm, platform_group, citation, enabled, updated_at) "
							. "SELECT 'IL', pattern, pattern_norm, platform_group, citation, enabled, updated_at "
							. "FROM " . $prefix . "gd_compliance_pica_models"
						);
					}
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10600 pica_models copy: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {} }
				}

				/* Drop the legacy table now that migration ran. */
				try { \IPS\Db::i()->query( "DROP TABLE " . $prefix . "gd_compliance_pica_models" ); }
				catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10600 DROP pica_models: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {} }
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10600 pica migration wrapper: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * (3) Carry-forward migrations from v1.5.x (guarded ALTERs,
		 * vestigial column drops, citation column ADD).
		 * ============================================================ */

		try { \IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_flags ADD COLUMN citation VARCHAR(255) NULL DEFAULT NULL AFTER reason' ); }
		catch ( \Throwable ) {}

		$vestigial = [
			'distributor_id', 'flag_type', 'flag_value', 'source', 'status',
			'first_seen_at', 'last_confirmed_at', 'removed_by_dist_at',
			'admin_reviewed_by', 'admin_reviewed_at', 'listing_id',
		];
		foreach ( $vestigial as $col )
		{
			try { \IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_flags DROP COLUMN ' . $col ); }
			catch ( \Throwable ) {}
		}

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
			try { \IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_roster ADD COLUMN ' . $col . ' ' . $type ); }
			catch ( \Throwable ) {}
		}
		try { \IPS\Db::i()->update( 'gd_compliance_roster', [ 'roster_state' => 'CA' ], [ "roster_state='' OR roster_state IS NULL" ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->update( 'gd_compliance_roster', [ 'list_type'    => 'approved' ], [ "list_type='' OR list_type IS NULL" ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->query( "ALTER TABLE " . $prefix . "gd_compliance_review ADD COLUMN roster_state CHAR(2) NOT NULL DEFAULT 'CA' AFTER upc, ADD KEY idx_state (roster_state)" ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->update( 'gd_compliance_review', [ 'roster_state' => 'CA' ], [ "roster_state='' OR roster_state IS NULL" ] ); } catch ( \Throwable ) {}
		try
		{
			\IPS\Db::i()->query(
				'ALTER TABLE ' . $prefix . 'gd_compliance_overrides '
				. "MODIFY action VARCHAR(20) NOT NULL DEFAULT 'force_restrict'"
			);
		}
		catch ( \Throwable ) {}

		try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $prefix . "gd_compliance_flags_stage" ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $prefix . "gd_compliance_flags_old" ); } catch ( \Throwable ) {}

		/* ============================================================
		 * (4) NON-DESTRUCTIVE SEEDS — capacity rules (Seeder) + AWB
		 * rules + AWB models (AwbModels).
		 * ============================================================ */

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Seeder.php';
			\IPS\gdcompliance\Seeder::seedMissingRules();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10600 seedMissingRules: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/AwbModels.php';

			$rc = \IPS\gdcompliance\AwbModels::seedMissingRules();
			try { \IPS\Log::log( 'upg_10600 awb rules seed: ' . (int) $rc['inserted'] . ' inserted, ' . (int) $rc['skipped'] . ' skipped, ' . (int) $rc['failed'] . ' failed', 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}

			$mc = \IPS\gdcompliance\AwbModels::seedMissingModels();
			try { \IPS\Log::log( 'upg_10600 awb models seed: ' . (int) $mc['inserted'] . ' inserted, ' . (int) $mc['skipped'] . ' skipped, ' . (int) $mc['failed'] . ' failed', 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10600 AwbModels seed: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * (5) SETTINGS SEED (Phase 3 URLs + Phase 5 front toggles).
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
		 * (6) LANG RESEED.
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

		/* Clean stale menu key for the removed picamodels controller. */
		try { \IPS\Db::i()->delete( 'core_sys_lang_words', [ 'word_app=? AND word_key=?', 'gdcompliance', 'menu__gdcompliance_compliance_picamodels' ] ); }
		catch ( \Throwable ) {}

		/* ============================================================
		 * (7) CACHE / OPCACHE + canonical_templates purge.
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
