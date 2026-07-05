<?php
/**
 * @brief  GD Compliance — upgrade 1.6.19
 *
 * Landing:
 *   1. Two new tables:
 *        gd_compliance_melting_rules  — per-state config (HI/IL/MD/
 *          MA/MN/NY), enabled + threshold_f + editable reason.
 *        gd_compliance_melting        — curated overrides (force_flag /
 *          force_clear / review; substring match wins over auto).
 *   2. Seed 6 state rules (HI/IL/MD/MA/NY = 800°F, MN = 1000°F).
 *   3. New sources/MeltingPoint.php classifier — cat1/2/3 gate, EXACT
 *      brand match against the Saturday-Night-Special maker list
 *      (Hi-Point, Heritage Mfg, Phoenix Arms, Jimenez, Lorcin, Raven,
 *      Davis, Bryco/Jennings, Sundance, Cobra Enterprises).
 *      Heritage Mfg with 'steel' in title → clear (steel frame legal).
 *      Hi-Point 'Black Steel' in title → still flag (that's the slide,
 *      frame is zinc).
 *   4. Engine::computeFlags Phase 6c emits gd_compliance_flags rows
 *      with firearm_type='melting_point' for every enabled state.
 *      Flag::forUpc classifies these as TYPE_ROSTER (falls through the
 *      existing classifier) → shows in the RED restriction banner on
 *      the storefront, NOT the yellow advisory block. No gdsearch
 *      changes needed.
 *   5. New ACP page /meltingpoint — mirrors Advisories + Lowers:
 *      summary + per-state edit + clickable state filter + paginated
 *      flagged-products with per-row override + curated CRUD.
 *
 * DEFENSIVE — carries all prior single-upg landings forward for
 * skip-upgrades from 1.6.9 → 1.6.19.
 *
 * Recompute after deploy to populate melting_point flags.
 */

