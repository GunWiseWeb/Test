<?php
/**
 * @brief  GD Compliance — upgrade 1.6.10
 *
 * Landing:
 *   1. Broadened Lowers classifier — cat154 lowers now flag by default;
 *      only clear signals of a non-AWB action (bolt/lever/pump/rimfire)
 *      route to review. Ships in sources/Lowers.php.
 *   2. Fixed magazine-by-caliber classification — a bare cat38 mag is
 *      classified from its caliber and compared against the state's
 *      handgun / rifle / shotgun limit (fixes v1.6.9 IL-5 bug where
 *      30-rd 5.56 mag was compared to the shotgun limit 5).
 *   3. New gd_compliance_lowers curated-override table (guarded
 *      CREATE + defensive re-run: on install/upgrade).
 *   4. Two new ACP controllers: modules/admin/compliance/lowers.php
 *      (Lowers & Receivers dashboard + curated CRUD + test box) and
 *      modules/admin/compliance/magazines.php (over-capacity mag
 *      monitor with "Set override" per-row action). Registered in
 *      data/acpmenu.json.
 *
 * Guarded schema (checkForTable before creating). Idempotent — safe
 * to re-run on partial-crash. NO auto-compute — Derrick recomputes
 * via CLI to re-flag lowers with broadened logic + corrected mag
 * limits.
 */

namespace IPS\gdcompliance\setup\upg_10610;

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
		/* ---------- Guarded CREATE gd_compliance_lowers ---------- */
		$hasTable = FALSE;
		try
		{
			$hasTable = (bool) \IPS\Db::i()->checkForTable( 'gd_compliance_lowers' );
		}
		catch ( \Throwable )
		{
			$hasTable = FALSE;
		}

		if ( !$hasTable )
		{
			try
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_compliance_lowers',
					'columns' => [
						'id' => [
							'name' => 'id', 'type' => 'INT', 'length' => 10, 'decimals' => null,
							'allow_null' => FALSE, 'default' => null, 'auto_increment' => TRUE,
							'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '',
						],
						'pattern' => [
							'name' => 'pattern', 'type' => 'VARCHAR', 'length' => 191, 'decimals' => null,
							'allow_null' => FALSE, 'default' => '', 'auto_increment' => FALSE,
							'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => 'title/model/MPN/UPC substring, case-insensitive',
						],
						'platform' => [
							'name' => 'platform', 'type' => 'VARCHAR', 'length' => 40, 'decimals' => null,
							'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE,
							'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => 'AR-15|AK|AR-10|... optional',
						],
						'action' => [
							'name' => 'action', 'type' => 'VARCHAR', 'length' => 20, 'decimals' => null,
							'allow_null' => FALSE, 'default' => 'force_flag', 'auto_increment' => FALSE,
							'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => 'force_flag|force_clear|review',
						],
						'note' => [
							'name' => 'note', 'type' => 'VARCHAR', 'length' => 255, 'decimals' => null,
							'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE,
							'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '',
						],
						'created_at' => [
							'name' => 'created_at', 'type' => 'INT', 'length' => 10, 'decimals' => null,
							'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE,
							'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '',
						],
					],
					'indexes' => [
						'PRIMARY'    => [ 'type' => 'primary', 'name' => 'PRIMARY',    'length' => [ null ],  'columns' => [ 'id' ] ],
						'uq_pattern' => [ 'type' => 'unique',  'name' => 'uq_pattern', 'length' => [ 191 ],   'columns' => [ 'pattern' ] ],
						'idx_action' => [ 'type' => 'key',     'name' => 'idx_action', 'length' => [ null ],  'columns' => [ 'action' ] ],
					],
				] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10610 createTable gd_compliance_lowers: ' . $e->getMessage(), 'gdcompliance_upg_10610' ); }
				catch ( \Throwable ) {}
				return FALSE;
			}
		}

		/* ---------- Lang seed for the two new ACP pages ---------- */
		$newStrings = [
			'menu__gdcompliance_compliance_lowers'    => 'Lowers & Receivers',
			'menu__gdcompliance_compliance_magazines' => 'Magazines',

			'gdcompliance_acp_lowers_title'           => 'Lowers & Receivers',
			'gdcompliance_acp_lowers_intro'           => 'AR/AK-pattern LOWER RECEIVERS are the serialized firearm — treated as an assault-weapon component in the rifle-class AWB states. cat154 flags by default; cat69 is title-gated; bolt/lever/rimfire lowers route to review; parts and uppers are excluded. Add curated overrides below to force-flag or force-clear specific patterns.',
			'gdcompliance_acp_lowers_test'            => 'Test a UPC against the classifier',
			'gdcompliance_acp_lowers_curated'         => 'Curated overrides',
			'gdcompliance_acp_lowers_curated_intro'   => 'Each row is a title/model/MPN/UPC substring, case-insensitive. Curated matches WIN over auto logic. force_flag → always flag. force_clear → never flag. review → route to review queue.',
			'gdcompliance_acp_lowers_add'             => 'Add curated entry',
			'gdcompliance_acp_lowers_col_pattern'     => 'Pattern',
			'gdcompliance_acp_lowers_col_platform'    => 'Platform',
			'gdcompliance_acp_lowers_col_action'      => 'Action',
			'gdcompliance_acp_lowers_col_note'        => 'Note',
			'gdcompliance_lowers_f_pattern'           => 'Pattern (case-insensitive substring, matched against title/brand/model/MPN/UPC)',
			'gdcompliance_lowers_f_platform'          => 'Platform label (e.g. AR-15, AK) — optional',
			'gdcompliance_lowers_f_action'            => 'Action',
			'gdcompliance_lowers_f_note'              => 'Note (shown in the tester, not in flag reasons) — optional',

			'gdcompliance_acp_mag_title'              => 'Magazines',
			'gdcompliance_acp_mag_intro'              => "Standalone MAGAZINE flags (cat38) — a bare LCM classified by caliber and compared against the state's handgun / rifle / shotgun limit. Curation is via the per-UPC override list (Overrides) — this page provides the quick \"Set override\" link for any mis-flagged magazine.",
			'gdcompliance_acp_mag_override'           => 'Set override',
		];
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
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

		/* Warm the Lowers helper so any admin request in the same PHP
		   process picks up the new class without an extra lookup. Non-
		   fatal if the file isn't reachable. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Lowers.php';
			\IPS\gdcompliance\Lowers::clearCache();
		}
		catch ( \Throwable ) {}

		/* ---------- Cache purges (acpmenu now has two new entries) ---------- */
		try { unset( \IPS\Data\Store::i()->acpmenu ); }            catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
