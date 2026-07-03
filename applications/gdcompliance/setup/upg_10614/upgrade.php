<?php
/**
 * @brief  GD Compliance — upgrade 1.6.14
 *
 * ACP-display-only: "Lowers & Receivers" page rebuilt to mirror the
 * Magazines page chrome section-for-section:
 *
 *   1. Summary ipsBox with sectionHead + 4 stat cards.
 *   2. Rifle-class AWB state badge strip (informational).
 *   3. Flagged Lower Receivers table (native select+join on
 *      gd_compliance_flags × gd_catalog, DISTINCT by upc,
 *      per-row Set-override link matching the Magazines placement).
 *   4. Per-UPC test box (preserved).
 *   5. Curated overrides Table\Db (preserved).
 *
 * No matching-logic changes, no schema changes, no recompute
 * required. Reads existing awb_lower flags at render time.
 *
 * DEFENSIVE — because this replaces the sole upg dir per rule #79,
 * carries the v1.6.10 gd_compliance_lowers CREATE, the v1.6.12
 * gd_compliance_review.review_type ADD + backfill, and the v1.6.13
 * tandemkross force_clear seed forward for any install skipping
 * intermediate versions.
 */

namespace IPS\gdcompliance\setup\upg_10614;

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
		/* ---------- Defensive gd_compliance_lowers CREATE (v1.6.10) ---------- */
		$hasLowers = FALSE;
		try
		{
			$hasLowers = (bool) \IPS\Db::i()->checkForTable( 'gd_compliance_lowers' );
		}
		catch ( \Throwable ) { $hasLowers = FALSE; }
		if ( !$hasLowers )
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
			catch ( \Throwable ) { /* non-fatal — the page still renders empty */ }
		}

		/* ---------- Defensive gd_compliance_review.review_type (v1.6.12) ---------- */
		$hasCol = FALSE;
		try
		{
			$hasCol = (bool) \IPS\Db::i()->checkForColumn( 'gd_compliance_review', 'review_type' );
		}
		catch ( \Throwable ) { $hasCol = FALSE; }
		if ( !$hasCol )
		{
			try
			{
				\IPS\Db::i()->addColumn( 'gd_compliance_review', [
					'name'           => 'review_type',
					'type'           => 'VARCHAR',
					'length'         => 20,
					'decimals'       => null,
					'allow_null'     => FALSE,
					'default'        => 'roster',
					'auto_increment' => FALSE,
					'binary'         => FALSE,
					'unsigned'       => FALSE,
					'zerofill'       => FALSE,
					'values'         => [],
					'comment'        => 'roster|awb_firearm|lower|magazine — the review category',
				] );

				/* Backfill only on fresh add. */
				$backfills = [
					[ 'awb_firearm', "suggested_status LIKE 'awb\\_review\\_%'" ],
					[ 'awb_firearm', "suggested_status LIKE 'awb\\_tier2\\_%'" ],
					[ 'awb_firearm', "suggested_status LIKE 'awb\\_%' AND review_type='roster'" ],
					[ 'lower',       "suggested_status LIKE 'lower\\_%'" ],
					[ 'magazine',    "suggested_status LIKE 'magazine\\_%'" ],
					[ 'roster',      "suggested_status='unmatched_review'" ],
				];
				foreach ( $backfills as [ $type, $whereFrag ] )
				{
					try
					{
						\IPS\Db::i()->update( 'gd_compliance_review',
							[ 'review_type' => $type ],
							[ $whereFrag . ' AND review_type<>?', $type ] );
					}
					catch ( \Throwable ) {}
				}
			}
			catch ( \Throwable ) {}
		}

		/* ---------- Defensive tandemkross force_clear seed (v1.6.13) ---------- */
		try
		{
			$existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_lowers',
				[ 'pattern=?', 'tandemkross' ] )->first();
			if ( $existing === 0 )
			{
				\IPS\Db::i()->insert( 'gd_compliance_lowers', [
					'pattern'    => 'tandemkross',
					'platform'   => null,
					'action'     => 'force_clear',
					'note'       => 'Ruger .22 pistol/rimfire lower maker (Cthulhu, etc.) — not AWB. Brand-level clear supersedes per-UPC entries.',
					'created_at' => time(),
				] );
			}
		}
		catch ( \Throwable ) {}

		/* ---------- Lang seed — v1.6.14 new labels ---------- */
		$newStrings = [
			'gdcompliance_acp_lowers_states_caption' => 'AR/AK-pattern lower receivers are restricted for sale in each of these states. Lowers apply uniformly across the set (no per-state filter — a serialized AWB-pattern lower IS the assault weapon).',
			'gdcompliance_acp_lowers_override'       => 'Set override',
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

		/* Warm Lowers so the acp page picks up the latest classifier
		   without an extra class load. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Lowers.php';
			\IPS\gdcompliance\Lowers::clearCache();
		}
		catch ( \Throwable ) {}

		/* ---------- Cache purges — canonical_templates for the ACP shell ---------- */
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