namespace IPS\gdcompliance\setup\upg_10619;

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
		/* ---------- Create gd_compliance_melting_rules (guarded) ---------- */
		$hasMpRules = FALSE;
		try { $hasMpRules = (bool) \IPS\Db::i()->checkForTable( 'gd_compliance_melting_rules' ); }
		catch ( \Throwable ) { $hasMpRules = FALSE; }
		if ( !$hasMpRules )
		{
			try
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_compliance_melting_rules',
					'columns' => [
						'id'             => [ 'name' => 'id', 'type' => 'INT', 'length' => 10, 'decimals' => null, 'allow_null' => FALSE, 'default' => null, 'auto_increment' => TRUE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'state_code'     => [ 'name' => 'state_code', 'type' => 'CHAR', 'length' => 2, 'decimals' => null, 'allow_null' => FALSE, 'default' => '', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'enabled'        => [ 'name' => 'enabled', 'type' => 'TINYINT', 'length' => 1, 'decimals' => null, 'allow_null' => FALSE, 'default' => '1', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'threshold_f'    => [ 'name' => 'threshold_f', 'type' => 'SMALLINT', 'length' => 6, 'decimals' => null, 'allow_null' => FALSE, 'default' => '800', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'reason'         => [ 'name' => 'reason', 'type' => 'TEXT', 'length' => 0, 'decimals' => null, 'allow_null' => FALSE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'citation'       => [ 'name' => 'citation', 'type' => 'VARCHAR', 'length' => 255, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'effective_date' => [ 'name' => 'effective_date', 'type' => 'DATE', 'length' => null, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'updated_at'     => [ 'name' => 'updated_at', 'type' => 'INT', 'length' => 10, 'decimals' => null, 'allow_null' => TRUE, 'default' => null, 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
					],
					'indexes' => [
						'PRIMARY'     => [ 'type' => 'primary', 'name' => 'PRIMARY',     'length' => [ null ], 'columns' => [ 'id' ] ],
						'uq_state'    => [ 'type' => 'unique',  'name' => 'uq_state',    'length' => [ null ], 'columns' => [ 'state_code' ] ],
						'idx_enabled' => [ 'type' => 'key',     'name' => 'idx_enabled', 'length' => [ null ], 'columns' => [ 'enabled' ] ],
					],
				] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10619 create melting_rules: ' . $e->getMessage(), 'gdcompliance_upg_10619' ); } catch ( \Throwable ) {}
				return FALSE;
			}
		}

		/* ---------- Create gd_compliance_melting curated table (guarded) ---------- */
		$hasMpCur = FALSE;
		try { $hasMpCur = (bool) \IPS\Db::i()->checkForTable( 'gd_compliance_melting' ); }
		catch ( \Throwable ) { $hasMpCur = FALSE; }
		if ( !$hasMpCur )
		{
			try
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_compliance_melting',
					'columns' => [
						'id'         => [ 'name' => 'id', 'type' => 'INT', 'length' => 10, 'decimals' => null, 'allow_null' => FALSE, 'default' => null, 'auto_increment' => TRUE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'pattern'    => [ 'name' => 'pattern', 'type' => 'VARCHAR', 'length' => 191, 'decimals' => null, 'allow_null' => FALSE, 'default' => '', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
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
			catch ( \Throwable ) { /* non-fatal — page still renders empty */ }
		}

		/* ---------- Seed the 6 state rules ---------- */
		$reasonTpl = "Handgun with a zinc-alloy / non-homogeneous frame that fails %s's minimum melting-point standard (%d°F) — prohibited for sale (Saturday-Night-Special ban). Steel-frame models from this line are exempt. Verify with the receiving FFL before purchase.";
		$seeds = [
			[ 'state_code' => 'HI', 'threshold_f' => 800,  'citation' => 'Hawaii HRS §134-16 (analogous melting-point provision)' ],
			[ 'state_code' => 'IL', 'threshold_f' => 800,  'citation' => '720 ILCS 5/24-3' ],
			[ 'state_code' => 'MD', 'threshold_f' => 800,  'citation' => 'MD Public Safety §5-133 / roster equivalent' ],
			[ 'state_code' => 'MA', 'threshold_f' => 800,  'citation' => 'MGL c.140 §131¾' ],
			[ 'state_code' => 'MN', 'threshold_f' => 1000, 'citation' => 'Minn. Stat. §624.712 (tensile + melting-point criteria)' ],
			[ 'state_code' => 'NY', 'threshold_f' => 800,  'citation' => 'NY Penal Law §270.00' ],
		];
		$stateFullNames = [
			'HI' => 'Hawaii', 'IL' => 'Illinois', 'MD' => 'Maryland',
			'MA' => 'Massachusetts', 'MN' => 'Minnesota', 'NY' => 'New York',
		];
		foreach ( $seeds as $seed )
		{
			try
			{
				$existing = null;
				try
				{
					$existing = \IPS\Db::i()->select( 'id', 'gd_compliance_melting_rules',
						[ 'state_code=?', $seed['state_code'] ] )->first();
				}
				catch ( \Throwable ) { $existing = null; }

				$reason = sprintf( $reasonTpl, $stateFullNames[ $seed['state_code'] ] ?? $seed['state_code'], $seed['threshold_f'] );

				if ( $existing )
				{
					\IPS\Db::i()->update( 'gd_compliance_melting_rules', [
						'enabled'     => 1,
						'threshold_f' => (int) $seed['threshold_f'],
						'reason'      => $reason,
						'citation'    => (string) $seed['citation'],
						'updated_at'  => time(),
					], [ 'id=?', (int) $existing ] );
				}
				else
				{
					\IPS\Db::i()->insert( 'gd_compliance_melting_rules', [
						'state_code'     => (string) $seed['state_code'],
						'enabled'        => 1,
						'threshold_f'    => (int) $seed['threshold_f'],
						'reason'         => $reason,
						'citation'       => (string) $seed['citation'],
						'effective_date' => null,
						'updated_at'     => time(),
					] );
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10619 seed ' . $seed['state_code'] . ': ' . $e->getMessage(), 'gdcompliance_upg_10619' ); }
				catch ( \Throwable ) {}
			}
		}

		/* ---------- Defensive prior single-upg landings ---------- */
		/* v1.6.17 — gd_compliance_advisory_rules */
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
			}
			catch ( \Throwable ) {}
		}

		/* v1.6.10 — gd_compliance_lowers */
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
						'pattern'    => [ 'name' => 'pattern', 'type' => 'VARCHAR', 'length' => 191, 'decimals' => null, 'allow_null' => FALSE, 'default' => '', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
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

		/* v1.6.12 — gd_compliance_review.review_type */
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

		/* v1.6.13 — tandemkross force_clear */
		try
		{
			$existing = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_lowers',
				[ 'pattern=?', 'tandemkross' ] )->first();
			if ( $existing === 0 )
			{
				\IPS\Db::i()->insert( 'gd_compliance_lowers', [
					'pattern' => 'tandemkross', 'platform' => null, 'action' => 'force_clear',
					'note' => 'Ruger .22 pistol/rimfire lower maker (Cthulhu, etc.) — not AWB.',
					'created_at' => time(),
				] );
			}
		}
		catch ( \Throwable ) {}

		/* ---------- Lang seed ---------- */
		$newStrings = [
			'menu__gdcompliance_compliance_meltingpoint' => 'Melting-Point',
			'gdcompliance_acp_mp_title'                  => 'Melting-Point Handgun Ban',
			'gdcompliance_acp_mp_intro'                  => 'Saturday-Night-Special / "melting-point" bans on cheap zinc-alloy handguns — HI / IL / MD / MA / MN / NY. Threshold 800°F (HI/IL/MD/MA/NY) or 1000°F (MN plus tensile/density). Handgun categories only (cat1/2/3); rifles/carbines and non-handgun accessories excluded. Match on the EXACT brand field, never the title (avoids "Heritage Cases" cases, ProMag "Fits Hi-Point" mags, and Cobra rail accessories).',
			'gdcompliance_acp_mp_flagged_title'          => 'Flagged handguns',
			'gdcompliance_acp_mp_flagged_intro'          => 'Every handgun currently carrying a melting_point flag. Click a state to filter and reach per-(UPC, state) override — force_clear suppresses the restriction for that product in that state.',
			'gdcompliance_acp_mp_override'               => 'Set override',
			'gdcompliance_acp_mp_curated'                => 'Curated overrides (win over auto-matching)',
			'gdcompliance_acp_mp_curated_intro'          => 'Each row is a substring matched case-insensitive against UPC / title / brand / model / MPN. force_flag → always flag. force_clear → never flag. review → route to review.',
			'gdcompliance_acp_mp_add'                    => 'Add curated entry',
			'gdcompliance_acp_mp_col_pattern'            => 'Pattern',
			'gdcompliance_acp_mp_col_action'             => 'Action',
			'gdcompliance_acp_mp_col_note'               => 'Note',
			'gdcompliance_mp_f_pattern'                  => 'Pattern (case-insensitive substring, matched against UPC/title/brand/model/MPN)',
			'gdcompliance_mp_f_action'                   => 'Action',
			'gdcompliance_mp_f_note'                     => 'Note (admin-only) — optional',
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
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/MeltingPoint.php';
			\IPS\gdcompliance\MeltingPoint::clearCache();
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

		/* ---------- Cache purges — acpmenu (new tab) + canonical templates ---------- */
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
