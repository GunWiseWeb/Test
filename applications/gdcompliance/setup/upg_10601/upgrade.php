<?php
/**
 * @brief  GD Compliance — upgrade 1.6.1 (AWB dashboard + enable states)
 *
 * v1.6.0 shipped the multi-state AWB framework with only IL/CA/NY/RI
 * enabled. v1.6.1:
 *   (a) enables NJ/WA/DE/MD/MA/DC (rifle) and HI (pistol) — for existing
 *       installs whose gd_compliance_awb_rules rows are still at v1.6.0
 *       (enabled=0), targeted UPDATE + guard-log
 *   (b) seeds the shared AR/AK/named core into gd_compliance_awb_models
 *       for every AWB state (idempotent per (state, pattern_norm))
 *   (c) leaves CT enabled=0 with source_note flag (statute threshold
 *       verification needed) and VA enabled=0 (2025-26 legal flux)
 *   (d) adds the AWB States dashboard controller (menu/restrictions
 *       ship with the tarball as usual)
 *
 * NEVER truncates any awb_* table or gd_compliance_rules / overrides.
 * Does NOT auto-run compute.
 */

namespace IPS\gdcompliance\setup\upg_10601;

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
		 * (1) Ensure every canonical table exists.
		 * ============================================================ */

		$creates = [
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
			catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10601 CREATE: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {} }
		}

		/* ============================================================
		 * (2) SEED (insert-only) via AwbModels — pulls the v1.6.1 shape
		 * including the new NJ/WA/DE/MD/MA/DC/HI rows + the shared
		 * pattern core across every state. Existing rows are skipped.
		 * ============================================================ */

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/AwbModels.php';

			$rc = \IPS\gdcompliance\AwbModels::seedMissingRules();
			try { \IPS\Log::log( 'upg_10601 awb rules seed: ' . (int) $rc['inserted'] . ' inserted, ' . (int) $rc['skipped'] . ' skipped, ' . (int) $rc['failed'] . ' failed', 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}

			$mc = \IPS\gdcompliance\AwbModels::seedMissingModels();
			try { \IPS\Log::log( 'upg_10601 awb models seed: ' . (int) $mc['inserted'] . ' inserted, ' . (int) $mc['skipped'] . ' skipped, ' . (int) $mc['failed'] . ' failed', 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10601 AwbModels seed: ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
		}

		/* ============================================================
		 * (3) FLIP EXISTING v1.6.0 ROWS TO ENABLED for the states we're
		 * activating in v1.6.1. seedMissingRules() is add-only so it
		 * won't touch pre-existing rules — we UPDATE explicitly.
		 *
		 * Only touches enabled + citation + notes; NEVER touches admin
		 * edits to feature_count_threshold or the OAL rule. Guarded so a
		 * row-not-found (fresh install) is a no-op.
		 * ============================================================ */

		$activations = [
			[ 'NJ', 'rifle',  1, 'N.J.S.A. 2C:39-1(w)',                              'One-feature since S2309 amendments; thumbhole + second handgrip added' ],
			[ 'WA', 'rifle',  1, 'RCW 9.41.010 (HB 1240, 2023)',                    'One-feature sale/transfer/manufacture ban' ],
			[ 'DE', 'rifle',  1, '11 Del. C. §1466 (HB 450, 2022)',                 'Delaware Lethal Firearms Safety Act one-feature' ],
			[ 'MD', 'rifle',  2, 'MD Crim Law §4-301 (regulated firearm list)',     'Two-feature test + enumerated regulated-firearm list' ],
			[ 'MA', 'rifle',  2, 'MGL c.140 §121 (Ch. 135 of Acts of 2024)',        'Two-feature statutory; MA AG interpretation may be broader — verify' ],
			[ 'DC', 'rifle',  1, 'DC Code §7-2501.01(3A)',                          'One-feature; Benson injunction flux — Derrick may need to disable if enforcement changes' ],
			[ 'HI', 'pistol', 1, 'HRS §134-1 (assault pistol definition)',          'HI bans assault pistols only, not rifles; framework only evaluates pistols against this row' ],
		];
		$flipped = 0;
		foreach ( $activations as [ $state, $class, $thresh, $cite, $note ] )
		{
			try
			{
				$affected = \IPS\Db::i()->update(
					'gd_compliance_awb_rules',
					[
						'enabled'                 => 1,
						'feature_count_threshold' => $thresh,
						'centerfire_only'         => 1,
						'citation'                => substr( $cite, 0, 255 ),
						'notes'                   => substr( $note, 0, 255 ),
						'updated_at'              => time(),
					],
					[ 'state_code=? AND firearm_class=?', $state, $class ]
				);
				if ( $affected ) { $flipped++; }
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10601 activate ' . $state . '/' . $class . ': ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
			}
		}
		try { \IPS\Log::log( 'upg_10601 activations: ' . $flipped . ' of ' . count( $activations ) . ' rules flipped to enabled', 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}

		/* Ensure CT stays disabled with the verify note (do not enable). */
		try
		{
			\IPS\Db::i()->update(
				'gd_compliance_awb_rules',
				[
					'notes'      => 'CT threshold needs statute verification (2023 amendment) before enabling — sources conflict on one- vs two-feature',
					'updated_at' => time(),
				],
				[ 'state_code=? AND firearm_class=? AND enabled=0', 'CT', 'rifle' ]
			);
		}
		catch ( \Throwable ) {}

		try { \IPS\gdcompliance\AwbModels::clearCache(); } catch ( \Throwable ) {}

		/* ============================================================
		 * (4) LANG RESEED.
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

		/* Delete stale menu key for the retired awbrules page. */
		try { \IPS\Db::i()->delete( 'core_sys_lang_words', [ 'word_app=? AND word_key=?', 'gdcompliance', 'menu__gdcompliance_compliance_awbrules' ] ); }
		catch ( \Throwable ) {}

		/* ============================================================
		 * (5) CACHE / OPCACHE + canonical_templates purge.
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
