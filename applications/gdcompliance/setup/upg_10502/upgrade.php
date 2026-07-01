<?php
/**
 * @brief  GD Compliance — upgrade 1.5.2 (Illinois PICA assault-weapon detection)
 *
 * Adds the PICA (720 ILCS 5/24-1.9) two-tier detection layer for rifles:
 *
 *   (1) CREATE gd_compliance_pica_models — editable named-model list
 *       for Tier 1 detection. Guarded CREATE IF NOT EXISTS.
 *   (2) NON-DESTRUCTIVE seed of the statutory (a)(1)(J) list from
 *       PicaModels::seedMissingModels() — admin edits preserved.
 *   (3) Lang reseed + cache purge.
 *
 * Also carries every migration from v1.5.1's upg_10501 forward so any
 * prior 1.x install lands with the canonical schema (CREATE IF NOT
 * EXISTS all 6 canonical tables + guarded vestigial-column DROPs +
 * Phase-3/4/5 settings + Seeder + lang).
 *
 * NEVER truncates gd_compliance_rules, gd_compliance_overrides, or
 * gd_compliance_pica_models. Does NOT auto-run compute.
 */

namespace IPS\gdcompliance\setup\upg_10502;

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
		 * (1) Ensure every canonical table exists at the correct width.
		 * ============================================================ */

		$creates = [
			'gd_compliance_rules' => "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_rules (
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

			'gd_compliance_flags' => "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_flags (
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
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			'gd_compliance_overrides' => "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_overrides (
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

			'gd_compliance_review' => "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_review (
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

			'gd_compliance_roster' => "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_roster (
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

			'gd_compliance_pica_models' => "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_pica_models (
				id             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				pattern        VARCHAR(120) NOT NULL DEFAULT '',
				pattern_norm   VARCHAR(120) NOT NULL DEFAULT '',
				platform_group VARCHAR(40) NOT NULL DEFAULT '',
				citation       VARCHAR(255) NULL DEFAULT NULL,
				enabled        TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
				updated_at     INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_norm (pattern_norm),
				KEY idx_enabled (enabled)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

			'gd_compliance_unparsed' => "CREATE TABLE IF NOT EXISTS " . $prefix . "gd_compliance_unparsed (
				id             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
				capacity_value VARCHAR(100) NOT NULL DEFAULT '',
				count          INT(10) UNSIGNED NOT NULL DEFAULT 0,
				updated_at     INT(10) UNSIGNED NULL DEFAULT NULL,
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
		];
		foreach ( $creates as $sql )
		{
			try { \IPS\Db::i()->query( $sql ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * (2) DROP vestigial distributor-feed columns unconditionally.
		 * ============================================================ */
		$vestigial = [
			'distributor_id', 'flag_type', 'flag_value', 'source', 'status',
			'first_seen_at', 'last_confirmed_at', 'removed_by_dist_at',
			'admin_reviewed_by', 'admin_reviewed_at', 'listing_id',
		];
		foreach ( $vestigial as $col )
		{
			try { \IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_flags DROP COLUMN ' . $col ); }
			catch ( \Throwable ) { /* likely doesn't exist — desired */ }
		}

		/* ============================================================
		 * (3) Guarded Phase-3 ALTERs.
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
			try { \IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_roster ADD COLUMN ' . $col . ' ' . $type ); }
			catch ( \Throwable ) {}
		}
		try { \IPS\Db::i()->update( 'gd_compliance_roster', [ 'roster_state' => 'CA' ], [ "roster_state='' OR roster_state IS NULL" ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->update( 'gd_compliance_roster', [ 'list_type'    => 'approved' ], [ "list_type='' OR list_type IS NULL" ] ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->query( "ALTER TABLE " . $prefix . "gd_compliance_review ADD COLUMN roster_state CHAR(2) NOT NULL DEFAULT 'CA' AFTER upc, ADD KEY idx_state (roster_state)" ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->update( 'gd_compliance_review', [ 'roster_state' => 'CA' ], [ "roster_state='' OR roster_state IS NULL" ] ); } catch ( \Throwable ) {}

		/* v1.3.1 fix — widen overrides.action if narrow. */
		try
		{
			\IPS\Db::i()->query(
				'ALTER TABLE ' . $prefix . 'gd_compliance_overrides '
				. "MODIFY action VARCHAR(20) NOT NULL DEFAULT 'force_restrict'"
			);
		}
		catch ( \Throwable ) {}

		/* Cleanup stray staging tables. */
		try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $prefix . "gd_compliance_flags_stage" ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $prefix . "gd_compliance_flags_old" ); } catch ( \Throwable ) {}

		/* ============================================================
		 * (4) NON-DESTRUCTIVE Seeder calls — capacity rules + PICA models.
		 * Both are per-row existence-checked; existing admin edits kept.
		 * ============================================================ */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Seeder.php';
			\IPS\gdcompliance\Seeder::seedMissingRules();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10502 seedMissingRules: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/PicaModels.php';
			$picaCounts = \IPS\gdcompliance\PicaModels::seedMissingModels();
			try { \IPS\Log::log( 'upg_10502 PICA seed: ' . (int) $picaCounts['inserted'] . ' inserted, ' . (int) $picaCounts['skipped'] . ' skipped, ' . (int) $picaCounts['failed'] . ' failed', 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10502 seedMissingModels: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
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
