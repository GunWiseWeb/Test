<?php
/**
 * @brief  GD Compliance — upgrade 1.2.1 (Phase 3 expansion: MD auto PDFs + disapproved + as_of_date)
 *
 * Self-contained per rule #79. Covers everything since v1.1.0:
 *   - Rename gd_compliance_ca_roster → gd_compliance_roster (v1.2.0 step)
 *   - Add roster_state, blanket, date_approved (v1.2.0)
 *   - Add list_type, blanket_caliber, source_label, as_of_date, source (v1.2.1)
 *   - Backfill roster_state='CA', list_type='approved' on rows from v1.1.0
 *   - Add roster_state to gd_compliance_review + backfill
 *   - Seed new settings (gdcompliance_md_roster_url, _md_disapproved_url,
 *     _ma_roster_url, _dc_derive)
 *   - Re-seed lang
 *   - Cache clear + opcache
 *
 * All ALTERs guarded (information_schema check + \Throwable catch) so
 * re-runs are no-ops. Never auto-fetches any roster.
 */

namespace IPS\gdcompliance\setup\upg_10201;

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
		   1.1.0 table name is still present. */
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
				/* Fresh from 1.0.0 → 1.2.1 with no intermediate step. Create
				   the current shape directly. */
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
			try { \IPS\Log::log( 'upg_10201 RENAME/CREATE roster: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (2) Guarded ALTERs — all v1.2.0 + v1.2.1 columns. */
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
				try { \IPS\Log::log( 'upg_10201 ALTER roster.' . $col . ': ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
			}
		}

		/* (3) Backfill roster_state='CA' + list_type='approved' on legacy rows. */
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
			try { \IPS\Log::log( 'upg_10201 ALTER review.roster_state: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}
		try { \IPS\Db::i()->update( 'gd_compliance_review', [ 'roster_state' => 'CA' ], [ "roster_state='' OR roster_state IS NULL" ] ); } catch ( \Throwable ) {}

		/* (5) Seed the four settings. */
		foreach ( [
			[ 'gdcompliance_ma_roster_url',      'https://www.mass.gov/doc/approved-handgun-roster-april-2026/download', 'none' ],
			[ 'gdcompliance_md_roster_url',      'https://dlslibrary.state.md.us/publications/Exec/MDSP/PS5-405(a)_2026(1).pdf', 'none' ],
			[ 'gdcompliance_md_disapproved_url', 'https://mdsp.maryland.gov/media/594', 'none' ],
			[ 'gdcompliance_dc_derive',          '1', 'full' ],
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
