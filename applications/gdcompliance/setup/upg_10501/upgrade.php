<?php
/**
 * @brief  GD Compliance — upgrade 1.5.1
 *
 * Fixes the v1.5.0 bug where the "DROP vestigial flag columns" step
 * SILENTLY didn't run on production. The bug: existence check via
 * information_schema was wrapped in a swallowed catch, so on any
 * transient info_schema failure $has stayed false and the DROP was
 * skipped. On the live server the columns were dropped by hand.
 *
 * This upgrade attempts the DROP UNCONDITIONALLY for every known
 * vestigial column, with its own \Throwable catch. If the column is
 * already absent, ALTER errors — caught silently, no-op. If present,
 * dropped. Idempotent, robust to any prior state.
 *
 * Also carries every migration from v1.5.0's upg_10500 forward so a
 * fresh v1.0.0 → v1.5.1 hop still lands with the canonical schema
 * (CREATE IF NOT EXISTS all 5 tables, guarded ALTERs, VARCHAR(20)
 * MODIFY guard, Seeder::seedMissingRules, settings seed, lang reseed,
 * cache purge).
 *
 * NEVER truncates gd_compliance_rules or gd_compliance_overrides.
 */

namespace IPS\gdcompliance\setup\upg_10501;

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
		 * (1) CREATE IF NOT EXISTS every canonical table.
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
		catch ( \Throwable ) {}

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
		catch ( \Throwable ) {}

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
		catch ( \Throwable ) {}

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
		catch ( \Throwable ) {}

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
		catch ( \Throwable ) {}

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
		 * (2) DROP VESTIGIAL DISTRIBUTOR-FEED COLUMNS — UNCONDITIONALLY.
		 *
		 * v1.5.0's version gated this behind an information_schema check
		 * that got silently swallowed on prod, so the DROP was skipped.
		 * Now: try the DROP for every known vestigial column, catch its
		 * error, count actual successes. If the column doesn't exist,
		 * MySQL errors "Can't DROP column; check that column exists" —
		 * caught, no-op. If it does exist, dropped. Idempotent.
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
		$dropped = 0;
		foreach ( $vestigialFlagCols as $col )
		{
			try
			{
				\IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_flags DROP COLUMN ' . $col );
				$dropped++;
			}
			catch ( \Throwable $e )
			{
				/* Two expected failure modes:
				 *   (a) column doesn't exist → "Can't DROP 'X'; check that column exists" — desired end state, no-op
				 *   (b) column has an index dep → fallback to nullable so INSERTs still succeed
				 * We only log (b). (a) is the happy path on re-run. */
				$msg = strtolower( $e->getMessage() );
				if ( strpos( $msg, "can't drop" ) === false && strpos( $msg, "check that column" ) === false && strpos( $msg, "unknown column" ) === false )
				{
					try
					{
						\IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_flags MODIFY ' . $col . ' TEXT NULL DEFAULT NULL' );
					}
					catch ( \Throwable ) {}
					try { \IPS\Log::log( 'upg_10501 DROP flags.' . $col . ' fell back to nullable: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
				}
			}
		}
		try { \IPS\Log::log( 'upg_10501 vestigial column drop: ' . $dropped . ' of ' . count( $vestigialFlagCols ) . ' columns dropped this run', 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}

		/* ============================================================
		 * (3) Guarded Phase-3 ALTERs + MODIFY overrides.action.
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
				\IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_roster ADD COLUMN ' . $col . ' ' . $type );
			}
			catch ( \Throwable ) { /* column probably already exists — ok */ }
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

		/* Cleanup any stray staging table from an interrupted compute run. */
		try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $prefix . "gd_compliance_flags_stage" ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $prefix . "gd_compliance_flags_old" ); } catch ( \Throwable ) {}

		/* ============================================================
		 * (4) NON-DESTRUCTIVE RULE RESEED via Seeder.
		 * ============================================================ */

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Seeder.php';
			\IPS\gdcompliance\Seeder::seedMissingRules();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10501 seedMissingRules: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * (5) SETTINGS SEED.
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
		 * (7) CACHE / OPCACHE + canonical_templates purge.
		 * ============================================================ */

		try { unset( \IPS\Data\Store::i()->settings ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpmenu ); }              catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->widgets ); }              catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); }  catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                    catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                    catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
