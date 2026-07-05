<?php
/**
 * @brief  GD Compliance — upgrade 1.6.18
 *
 * ACP-display-only. Adds the flagged-products workflow to the
 * Advisories page (state filter → paginated flag list → per-row
 * override), matching Magazines and Lowers. Existing summary +
 * per-state reason-edit cards preserved unchanged.
 *
 * No engine changes. No schema changes. No recompute required.
 *
 * DEFENSIVE — carries all prior single-upg landings forward so a
 * skip-upgrade from 1.6.9 → 1.6.18 lands the full intermediate
 * state:
 *   v1.6.10 gd_compliance_lowers CREATE
 *   v1.6.12 gd_compliance_review.review_type ADD + backfill
 *   v1.6.13 tandemkross force_clear seed
 *   v1.6.17 gd_compliance_advisory_rules CREATE + CO/MN seed
 */

namespace IPS\gdcompliance\setup\upg_10618;

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
		/* ---------- Defensive gd_compliance_advisory_rules CREATE (v1.6.17) ---------- */
		$hasAdv = FALSE;
		try { $hasAdv = (bool) \IPS\Db::i()->checkForTable( 'gd_compliance_advisory_rules' ); }
		catch ( \Throwable ) { $hasAdv = FALSE; }
		if ( !$hasAdv )
		{
			try
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_compliance_advisory_rules',
					'columns' => [
						'id'             => [ 'name' => 'id', 'type' => 'INT', 'length' => 10, 'decimals' => null, 'allow_null' => FALSE, 'default' => null, 'auto_increment' => TRUE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'state_code'     => [ 'name' => 'state_code', 'type' => 'CHAR', 'length' => 2, 'decimals' => null, 'allow_null' => FALSE, 'default' => '', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'firearm_class'  => [ 'name' => 'firearm_class', 'type' => 'VARCHAR', 'length' => 20, 'decimals' => null, 'allow_null' => FALSE, 'default' => 'rifle', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'enabled'        => [ 'name' => 'enabled', 'type' => 'TINYINT', 'length' => 1, 'decimals' => null, 'allow_null' => FALSE, 'default' => '1', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'reason'         => [ 'name' => 'reason', 'type' => 'TEXT', 'length' => 0, 'decimals' => null, 'allow_null' => FALSE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'citation'       => [ 'name' => 'citation', 'type' => 'VARCHAR', 'length' => 255, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'effective_date' => [ 'name' => 'effective_date', 'type' => 'DATE', 'length' => null, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'updated_at'     => [ 'name' => 'updated_at', 'type' => 'INT', 'length' => 10, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
					],
					'indexes' => [
						'PRIMARY'         => [ 'type' => 'primary', 'name' => 'PRIMARY',         'length' => [ null ],       'columns' => [ 'id' ] ],
						'uq_state_class'  => [ 'type' => 'unique',  'name' => 'uq_state_class',  'length' => [ null, null ], 'columns' => [ 'state_code', 'firearm_class' ] ],
						'idx_enabled'     => [ 'type' => 'key',     'name' => 'idx_enabled',     'length' => [ null ],       'columns' => [ 'enabled' ] ],
					],
				] );

				$coReason = "Colorado: The buyer must complete a state-approved firearms safety course and hold a sheriff-issued eligibility card before purchasing this semi-automatic firearm (Colo. SB25-003, effective 2026-08-01). This is a BUYER requirement — the item can ship to your FFL and the purchaser completes the eligibility card process there. This is not a sale prohibition.";
				$mnReason = "Minnesota: The buyer must hold a valid Permit to Purchase or Permit to Carry to acquire this semi-automatic military-style assault weapon; a 30-day dealer waiting period may apply (Minn. Stat. § 624.712). This is a BUYER requirement handled by the FFL at the time of transfer — not a sale prohibition.";
				$seeds = [
					[ 'state_code' => 'CO', 'firearm_class' => 'rifle', 'enabled' => 1, 'reason' => $coReason, 'citation' => 'Colo. Rev. Stat. — SB25-003 (Specified Semiautomatic Firearms Act)', 'effective_date' => '2026-08-01', 'updated_at' => time() ],
					[ 'state_code' => 'MN', 'firearm_class' => 'rifle', 'enabled' => 1, 'reason' => $mnReason, 'citation' => 'Minn. Stat. § 624.712',                                             'effective_date' => '2023-08-01', 'updated_at' => time() ],
				];
				foreach ( $seeds as $seed )
				{
					try { \IPS\Db::i()->insert( 'gd_compliance_advisory_rules', $seed ); }
					catch ( \Throwable ) {}
				}
			}
			catch ( \Throwable ) {}
		}

		/* ---------- Defensive gd_compliance_lowers CREATE (v1.6.10) ---------- */
		$hasLowers = FALSE;
		try { $hasLowers = (bool) \IPS\Db::i()->checkForTable( 'gd_compliance_lowers' ); }
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
			catch ( \Throwable ) {}
		}

		/* ---------- Defensive gd_compliance_review.review_type (v1.6.12) ---------- */
		$hasReviewType = FALSE;
		try { $hasReviewType = (bool) \IPS\Db::i()->checkForColumn( 'gd_compliance_review', 'review_type' ); }
		catch ( \Throwable ) { $hasReviewType = FALSE; }
		if ( !$hasReviewType )
		{
			try
			{
				\IPS\Db::i()->addColumn( 'gd_compliance_review', [
					'name' => 'review_type', 'type' => 'VARCHAR', 'length' => 20, 'decimals' => null,
					'allow_null' => FALSE, 'default' => 'roster', 'auto_increment' => FALSE,
					'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [],
					'comment' => 'roster|awb_firearm|lower|magazine — the review category',
				] );
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
					try { \IPS\Db::i()->update( 'gd_compliance_review', [ 'review_type' => $type ],
						[ $whereFrag . ' AND review_type<>?', $type ] ); } catch ( \Throwable ) {}
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
					'pattern' => 'tandemkross', 'platform' => null, 'action' => 'force_clear',
					'note' => 'Ruger .22 pistol/rimfire lower maker (Cthulhu, etc.) — not AWB. Brand-level clear supersedes per-UPC entries.',
					'created_at' => time(),
				] );
			}
		}
		catch ( \Throwable ) {}

		/* ---------- Lang seed — v1.6.18 new labels ---------- */
		$newStrings = [
			'gdcompliance_acp_adv_flagged_title' => 'Flagged products',
			'gdcompliance_acp_adv_flagged_intro' => 'Every product currently carrying an advisory flag. Click a state to filter and reach per-(UPC, state) override — a force_clear suppresses the advisory notice for that specific product in that state.',
			'gdcompliance_acp_adv_override'      => 'Set override',
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

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Advisories.php';
			\IPS\gdcompliance\Advisories::clearCache();
		}
		catch ( \Throwable ) {}
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Lowers.php';
			\IPS\gdcompliance\Lowers::clearCache();
		}
		catch ( \Throwable ) {}

		/* ---------- Cache purges (ACP template change) ---------- */
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
