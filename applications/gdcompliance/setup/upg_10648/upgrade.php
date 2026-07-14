<?php
/**
 * @brief  GD Compliance — upgrade 1.6.48
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.6.48 — ammo/knife advisory flags actually generate.
 *
 *   1. gd_compliance_flags.reason widened VARCHAR(255) -> VARCHAR(500).
 *      The longest ammo/knife advisory reason runs 338 chars (incl.
 *      the mandatory "verify current law before purchasing" tail).
 *      At 255 chars the tail was truncated; more importantly the
 *      bulkInsert chunk that carried an over-255 row was failing
 *      the whole chunk — the crash-safe swap then keeps the OLD
 *      flag set, so ammo/knife flags never landed. Guarded ALTER
 *      via checkForColumn/introspection (idempotent — re-runs are
 *      no-ops when the column is already 500).
 *
 *   2. sources/Engine.php — every "'reason' => substr( \$X, 0, 255 )"
 *      flag-row build site widened to 0, 500. Firearm advisories
 *      (CO SSF / MN SAMSAW rifle) and the new ammo/knife pass now
 *      persist the full customer-visible text.
 *
 *   3. sources/Engine.php — DEDICATED ammo/knife advisory pass
 *      added AFTER the main firearm loop and BEFORE the crash-safe
 *      stage swap. Streams gd_catalog by category (23-30 ammo,
 *      138+150 knife), fetches the per-state rule list ONCE via
 *      Advisories::matchesFor([], 'ammo'|'knife'), then emits one
 *      flag row per (product x enabled state). Appends to the same
 *      $flags array so the existing crash-safe swap persists them.
 *
 *      The per-row advisory pass in the main loop now explicitly
 *      SKIPS $type === 'ammo' || 'knife' so the new pass is the
 *      single source of truth for those classes — no duplicates.
 *
 *   4. All five \$result['review_queue'][] push sites now set
 *      'resolved' => 0. Fixes the recurring
 *        Engine::computeFlags review insert: Column 'resolved' cannot
 *        be null
 *      log entry that surfaced when melting-point / lower / AWB
 *      review rows carried the fresh queue (the roster-review path
 *      was already setting it explicitly at line ~1477).
 *
 * NO CanonicalTemplates re-seed call. No schema for advisory rules
 * (already exists). No lang changes.
 */

namespace IPS\gdcompliance\setup\upg_10648;

use function defined;
use function function_exists;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$this->widenReasonColumn();
		$this->clearCaches();
		return TRUE;
	}

	/**
	 * Widen gd_compliance_flags.reason from VARCHAR(255) to
	 * VARCHAR(500). Introspection-guarded so a re-run is a no-op.
	 *
	 * DESCRIBE returns rows with a Type column like "varchar(255)";
	 * we parse the number and only ALTER when it's < 500. Any
	 * failure logs and returns — never fatal.
	 */
	protected function widenReasonColumn(): void
	{
		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_compliance_flags', 'reason' ) )
			{
				try { \IPS\Log::log( 'upg_10648: gd_compliance_flags.reason missing — skipping widen', 'gdcompliance_upg_10648' ); } catch ( \Throwable ) {}
				return;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10648 checkForColumn: ' . $e->getMessage(), 'gdcompliance_upg_10648' ); } catch ( \Throwable ) {}
			return;
		}

		/* Read current type via DESCRIBE. */
		$curLength = 0;
		try
		{
			$prefix = (string) \IPS\Db::i()->prefix;
			$rs     = \IPS\Db::i()->preparedQuery(
				'SHOW COLUMNS FROM `' . $prefix . 'gd_compliance_flags` LIKE ?',
				[ 'reason' ]
			);
			if ( is_object( $rs ) )
			{
				$row = $rs->fetch_assoc();
				if ( $row && preg_match( '/varchar\((\d+)\)/i', (string) ( $row['Type'] ?? '' ), $m ) )
				{
					$curLength = (int) $m[1];
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10648 SHOW COLUMNS reason: ' . $e->getMessage(), 'gdcompliance_upg_10648' ); } catch ( \Throwable ) {}
			/* Fall through — we'll try the ALTER anyway. */
		}

		if ( $curLength >= 500 )
		{
			try { \IPS\Log::log( 'upg_10648: gd_compliance_flags.reason already varchar(' . $curLength . ') — no ALTER needed', 'gdcompliance_upg_10648' ); } catch ( \Throwable ) {}
			return;
		}

		try
		{
			$prefix = (string) \IPS\Db::i()->prefix;
			\IPS\Db::i()->preparedQuery(
				'ALTER TABLE `' . $prefix . 'gd_compliance_flags` MODIFY COLUMN `reason` VARCHAR(500) NULL DEFAULT NULL',
				[]
			);
			try { \IPS\Log::log( 'upg_10648: widened gd_compliance_flags.reason to VARCHAR(500)', 'gdcompliance_upg_10648' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10648 ALTER reason: ' . $e->getMessage(), 'gdcompliance_upg_10648' ); } catch ( \Throwable ) {}
		}
	}

	protected function clearCaches(): void
	{
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\gdcompliance\Advisories::clearCache(); }        catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
	}
}
class upgrade extends _upgrade {}
