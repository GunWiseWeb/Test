<?php
/**
 * @brief  GD Compliance — upgrade 1.6.12
 *
 * Landing:
 *   1. Add gd_compliance_review.review_type VARCHAR(20) NOT NULL
 *      DEFAULT 'roster' (guarded via checkForColumn) — categorizes the
 *      existing ~10.5k rows into roster / awb_firearm / lower /
 *      magazine.
 *   2. Backfill review_type from suggested_status:
 *        'unmatched_review'         → 'roster'
 *        'awb_review_%' | 'awb_%'   → 'awb_firearm'
 *        'awb_tier2_%'              → 'awb_firearm'
 *        'lower_%'                  → 'lower'
 *        (anything else / null)     → keeps default 'roster'
 *   3. Rebuild review.php ACP controller with category tabs (primary
 *      filter) + adaptive header + per-category resolve actions.
 *      Roster rows keep on_roster/off_roster; awb/lower/magazine rows
 *      map to confirm_* / not_* resolutions that write force_restrict
 *      / force_clear overrides.
 *   4. Engine now sets review_type on every new insert (roster,
 *      awb_firearm, lower) — already in source; no upgrade action.
 *
 * Overrides continue to run after every pass (Override::applyAll) —
 * every resolution persists across recompute.
 *
 * DEFENSIVE — because this replaces the sole upg dir per rule #79,
 * carries the v1.6.11 gd_compliance_lowers CREATE forward for any
 * install skipping 1.6.10/1.6.11. Idempotent (checkForTable +
 * checkForColumn).
 */

namespace IPS\gdcompliance\setup\upg_10612;

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
		/* ---------- Add gd_compliance_review.review_type (guarded) ---------- */
		$hasCol = FALSE;
		try
		{
			$hasCol = (bool) \IPS\Db::i()->checkForColumn( 'gd_compliance_review', 'review_type' );
		}
		catch ( \Throwable )
		{
			$hasCol = FALSE;
		}
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
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10612 addColumn review_type: ' . $e->getMessage(), 'gdcompliance_upg_10612' ); }
				catch ( \Throwable ) {}
				return FALSE;
			}
		}

		/* ---------- Backfill review_type from suggested_status ---------- */
		$backfills = [
			[ 'awb_firearm', "suggested_status LIKE 'awb\\_review\\_%'" ],
			[ 'awb_firearm', "suggested_status LIKE 'awb\\_tier2\\_%'" ],
			[ 'awb_firearm', "suggested_status LIKE 'awb\\_%' AND review_type='roster'" ],
			[ 'lower',       "suggested_status LIKE 'lower\\_%'" ],
			[ 'magazine',    "suggested_status LIKE 'magazine\\_%'" ],
			[ 'roster',      "suggested_status='unmatched_review'" ],
		];
		$updated = 0;
		foreach ( $backfills as [ $type, $whereFrag ] )
		{
			try
			{
				\IPS\Db::i()->update(
					'gd_compliance_review',
					[ 'review_type' => $type ],
					[ $whereFrag . ' AND review_type<>?', $type ]
				);
				$updated++;
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10612 backfill ' . $type . ': ' . $e->getMessage(), 'gdcompliance_upg_10612' ); }
				catch ( \Throwable ) {}
			}
		}

		/* ---------- Log per-category counts so Derrick can verify ---------- */
		try
		{
			$counts = [];
			foreach ( \IPS\Db::i()->select( 'review_type, COUNT(*) AS c',
				'gd_compliance_review', null, null, null, 'review_type' ) as $row )
			{
				$counts[ (string) $row['review_type'] ] = (int) $row['c'];
			}
			$msg = 'upg_10612 review_type counts: ' . json_encode( $counts );
			try { \IPS\Log::log( $msg, 'gdcompliance_upg_10612' ); } catch ( \Throwable ) {}
			@error_log( $msg );
		}
		catch ( \Throwable ) {}

		/* ---------- Defensive gd_compliance_lowers create (from v1.6.10) ---------- */
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
			catch ( \Throwable ) { /* non-fatal, non-blocking */ }
		}

		/* ---------- Lang seed — v1.6.12 category-aware review keys ---------- */
		$newStrings = [
			/* Category-aware review queue */
			'gdcompliance_acp_review_title'              => 'Compliance Review Queue',
			'gdcompliance_acp_review_intro'              => 'Items pending manual review across every category. Filter by CATEGORY to focus on roster / AWB firearms / lowers / magazines; STATE narrows within a category.',
			'gdcompliance_acp_review_title_all'          => 'Compliance Review Queue',
			'gdcompliance_acp_review_intro_all'          => 'All pending items across categories. Choose a CATEGORY tab to narrow.',
			'gdcompliance_acp_review_title_roster'       => 'Roster Review Queue',
			'gdcompliance_acp_review_intro_roster'       => 'Handguns the matcher could not confidently place on or off a state roster. Each row shows the near-miss roster candidates the matcher considered. Resolve manually — Mark on-roster clears the state restriction for that UPC; Mark off-roster sets the state flag. Decisions persist across recomputes.',
			'gdcompliance_acp_review_title_awb'          => 'AWB Firearm Review Queue',
			'gdcompliance_acp_review_intro_awb'          => 'Semi-auto centerfire firearms flagged for feature-based review (tier-2 or tier-3 AWB). Verify the model / features against the state statute — Confirm AWB writes a force_restrict override; Not an AWB writes a force_clear.',
			'gdcompliance_acp_review_title_lower'        => 'Lower Receiver Review Queue',
			'gdcompliance_acp_review_intro_lower'        => 'Ambiguous cat154 lower receivers (bolt-action / rimfire / unknown platform) needing confirmation. Confirm restricted lower applies a force_restrict override across every enabled rifle-class AWB state; Not an AWB lower clears them all.',
			'gdcompliance_acp_review_title_magazine'     => 'Magazine Review Queue',
			'gdcompliance_acp_review_intro_magazine'     => 'Standalone magazines needing manual review. Confirm over-capacity keeps / adds the state restriction; Not restricted clears it.',
			'gdcompliance_acp_review_mark_on'            => 'Mark on-roster (clear)',
			'gdcompliance_acp_review_mark_off'           => 'Mark off-roster (restrict)',
			'gdcompliance_acp_review_mark_confirm_awb'   => 'Confirm AWB (restrict)',
			'gdcompliance_acp_review_mark_not_awb'       => 'Not an AWB (clear)',
			'gdcompliance_acp_review_mark_confirm_lower' => 'Confirm restricted lower',
			'gdcompliance_acp_review_mark_not_lower'     => 'Not an AWB lower (clear)',
			'gdcompliance_acp_review_mark_confirm_mag'   => 'Confirm over-capacity (restrict)',
			'gdcompliance_acp_review_mark_not_mag'       => 'Not restricted (clear)',
			'gdcompliance_acp_review_col_review_type'    => 'Category',
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

		/* ---------- Cache purges ---------- */
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
