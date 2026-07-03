<?php
/**
 * @brief  GD Compliance — upgrade 1.6.11
 *
 * ACP-display-only change: rebuild "Lowers & Receivers" page to match
 * the AWB master-list look. Three sections now:
 *   1. Title + auto-summary + AWB state badge strip (informational —
 *      lowers apply uniformly across the enabled rifle-class states).
 *   2. Flagged Lower Receivers table (distinct UPCs joined to gd_catalog
 *      via IPS-native select+join, GROUP BY upc).
 *   3. Curated overrides Table\Db (existing v1.6.10 CRUD, restyled).
 *
 * No engine changes, no schema changes, no recompute needed.
 *
 * DEFENSIVE — because this replaces the sole upg dir per rule #79, if
 * Derrick jumps from 1.6.9 directly to 1.6.11 we still need to land the
 * v1.6.10 landing (gd_compliance_lowers table + lang keys). Guarded
 * checkForTable + idempotent lang replace() cover both paths.
 */

namespace IPS\gdcompliance\setup\upg_10611;

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
		/* ---------- Guarded CREATE gd_compliance_lowers (v1.6.10 legacy) ---------- */
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
						'id'         => [ 'name' => 'id', 'type' => 'INT', 'length' => 10, 'decimals' => null, 'allow_null' => FALSE, 'default' => null, 'auto_increment' => TRUE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'pattern'    => [ 'name' => 'pattern', 'type' => 'VARCHAR', 'length' => 191, 'decimals' => null, 'allow_null' => FALSE, 'default' => '', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => 'title/model/MPN/UPC substring, case-insensitive' ],
						'platform'   => [ 'name' => 'platform', 'type' => 'VARCHAR', 'length' => 40, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'action'     => [ 'name' => 'action', 'type' => 'VARCHAR', 'length' => 20, 'decimals' => null, 'allow_null' => FALSE, 'default' => 'force_flag', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'note'       => [ 'name' => 'note', 'type' => 'VARCHAR', 'length' => 255, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'created_at' => [ 'name' => 'created_at', 'type' => 'INT', 'length' => 10, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
					],
					'indexes' => [
						'PRIMARY'    => [ 'type' => 'primary', 'name' => 'PRIMARY',    'length' => [ null ], 'columns' => [ 'id' ] ],
						'uq_pattern' => [ 'type' => 'unique',  'name' => 'uq_pattern', 'length' => [ 191 ],  'columns' => [ 'pattern' ] ],
						'idx_action' => [ 'type' => 'key',     'name' => 'idx_action', 'length' => [ null ], 'columns' => [ 'action' ] ],
					],
				] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10611 createTable gd_compliance_lowers: ' . $e->getMessage(), 'gdcompliance_upg_10611' ); }
				catch ( \Throwable ) {}
				return FALSE;
			}
		}

		/* ---------- Lang seed — v1.6.10 keys + v1.6.11 new keys ---------- */
		$newStrings = [
			/* v1.6.10 — Lowers ACP (idempotent replace) */
			'menu__gdcompliance_compliance_lowers'    => 'Lowers & Receivers',
			'menu__gdcompliance_compliance_magazines' => 'Magazines',
			'gdcompliance_acp_lowers_title'           => 'Lowers & Receivers',
			'gdcompliance_acp_lowers_intro'           => 'AR/AK-pattern LOWER RECEIVERS are the serialized firearm — treated as an assault-weapon component in the rifle-class AWB states. cat154 flags by default; cat69 is title-gated; bolt/lever/rimfire lowers route to review; parts and uppers are excluded. Add curated overrides below to force-flag or force-clear specific patterns.',
			'gdcompliance_acp_lowers_test'            => 'Test a UPC against the classifier',
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

			/* v1.6.11 — new section labels */
			'gdcompliance_acp_lowers_flagged_title'   => 'Flagged Lower Receivers',
			'gdcompliance_acp_lowers_flagged_intro'   => 'Serialized AR/AK-pattern lower receivers currently flagged in gd_compliance_flags (firearm_type=awb_lower). One row per distinct UPC — the "States" count is how many enabled rifle-class AWB states the flag applies to.',
			'gdcompliance_acp_lowers_curated'         => 'Curated overrides (win over auto-matching)',
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

		/* Warm Lowers so any admin request in this PHP process picks up
		   the new class body without an extra lookup. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Lowers.php';
			\IPS\gdcompliance\Lowers::clearCache();
		}
		catch ( \Throwable ) {}

		/* ---------- Cache purges — acpmenu (two entries from v1.6.10) +
		   canonical_templates (compiled ACP output invalidated) ---------- */
		try { unset( \IPS\Data\Store::i()->acpmenu ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }            catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }        catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }          catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                   catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                   catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
