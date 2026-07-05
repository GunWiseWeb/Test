<?php
/**
 * @brief  GD Compliance — upgrade 1.6.21
 *
 * NOTE ON VERSION — the corresponding prompt asked for 1.6.20, but
 * v1.6.20 had already shipped as the CRITICAL Heritage frame-material
 * correction to v1.6.19's melting-point matcher. This ship extends
 * that as v1.6.21 rather than overwriting v1.6.20.
 *
 * Landing:
 *   1. Two new tables:
 *        gd_compliance_rof_rules  — per-state config for the 14
 *          rate-of-fire ban states (enabled + reason + citation).
 *          MN NOT seeded (ban struck down 2026-05-26 by Minn. Gun
 *          Owners Caucus v. Walz).
 *        gd_compliance_rof        — curated overrides (force_flag /
 *          force_clear / review, substring match wins over auto).
 *   2. New sources/RateOfFire.php classifier:
 *        - Franklin Armory + title contains 'binary'/'BFS'/'BFSIII'
 *          → flag (cross-category; catches standalone triggers AND
 *          complete Franklin rifles in cat8 with a binary installed).
 *        - Cat58 Parts & Accessories or cat60 Triggers & Trigger
 *          Groups + title contains 'bump stock' or 'trigger crank'
 *          → flag. Both phrases are safe (no false positives observed).
 *        - Bare 'binary' / 'FRT' / 'BFS' / 'rare breed' NEVER match
 *          without a brand qualifier. Verified false positives that
 *          MUST NOT fire: Primos "Rare Breed" turkey calls (cat115),
 *          CobraTec "BFS" knives (cat138), Wilson Combat "CQBFS"
 *          pistols, Night Fision / FAB / Samson / TacFire "FRT"
 *          (FRONT sight).
 *        - Curated table wins over auto — Rare Breed Triggers,
 *          Fostech Echo, Wide Open Trigger, etc. arrive here matched
 *          by exact brand / model.
 *   3. Engine::computeFlags Phase 7C — runs BEFORE the typeMap
 *      null-skip so cat58/60 accessories (which don't roll up to
 *      firearm categories) get matched. Emits firearm_type='rate_of_fire'
 *      rows for every enabled state. Falls through Flag::forUpc into
 *      TYPE_ROSTER classification → red "cannot ship" banner.
 *   4. New ACP page /rof — mirrors Melting-Point + Advisories:
 *      summary + per-state edit + clickable state filter + paginated
 *      flagged-products with per-row override + curated CRUD.
 *
 * DEFENSIVE — carries all prior single-upg landings forward for
 * skip-upgrades from any earlier version.
 *
 * Recompute after deploy to populate rate_of_fire flags.
 */

