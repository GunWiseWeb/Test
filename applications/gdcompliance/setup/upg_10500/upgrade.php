<?php
/**
 * @brief  GD Compliance — upgrade 1.5.0 (CONSOLIDATION)
 *
 * A single self-contained upgrade that brings ANY prior 1.x install to
 * the canonical 1.5.0 baseline without assuming a specific starting
 * point. All ALTER / CREATE / DROP operations are idempotent (guarded
 * on information_schema + per-step \Throwable catch) so re-runs no-op.
 *
 * What it does:
 *
 *   (1) CREATE IF NOT EXISTS every canonical table at the correct
 *       column widths so a fresh 1.4.x → 1.5.0 hop that skipped some
 *       Phase-3/4 migrations still lands with the right schema.
 *
 *   (2) FIX the "compute reports N flags but writes 0" bug — an early
 *       draft of gd_compliance_flags had distributor-feed columns
 *       (distributor_id, flag_type, flag_value, source, status,
 *       first_seen_at, last_confirmed_at, removed_by_dist_at,
 *       admin_reviewed_by, admin_reviewed_at, listing_id) NOT NULL with
 *       no default. Later refactors dropped every reference to those
 *       columns from the code but the LIVE tables kept them, so the
 *       Engine's clean 8-column INSERT would throw "Field 'X' doesn't
 *       have a default value" and silently write zero rows. We DROP
 *       each vestigial column, guarded — safe because grep confirms
 *       zero code references remain.
 *
 *   (3) Guarded MODIFY of gd_compliance_overrides.action to VARCHAR(20)
 *       (was VARCHAR(12) with 14-char default — MySQL error 1067). Both
 *       schema.json and the CREATE below already use VARCHAR(20) — this
 *       covers any live table that still has the narrow column.
 *
 *   (4) Non-destructive rule reseed via Seeder — brings partial 1.3.x
 *       installs back to the full 19-row canonical set WITHOUT touching
 *       any admin edits. Rules are PERMANENT reference data — never
 *       truncated or deleted here (or anywhere).
 *
 *   (5) Phase-3 settings seed (MA/MD roster URLs + DC derive) and
 *       Phase-5 settings seed (front_enabled / show_reasons / disclaimer).
 *
 *   (6) Lang reseed + cache/canonical_templates purge + opcache reset.
 *
 *   (7) Cleanup: drop any stray gd_compliance_flags_stage / _old table
 *       left by an interrupted crash-safe compute run.
 *
 * NEVER truncates gd_compliance_rules or gd_compliance_overrides.
 * NEVER auto-runs computeFlags (Derrick clicks it manually).
 */

