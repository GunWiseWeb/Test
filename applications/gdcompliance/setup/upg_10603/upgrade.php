<?php
/**
 * @brief  GD Compliance — upgrade 1.6.3 (compute-collapse fix +
 *          per-row resilience + admin JOIN fix + vestigial drop
 *          consolidation)
 *
 * Code-shape changes only. What this migration script does:
 *   (1) Robust vestigial-column drop on gd_compliance_flags (belt and
 *       braces — repeats the v1.5.x drop-any-that-exist for installs
 *       where prior upgrades were skipped)
 *   (2) Lang reseed for the compute summary strings that surface
 *       row_errors and roster-outcome counts
 *   (3) Cache + canonical_templates + opcache purge
 *
 * NEVER truncates rules, overrides, awb_models, or awb_rules. Does NOT
 * auto-run compute — but Derrick MUST recompute after deploy so the
 * self:: fix takes effect and ~32,000 flags persist.
 */

namespace IPS\gdcompliance\setup\upg_10603;

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
		$prefix = (string) \IPS\Db::i()->prefix;

		/* ============================================================
		 * (1) VESTIGIAL COLUMN DROP — belt-and-braces repeat of the
		 * v1.5.x drop for any install where prior upgrades were skipped
		 * or partial. Unconditional per-column try/catch — expected
		 * failure ("column doesn't exist") is caught silently.
		 * ============================================================ */
		$vestigial = [
			'distributor_id',
			'flag_type',
			'flag_value',
			'source',
			'status',
			'first_seen_at',
			'last_confirmed_at',
			'removed_by_dist_at',
			'admin_reviewed_by',
			'admin_reviewed_at',
			'listing_id',
		];
		$dropped = 0;
		foreach ( $vestigial as $col )
		{
			try
			{
				\IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_flags DROP COLUMN ' . $col );
				$dropped++;
			}
			catch ( \Throwable $e )
			{
				$msg = strtolower( $e->getMessage() );
				if ( strpos( $msg, "can't drop" ) === false && strpos( $msg, "check that column" ) === false && strpos( $msg, "unknown column" ) === false )
				{
					try { \IPS\Log::log( 'upg_10603 DROP flags.' . $col . ': ' . $e->getMessage(), 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}
				}
			}
		}
		try { \IPS\Log::log( 'upg_10603 vestigial column drop: ' . $dropped . ' of ' . count( $vestigial ) . ' columns dropped this run', 'gdcompliance_upgrade' ); } catch ( \Throwable ) {}

		/* Ensure the citation column exists (from v1.5.3). Guarded — if it
		   already exists, the ALTER errors and we swallow. */
		try { \IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_compliance_flags ADD COLUMN citation VARCHAR(255) NULL DEFAULT NULL AFTER reason' ); }
		catch ( \Throwable ) {}

		/* Clean up any stray staging tables from an interrupted compute. */
		try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $prefix . "gd_compliance_flags_stage" ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $prefix . "gd_compliance_flags_old" ); } catch ( \Throwable ) {}

		/* ============================================================
		 * (2) LANG RESEED — picks up new summary strings.
		 * ============================================================ */
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

		/* ============================================================
		 * (3) CACHE / OPCACHE + canonical_templates purge.
		 * ============================================================ */
		try { unset( \IPS\Data\Store::i()->settings ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpmenu ); }              catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); }  catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                    catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                    catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
