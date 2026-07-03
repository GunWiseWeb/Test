<?php
/**
 * @brief  GD Compliance — upgrade 1.6.17
 *
 * Landing:
 *   1. New table gd_compliance_advisory_rules — per-state buyer-permit
 *      advisory config (state_code + firearm_class, enabled, reason
 *      text, citation, effective_date). Editable via the new ACP
 *      Advisories page.
 *   2. Seed CO + MN rifle rules (both enabled). CO uses the SB25-003
 *      SSF eligibility card language; MN uses the §624.712 SAMSAW
 *      Permit to Purchase language. Reason text is customer-visible
 *      in the yellow advisory block on the product page.
 *   3. New sources/Advisories.php classifier ships in the tarball.
 *   4. Engine::computeFlags Phase 6b emits firearm_type='advisory'
 *      rows for every catalog product matching the state rule
 *      (semi-auto centerfire rifle, non-rimfire, detachable-mag).
 *
 * Advisories are NOT restrictions. Storefront:
 *   - Flag::forUpc returns each advisory row with type=TYPE_ADVISORY.
 *   - gdsearch v1.0.80 splits Flag::forUpc into $restrictionRows (red
 *     banner) and $advisoryRows (new yellow block).
 *   - The red "State Restricted — cannot ship to:" banner NEVER shows
 *     CO or MN under this path.
 *
 * DEFENSIVE — carries all prior single-upg landings forward
 * (gd_compliance_lowers CREATE from v1.6.10, review_type from v1.6.12,
 * tandemkross seed from v1.6.13) so a skip-upgrade from 1.6.9 → 1.6.17
 * lands the full intermediate state.
 *
 * Recompute after deploy to populate the new advisory flag rows.
 */

namespace IPS\gdcompliance\setup\upg_10617;

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
		/* ---------- Create gd_compliance_advisory_rules (guarded) ---------- */
		$hasAdv = FALSE;
		try
		{
			$hasAdv = (bool) \IPS\Db::i()->checkForTable( 'gd_compliance_advisory_rules' );
		}
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
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10617 createTable advisory_rules: ' . $e->getMessage(), 'gdcompliance_upg_10617' ); }
				catch ( \Throwable ) {}
				return FALSE;
			}
		}

		/* ---------- Seed CO + MN rifle advisories (idempotent per uq_state_class) ---------- */
		$coReason = "Colorado: The buyer must complete a state-approved firearms safety course and hold a sheriff-issued eligibility card before purchasing this semi-automatic firearm (Colo. SB25-003, effective 2026-08-01). This is a BUYER requirement — the item can ship to your FFL and the purchaser completes the eligibility card process there. This is not a sale prohibition.";
		$mnReason = "Minnesota: The buyer must hold a valid Permit to Purchase or Permit to Carry to acquire this semi-automatic military-style assault weapon; a 30-day dealer waiting period may apply (Minn. Stat. § 624.712). This is a BUYER requirement handled by the FFL at the time of transfer — not a sale prohibition.";

		$seeds = [
			[ 'state_code' => 'CO', 'firearm_class' => 'rifle', 'enabled' => 1, 'reason' => $coReason, 'citation' => 'Colo. Rev. Stat. — SB25-003 (Specified Semiautomatic Firearms Act)', 'effective_date' => '2026-08-01' ],
			[ 'state_code' => 'MN', 'firearm_class' => 'rifle', 'enabled' => 1, 'reason' => $mnReason, 'citation' => 'Minn. Stat. § 624.712',                                             'effective_date' => '2023-08-01' ],
		];
		foreach ( $seeds as $seed )
		{
			try
			{
				$existing = null;
				try
				{
					$existing = \IPS\Db::i()->select( 'id', 'gd_compliance_advisory_rules',
						[ 'state_code=? AND firearm_class=?', $seed['state_code'], $seed['firearm_class'] ] )->first();
				}
				catch ( \Throwable ) { $existing = null; }

				if ( $existing )
				{
					\IPS\Db::i()->update( 'gd_compliance_advisory_rules', [
						'enabled'        => (int) $seed['enabled'],
						'reason'         => (string) $seed['reason'],
						'citation'       => (string) $seed['citation'],
						'effective_date' => (string) $seed['effective_date'],
						'updated_at'     => time(),
					], [ 'id=?', (int) $existing ] );
				}
				else
				{
					\IPS\Db::i()->insert( 'gd_compliance_advisory_rules', $seed + [ 'updated_at' => time() ] );
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10617 seed ' . $seed['state_code'] . ': ' . $e->getMessage(), 'gdcompliance_upg_10617' ); }
				catch ( \Throwable ) {}
			}
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
					try
					{
						\IPS\Db::i()->update( 'gd_compliance_review', [ 'review_type' => $type ],
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
					'pattern' => 'tandemkross', 'platform' => null, 'action' => 'force_clear',
					'note' => 'Ruger .22 pistol/rimfire lower maker (Cthulhu, etc.) — not AWB. Brand-level clear supersedes per-UPC entries.',
					'created_at' => time(),
				] );
			}
		}
		catch ( \Throwable ) {}

		/* ---------- Lang seed (menu key + ACP labels) ---------- */
		$newStrings = [
			'menu__gdcompliance_compliance_advisories' => 'Advisories',
			'gdcompliance_acp_adv_title'               => 'Buyer-Permit Advisories',
			'gdcompliance_acp_adv_intro'               => 'Advisories are NOT sale restrictions. Each row here means the item CAN ship to that state, but the BUYER must meet a permit / eligibility-card / training step at the FFL. CO covers semi-auto centerfire rifles under SB25-003 (eff. 2026-08-01); MN covers AR/AK-pattern semi-auto rifles under Minn. Stat. §624.712 (in effect since 2023-08-01). Reason text is customer-visible in a yellow advisory block on the product page.',
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

		/* Warm the new classifier so any admin request in this PHP
		   process picks up the class. */
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

		/* ---------- Cache purges — acpmenu (advisory tab added) +
		   canonical_templates (companion gdsearch v1.0.80 ships new
		   product.phtml markup) ---------- */
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