namespace IPS\gdcompliance\setup\upg_10500;

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
		 * (1a) CREATE IF NOT EXISTS gd_compliance_rules
		 * ============================================================ */
		try
		{
			\IPS\Db::i()->query( "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_rules (
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
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10500 CREATE rules: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * (1b) CREATE IF NOT EXISTS gd_compliance_flags — canonical
		 * clean 8-column shape. Live installs may have distributor
		 * vestigials; those get dropped in step (2) below.
		 * ============================================================ */
		try
		{
			\IPS\Db::i()->query( "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_flags (
				id              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				upc             VARCHAR(50) NOT NULL DEFAULT '',
				state_code      CHAR(2) NOT NULL DEFAULT '',
				firearm_type    VARCHAR(20) NOT NULL DEFAULT 'all',
				parsed_capacity INT(10) UNSIGNED NULL DEFAULT NULL,
				rule_id         INT(10) UNSIGNED NOT NULL DEFAULT 0,
				reason          VARCHAR(255) NULL DEFAULT NULL,
				computed_at     INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (id),
				KEY idx_upc (upc),
				KEY idx_state (state_code),
				KEY idx_upc_state (upc, state_code)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10500 CREATE flags: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * (1c) CREATE IF NOT EXISTS gd_compliance_roster
		 * ============================================================ */
		try
		{
			\IPS\Db::i()->query( "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_roster (
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
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10500 CREATE roster: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * (1d) CREATE IF NOT EXISTS gd_compliance_review
		 * ============================================================ */
		try
		{
			\IPS\Db::i()->query( "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_review (
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
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10500 CREATE review: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * (1e) CREATE IF NOT EXISTS gd_compliance_overrides (VARCHAR(20)
		 * for action — the 1067 fix baked in at CREATE).
		 * ============================================================ */
		try
		{
			\IPS\Db::i()->query( "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_overrides (
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
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10500 CREATE overrides: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* Also cover gd_compliance_unparsed (stats table). */
		try
		{
			\IPS\Db::i()->query( "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_unparsed (
				id             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				capacity_value VARCHAR(100) NOT NULL DEFAULT '',
				count          INT(10) UNSIGNED NOT NULL DEFAULT 0,
				updated_at     INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		}
		catch ( \Throwable ) {}

		/* ============================================================
		 * (2) DROP VESTIGIAL DISTRIBUTOR-FEED COLUMNS from
		 * gd_compliance_flags.
		 *
		 * These columns come from an early draft of gd_compliance_flags
		 * that treated the table as a distributor-feed queue. Later
		 * refactors made it a computed per-(upc, state) restriction
		 * store, but the LIVE tables kept the old columns. Several were
		 * declared NOT NULL with no default, so Engine::computeFlags's
		 * clean 8-column INSERT throws "Field '...' doesn't have a
		 * default value" and silently writes zero rows. Grep confirms
		 * ZERO references to any of these columns anywhere in the
		 * codebase — dropping is safe.
		 * ============================================================ */
		$vestigialFlagCols = [
			'distributor_id',
			'flag_type',
			'flag_value',
			'source',
			'status',
			'first_seen_at',
			'last_confirmed_at',
			'removed_by_dist_at',
			'admin_reviewed_by',
			'admin_reviewed_at',
			'listing_id',
		];
		foreach ( $vestigialFlagCols as $col )
		{
			try
			{
				$has = false;
				try
				{
					$has = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.COLUMNS', [
						'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
						$prefix . 'gd_compliance_flags', $col,
					] )->first();
				}
				catch ( \Throwable ) {}

				if ( $has )
				{
					try
					{
						\IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_flags DROP COLUMN ' . $col );
					}
					catch ( \Throwable $e )
					{
						/* Fallback: if the DROP errors (e.g. an index depends on it),
						   at least make the column nullable so INSERTs succeed. */
						try
						{
							\IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_flags MODIFY ' . $col . ' TEXT NULL DEFAULT NULL' );
						}
						catch ( \Throwable ) {}
						try { \IPS\Log::log( 'upg_10500 DROP flags.' . $col . ' fell back to nullable: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10500 DROP flags.' . $col . ': ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
			}
		}

		/* ============================================================
		 * (3) Phase 3 columns on gd_compliance_roster / review.
		 * Guarded ALTERs for any partial-install state.
		 * ============================================================ */
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
				try { \IPS\Log::log( 'upg_10500 ALTER roster.' . $col . ': ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
			}
		}
		try { \IPS\Db::i()->update( 'gd_compliance_roster', [ 'roster_state' => 'CA' ], [ "roster_state='' OR roster_state IS NULL" ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->update( 'gd_compliance_roster', [ 'list_type'    => 'approved' ], [ "list_type='' OR list_type IS NULL" ] ); } catch ( \Throwable ) {}

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
			try { \IPS\Log::log( 'upg_10500 ALTER review.roster_state: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}
		try { \IPS\Db::i()->update( 'gd_compliance_review', [ 'roster_state' => 'CA' ], [ "roster_state='' OR roster_state IS NULL" ] ); } catch ( \Throwable ) {}

		/* ============================================================
		 * (3b) Guarded MODIFY overrides.action → VARCHAR(20) for any
		 * live table still at the 1.3.0-era VARCHAR(12) width.
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
			try { \IPS\Log::log( 'upg_10500 MODIFY overrides.action: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* Cleanup any stray staging table from an interrupted compute run. */
		try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $prefix . "gd_compliance_flags_stage" ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $prefix . "gd_compliance_flags_old" ); } catch ( \Throwable ) {}

		/* ============================================================
		 * (4) NON-DESTRUCTIVE RULE RESEED via Seeder.
		 * Existing rules (including admin edits) are preserved; only
		 * missing (state, firearm_type) pairs are inserted.
		 * ============================================================ */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Seeder.php';
			\IPS\gdcompliance\Seeder::seedMissingRules();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10500 seedMissingRules: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * (5) SETTINGS SEED — Phase 3 URLs + Phase 5 front toggles.
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
		 * (6) LANG RESEED — 6-column schema (rule #43).
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
		 * (7) CACHE / OPCACHE.
		 * ============================================================ */
		try { unset( \IPS\Data\Store::i()->settings ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpmenu ); }              catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->widgets ); }              catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                    catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                    catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
