<?php
/**
 * @brief  GD Compliance — canonical rule seed (single source of truth)
 *
 * The mid-2026 verified state magazine-capacity ruleset lives here so:
 *   - setup/install/install.php (fresh install)
 *   - setup/upg_XXXXX/upgrade.php (existing installs)
 *   - modules/admin/compliance/rules.php "Reseed missing rules" button
 * all draw from the SAME list.
 *
 * Seeding is IDEMPOTENT and NON-DESTRUCTIVE — it inserts a rule ONLY if
 * a row for that (state_code, firearm_type) pair does not already exist.
 * Existing rows — including any edits Derrick made — are NEVER touched.
 *
 * Rules table is PERMANENT REFERENCE DATA. Neither this class, nor the
 * install, nor any upgrade step, nor a compute run, ever deletes rows
 * from gd_compliance_rules. Same for gd_compliance_overrides.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Seeder
{
	/**
	 * The canonical ruleset (mid-2026 verified). ONE entry per
	 * (state_code, firearm_type) pair — that's the idempotency key.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function ruleSet(): array
	{
		return [
			/* IL — Protect Illinois Communities Act / PA 102-1116. Different limits per type. */
			[ 'state_code' => 'IL', 'firearm_type' => 'handgun', 'max_capacity' => 15, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'PA 102-1116 (Protect Illinois Communities Act, 2023)' ],
			[ 'state_code' => 'IL', 'firearm_type' => 'rifle',   'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'PA 102-1116 (Protect Illinois Communities Act, 2023)' ],
			[ 'state_code' => 'IL', 'firearm_type' => 'shotgun', 'max_capacity' => 5,  'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'PA 102-1116 (Protect Illinois Communities Act, 2023)' ],

			/* VT — Act 94 / S.55 (2018). */
			[ 'state_code' => 'VT', 'firearm_type' => 'handgun', 'max_capacity' => 15, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'Act 94 / S.55 (2018)' ],
			[ 'state_code' => 'VT', 'firearm_type' => 'rifle',   'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'Act 94 / S.55 (2018)' ],
			[ 'state_code' => 'VT', 'firearm_type' => 'shotgun', 'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'Act 94 / S.55 (2018)' ],

			/* CO — HB 13-1224 (2013). */
			[ 'state_code' => 'CO', 'firearm_type' => 'all',     'max_capacity' => 15, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'HB 13-1224 (2013)' ],

			/* DE — HB 451 (2022). */
			[ 'state_code' => 'DE', 'firearm_type' => 'all',     'max_capacity' => 17, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'HB 451 (2022)' ],

			/* CT — PA 13-3 post-Sandy-Hook. */
			[ 'state_code' => 'CT', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'PA 13-3 (Act Concerning Gun Violence Prevention, 2013)' ],

			/* MD — Public Safety §4-305 (FSA 2013). Sale/transfer only. */
			[ 'state_code' => 'MD', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'MD Public Safety §4-305 (FSA 2013); sale/transfer only — possession legal' ],

			/* MA — MGL c.140 §131M (1998 / pre-existing grandfathered). */
			[ 'state_code' => 'MA', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'MGL c.140 §131M (1998 / pre-existing grandfathered)' ],

			/* NJ — A2761 (2018). */
			[ 'state_code' => 'NJ', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'A2761 (Large Capacity Magazine Reduction, 2018)' ],

			/* NY — SAFE Act / S2230 (2013). */
			[ 'state_code' => 'NY', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'NY SAFE Act / S2230 (2013)' ],

			/* WA — SB 5078 (2022) sale/transfer only. */
			[ 'state_code' => 'WA', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'SB 5078 (2022); sale/transfer only — possession legal' ],

			/* RI — H7457 (2022). */
			[ 'state_code' => 'RI', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'H7457 (2022)' ],

			/* HI — HRS §134-8 handguns only. */
			[ 'state_code' => 'HI', 'firearm_type' => 'handgun', 'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'HRS §134-8 — handguns only' ],

			/* CA — currently enforced (Duncan v Bonta ongoing). */
			[ 'state_code' => 'CA', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 1, 'source_note' => 'CA Pen Code §32310 (in flux: Duncan v Bonta — verify before publishing)' ],

			/* VA — SB 749 effective 2026-07-01 (auto-activates via date gate). */
			[ 'state_code' => 'VA', 'firearm_type' => 'all',     'max_capacity' => 15, 'rule_type' => 'sale_transfer', 'effective_date' => '2026-07-01', 'expires_date' => null, 'enabled' => 1, 'source_note' => 'SB 749 (effective 2026-07-01)' ],

			/* DC — seeded DISABLED; Benson v US currently enjoins enforcement. */
			[ 'state_code' => 'DC', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,         'expires_date' => null, 'enabled' => 0, 'source_note' => 'DC Code §7-2506.01 (currently enjoined per Benson v United States)' ],
		];
	}

	/**
	 * Seed any MISSING rules — idempotent, non-destructive.
	 *
	 * For each entry in ruleSet(), checks whether a row already exists
	 * for that (state_code, firearm_type) pair. If yes → skip untouched
	 * (preserving any admin edits). If no → insert.
	 *
	 * Never deletes, never truncates, never updates existing rows.
	 *
	 * @return array{inserted:int, skipped:int, failed:int}
	 */
	public static function seedMissingRules(): array
	{
		$counts = [ 'inserted' => 0, 'skipped' => 0, 'failed' => 0 ];
		$now    = time();

		foreach ( self::ruleSet() as $rule )
		{
			$state = (string) ( $rule['state_code']   ?? '' );
			$type  = (string) ( $rule['firearm_type'] ?? '' );
			if ( $state === '' || $type === '' )
			{
				$counts['failed']++;
				continue;
			}

			try
			{
				$exists = (int) \IPS\Db::i()->select(
					'COUNT(*)',
					'gd_compliance_rules',
					[ 'state_code=? AND firearm_type=?', $state, $type ]
				)->first();

				if ( $exists > 0 )
				{
					$counts['skipped']++;
					continue;
				}

				\IPS\Db::i()->insert( 'gd_compliance_rules', $rule + [ 'updated_at' => $now ] );
				$counts['inserted']++;
			}
			catch ( \Throwable $e )
			{
				$counts['failed']++;
				try { \IPS\Log::log( 'Seeder::seedMissingRules ' . $state . '/' . $type . ': ' . $e->getMessage(), 'gdcompliance_seed' ); } catch ( \Throwable ) {}
			}
		}

		return $counts;
	}
}

class Seeder extends _Seeder {}
