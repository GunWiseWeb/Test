<?php
/**
 * @brief  GD Compliance — Install routine
 *
 * Runs after schema.json creates the three gd_compliance_* tables.
 * Seeds:
 *   - the initial state magazine-capacity ruleset (14 states, verified
 *     mid-2026 — Derrick can edit any of it via ACP → Compliance → Rules)
 *   - dev/lang.php → core_sys_lang_words per language
 *   - compliance_manage permission row
 *
 * Never writes to gd_catalog. Disabling the app removes flag display
 * with zero catalog impact.
 */

if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { exit; }

/* -------------------------------------------------------------------------
 * SEED RULESET — mid-2026 verified state magazine-capacity laws.
 *
 *  state, firearm_type, max_capacity, rule_type, effective_date, expires_date,
 *  enabled, source_note
 *
 * Capacity STRICTLY GREATER than max_capacity flags the product. A rule with
 * effective_date in the future is seeded ENABLED but its date gate prevents
 * it from flagging until then (e.g. VA 7/1/2026). DC is seeded DISABLED
 * because Benson v. United States enjoined enforcement — one toggle to
 * re-enable when that changes. OR (Measure 114) is intentionally NOT seeded.
 * ------------------------------------------------------------------------- */
$seedRules = [
	/* IL — Protect Illinois Communities Act / PA 102-1116. Different limits per type. */
	[ 'state_code' => 'IL', 'firearm_type' => 'handgun', 'max_capacity' => 15, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'PA 102-1116 (Protect Illinois Communities Act, 2023)' ],
	[ 'state_code' => 'IL', 'firearm_type' => 'rifle',   'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'PA 102-1116 (Protect Illinois Communities Act, 2023)' ],
	[ 'state_code' => 'IL', 'firearm_type' => 'shotgun', 'max_capacity' => 5,  'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'PA 102-1116 (Protect Illinois Communities Act, 2023)' ],

	/* VT — handguns 15, long guns 10 (Act 94 / 2018). */
	[ 'state_code' => 'VT', 'firearm_type' => 'handgun', 'max_capacity' => 15, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'Act 94 / S.55 (2018)' ],
	[ 'state_code' => 'VT', 'firearm_type' => 'rifle',   'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'Act 94 / S.55 (2018)' ],
	[ 'state_code' => 'VT', 'firearm_type' => 'shotgun', 'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'Act 94 / S.55 (2018)' ],

	/* CO — all 15 (HB 13-1224 / 2013). */
	[ 'state_code' => 'CO', 'firearm_type' => 'all',     'max_capacity' => 15, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'HB 13-1224 (2013)' ],

	/* DE — all 17 (HB 451 / 2022). */
	[ 'state_code' => 'DE', 'firearm_type' => 'all',     'max_capacity' => 17, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'HB 451 (2022)' ],

	/* CT — all 10 (PA 13-3 post-Sandy-Hook). */
	[ 'state_code' => 'CT', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'PA 13-3 (Act Concerning Gun Violence Prevention, 2013)' ],

	/* MD — sale/transfer of >10 prohibited; possession of pre-existing legal. */
	[ 'state_code' => 'MD', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'MD Public Safety §4-305 (FSA 2013); sale/transfer only — possession legal' ],

	/* MA — all 10. */
	[ 'state_code' => 'MA', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'MGL c.140 §131M (1998 / pre-existing grandfathered)' ],

	/* NJ — A2761 (2018) reduced to 10. */
	[ 'state_code' => 'NJ', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'A2761 (Large Capacity Magazine Reduction, 2018)' ],

	/* NY — SAFE Act / S2230 (2013) 10. */
	[ 'state_code' => 'NY', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'NY SAFE Act / S2230 (2013)' ],

	/* WA — SB 5078 (2022) sale/transfer only. */
	[ 'state_code' => 'WA', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'SB 5078 (2022); sale/transfer only — possession legal' ],

	/* RI — H7457 (2022) 10. */
	[ 'state_code' => 'RI', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'H7457 (2022)' ],

	/* HI — handguns 10 only (no rifle/shotgun limit). */
	[ 'state_code' => 'HI', 'firearm_type' => 'handgun', 'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'HRS §134-8 — handguns only' ],

	/* CA — currently enforced (Duncan v Bonta litigation ongoing). */
	[ 'state_code' => 'CA', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 1, 'source_note' => 'CA Pen Code §32310 (in flux: Duncan v Bonta — verify before publishing)' ],

	/* VA — SB 749 effective 2026-07-01 (auto-activates via date gate). */
	[ 'state_code' => 'VA', 'firearm_type' => 'all',     'max_capacity' => 15, 'rule_type' => 'sale_transfer', 'effective_date' => '2026-07-01',   'expires_date' => null, 'enabled' => 1, 'source_note' => 'SB 749 (effective 2026-07-01)' ],

	/* DC — seeded DISABLED; Benson v US currently enjoins enforcement. */
	[ 'state_code' => 'DC', 'firearm_type' => 'all',     'max_capacity' => 10, 'rule_type' => 'sale_transfer', 'effective_date' => null,           'expires_date' => null, 'enabled' => 0, 'source_note' => 'DC Code §7-2506.01 (currently enjoined per Benson v United States)' ],
];

try
{
	$existingRules = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_rules' )->first();
	if ( $existingRules === 0 )
	{
		$now = time();
		foreach ( $seedRules as $r )
		{
			try
			{
				\IPS\Db::i()->insert( 'gd_compliance_rules', $r + [ 'updated_at' => $now ] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'gdcompliance install rule seed: ' . $e->getMessage(), 'gdcompliance_install' ); } catch ( \Throwable ) {}
			}
		}
	}
}
catch ( \Throwable $e )
{
	try { \IPS\Log::log( 'gdcompliance install rule seed: ' . $e->getMessage(), 'gdcompliance_install' ); } catch ( \Throwable ) {}
}

/* Lang seed — every key in dev/lang.php into core_sys_lang_words per language. */
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

/* ACP permission row. */
try
{
	$has = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_admin_permission_rows',
		[ 'app=? AND `key`=?', 'gdcompliance', 'compliance_manage' ] )->first();
	if ( !$has )
	{
		\IPS\Db::i()->insert( 'core_admin_permission_rows', [
			'app' => 'gdcompliance',
			'key' => 'compliance_manage',
			'tab' => 'gdcompliance',
		] );
	}
}
catch ( \Throwable ) {}

try { unset( \IPS\Data\Store::i()->acpmenu ); } catch ( \Throwable ) {}
try { \IPS\Data\Store::i()->clearAll(); }       catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); }       catch ( \Throwable ) {}