namespace IPS\gdcompliance\setup\upg_10621;

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
		/* ---------- Create gd_compliance_rof_rules (guarded) ---------- */
		$hasRofRules = FALSE;
		try { $hasRofRules = (bool) \IPS\Db::i()->checkForTable( 'gd_compliance_rof_rules' ); }
		catch ( \Throwable ) { $hasRofRules = FALSE; }
		if ( !$hasRofRules )
		{
			try
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_compliance_rof_rules',
					'columns' => [
						'id'             => [ 'name' => 'id', 'type' => 'INT', 'length' => 10, 'decimals' => null, 'allow_null' => FALSE, 'default' => null, 'auto_increment' => TRUE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'state_code'     => [ 'name' => 'state_code', 'type' => 'CHAR', 'length' => 2, 'decimals' => null, 'allow_null' => FALSE, 'default' => '', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => FALSE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
						'enabled'        => [ 'name' => 'enabled', 'type' => 'TINYINT', 'length' => 1, 'decimals' => null, 'allow_null' => FALSE, 'default' => '1', 'auto_increment' => FALSE, 'binary' => FALSE, 'unsigned' => TRUE, 'zerofill' => FALSE, 'values' => [], 'comment' => '' ],
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
				try { \IPS\Log::log( 'upg_10621 create rof_rules: ' . $e->getMessage(), 'gdcompliance_upg_10621' ); } catch ( \Throwable ) {}
				return FALSE;
			}
		}

		/* ---------- Create gd_compliance_rof curated table (guarded) ---------- */
		$hasRofCur = FALSE;
		try { $hasRofCur = (bool) \IPS\Db::i()->checkForTable( 'gd_compliance_rof' ); }
		catch ( \Throwable ) { $hasRofCur = FALSE; }
		if ( !$hasRofCur )
		{
			try
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_compliance_rof',
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
			catch ( \Throwable ) {}
		}

		/* ---------- Seed the 14 banned states (NOT MN) ---------- */
		$reasonTpl = "Rate-of-fire enhancement device (binary trigger / forced-reset trigger / bump stock / trigger crank) — prohibited for sale in %s under the state's rapid-fire / multiburst-trigger-activator statute. This item cannot ship to a %s address.";
		$seeds = [
			[ 'state_code' => 'CA', 'citation' => 'Cal. Penal Code §16590 / §32900 (multiburst trigger activator)' ],
			[ 'state_code' => 'CT', 'citation' => 'Conn. Gen. Stat. §53-202 (rapid-fire enhancement — bump stock, trigger crank, binary trigger)' ],
			[ 'state_code' => 'DE', 'citation' => '11 Del. C. §1444 (rapid-fire device / bump stock)' ],
			[ 'state_code' => 'DC', 'citation' => 'DC Code §7-2502.02 (machine gun definition — rate of fire enhancements)' ],
			[ 'state_code' => 'HI', 'citation' => 'HRS §134-1 (bump-fire stock / trigger-crank equivalent)' ],
			[ 'state_code' => 'IL', 'citation' => '720 ILCS 5/24-1(a)(19) (trigger crank, bump stock, other rate-of-fire enhancements)' ],
			[ 'state_code' => 'MA', 'citation' => 'MGL c.140 §131M (bump stock / trigger crank)' ],
			[ 'state_code' => 'MD', 'citation' => 'MD Crim Law §4-305 (rapid-fire trigger activator)' ],
			[ 'state_code' => 'NJ', 'citation' => 'N.J.S.A. 2C:39-1(w)(6) (trigger crank / bump stock / rate-of-fire enhancement)' ],
			[ 'state_code' => 'NV', 'citation' => 'NRS 202.274 (bump-fire stock ban)' ],
			[ 'state_code' => 'NY', 'citation' => 'NY Penal Law §265.00(23-a) (rapid-fire modification device)' ],
			[ 'state_code' => 'OR', 'citation' => 'ORS 166.365 (rapid-fire activator)' ],
			[ 'state_code' => 'RI', 'citation' => 'R.I. Gen. Laws §11-47-8(c) (bump stock / trigger crank)' ],
			[ 'state_code' => 'WA', 'citation' => 'RCW 9.41.190 (bump-fire stock)' ],
		];
		$stateFullNames = [
			'CA' => 'California',    'CT' => 'Connecticut',  'DE' => 'Delaware',
			'DC' => 'District of Columbia',
			'HI' => 'Hawaii',        'IL' => 'Illinois',     'MA' => 'Massachusetts',
			'MD' => 'Maryland',      'NJ' => 'New Jersey',   'NV' => 'Nevada',
			'NY' => 'New York',      'OR' => 'Oregon',       'RI' => 'Rhode Island',
			'WA' => 'Washington',
		];
		foreach ( $seeds as $seed )
		{
			try
			{
				$existing = null;
				try { $existing = \IPS\Db::i()->select( 'id', 'gd_compliance_rof_rules', [ 'state_code=?', $seed['state_code'] ] )->first(); }
				catch ( \Throwable ) { $existing = null; }
				$stateName = $stateFullNames[ $seed['state_code'] ] ?? $seed['state_code'];
				$reason    = sprintf( $reasonTpl, $stateName, $seed['state_code'] );
				if ( $existing )
				{
					\IPS\Db::i()->update( 'gd_compliance_rof_rules', [
						'enabled'    => 1,
						'reason'     => $reason,
						'citation'   => (string) $seed['citation'],
						'updated_at' => time(),
					], [ 'id=?', (int) $existing ] );
				}
				else
				{
					\IPS\Db::i()->insert( 'gd_compliance_rof_rules', [
						'state_code'     => (string) $seed['state_code'],
						'enabled'        => 1,
						'reason'         => $reason,
						'citation'       => (string) $seed['citation'],
						'effective_date' => null,
						'updated_at'     => time(),
					] );
				}
			}
			catch ( \Throwable ) {}
		}

		/* ---------- Defensive prior single-upg landings ---------- */
		/* v1.6.19: gd_compliance_melting_rules + gd_compliance_melting */
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
			catch ( \Throwable ) {}
		}
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
			catch ( \Throwable ) {}
		}

		/* v1.6.17: gd_compliance_advisory_rules */
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

		/* v1.6.10: gd_compliance_lowers */
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

		/* v1.6.12: gd_compliance_review.review_type */
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
					'comment' => 'roster|awb_firearm|lower|magazine|melting — the review category',
				] );
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

		/* ---------- Lang seed — v1.6.21 rate-of-fire ACP + menu ---------- */
		$newStrings = [
			'menu__gdcompliance_compliance_rof'      => 'Rate-of-Fire Devices',
			'gdcompliance_acp_rof_title'             => 'Rate-of-Fire Devices',
			'gdcompliance_acp_rof_intro'             => 'Bans on rate-of-fire enhancement devices: binary triggers, forced-reset triggers (FRTs), bump stocks, trigger cranks. Enabled in CA, CT, DE, DC, HI, IL, MA, MD, NJ, NV, NY, OR, RI, WA. ⚠️ MN is NOT enabled — its binary-trigger ban was struck down by the MN Court of Appeals on 2026-05-26. Matcher requires the Franklin Armory brand qualifier for binary/BFS titles (bare "BFS" hits knives, "FRT" hits front sights, "Rare Breed" hits turkey calls). Safe phrases (bump stock, trigger crank) only match inside cat58 Parts & Accessories and cat60 Triggers & Trigger Groups.',
			'gdcompliance_acp_rof_flagged_title'     => 'Flagged devices',
			'gdcompliance_acp_rof_flagged_intro'     => 'Every product currently carrying a rate_of_fire flag. Click a state to filter and reach per-(UPC, state) override — force_clear suppresses the restriction for that product in that state.',
			'gdcompliance_acp_rof_override'          => 'Set override',
			'gdcompliance_acp_rof_curated'           => 'Curated overrides (win over auto-matching)',
			'gdcompliance_acp_rof_curated_intro'     => 'Each row is a substring matched case-insensitive against UPC / title / brand / model / MPN. Use for named makers (Rare Breed Triggers, Fostech Echo, Wide Open Trigger) or to force_clear a false auto-match. force_flag → always flag. force_clear → never flag. review → route to review.',
			'gdcompliance_acp_rof_add'               => 'Add curated entry',
			'gdcompliance_acp_rof_col_pattern'       => 'Pattern',
			'gdcompliance_acp_rof_col_action'        => 'Action',
			'gdcompliance_acp_rof_col_note'          => 'Note',
			'gdcompliance_rof_f_pattern'             => 'Pattern (case-insensitive substring, matched against UPC/title/brand/model/MPN)',
			'gdcompliance_rof_f_action'              => 'Action',
			'gdcompliance_rof_f_note'                => 'Note (admin-only) — optional',
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
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/RateOfFire.php';
			\IPS\gdcompliance\RateOfFire::clearCache();
		}
		catch ( \Throwable ) {}
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/MeltingPoint.php';
			\IPS\gdcompliance\MeltingPoint::clearCache();
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
