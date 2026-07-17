<?php
/**
 * @brief  GD Compliance — upgrade 1.6.51
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.6.51 — Manual Flag tool.
 *
 *   New ACP action modules/admin/compliance/lowers.php -> do=mflag
 *   lets an admin enter a UPC, pick states, review auto-filled
 *   reasons, and Flag / Clear the product in every selected state
 *   INSTANTLY (no 76-min recompute). Uses the existing
 *   Override::save() API with the sixth arg applyImmediately=true,
 *   which persists to gd_compliance_overrides AND upserts a
 *   gd_compliance_flags row via applyOne(). Recompute-safe:
 *   Engine::computeFlags() calls Override::applyAll() AFTER the
 *   crash-safe stage swap, so manual flags re-apply on every
 *   future full recompute.
 *
 *   Extensions to sources/Override.php:
 *     * save() gains an optional $firearmType parameter (7th) so
 *       the caller can request a specific firearm_type for the
 *       resulting flag row (e.g. 'awb_lower' — byte-identical to
 *       what Engine::computeFlags writes for a rule-based lower).
 *       Prior 6-arg call sites keep working (default null ->
 *       'manual' fallback in applyOne).
 *     * applyOne() reads $override['firearm_type'] and writes
 *       the flag row with that type, verbatim reason (no "Manual
 *       override:" prefix — byte-identical formatting to
 *       auto-flags).
 *     * remove() drops the historic reason LIKE 'Manual
 *       override:%' filter (that provenance prefix is gone) and
 *       deletes ALL flag rows for the (upc, state) — safe because
 *       overrides are the exclusive owner of that combo while
 *       active.
 *     * Reason substr cap raised 255 -> 500 to match
 *       gd_compliance_flags.reason.
 *
 *   Extensions to data/schema.json:
 *     * gd_compliance_overrides.reason widened 255 -> 500.
 *     * gd_compliance_overrides.firearm_type VARCHAR(20) NULL
 *       added.
 *
 *   Extensions to modules/admin/compliance/lowers.php:
 *     * New protected mflag(): void action. GET-no-upc shows a
 *       lookup form; GET-with-upc shows the product + state grid
 *       with per-state editable reason textareas auto-populated
 *       from Engine.php's awb_lower sprintf pattern; POST calls
 *       Override::save() per state and clears
 *       gd_compliance_review for the UPC.
 *     * manage() surfaces a green launcher card at the top with
 *       an "Open Manual Flag tool" button linking to do=mflag.
 *
 *   Lang keys — 20 new gdcompliance_acp_lowers_mflag_* strings
 *   added to dev/lang.php + data/lang.xml, re-seeded into
 *   core_sys_lang_words for every installed lang_id (rule #43,
 *   per-row try/catch rule #44).
 *
 * upgrade step1() does:
 *   1. Guarded ALTER: widen gd_compliance_overrides.reason to
 *      VARCHAR(500); add firearm_type VARCHAR(20) NULL if absent.
 *      SHOW COLUMNS introspection + checkForColumn — idempotent.
 *   2. Re-seed the 20 new lang keys per lang_id.
 *   3. Cache purge (modules_admin, applications, extensions,
 *      settings, Store::clearAll, Cache::clearAll, opcache_reset).
 *      NO CanonicalTemplates::ensure().
 *
 * Rule #79: upg_10650 removed, exactly one upg dir per app.
 */

namespace IPS\gdcompliance\setup\upg_10651;

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
		$this->alterOverridesReason();
		$this->addOverridesFirearmType();
		$this->seedLangStrings();
		$this->clearCaches();
		return TRUE;
	}

	/**
	 * gd_compliance_overrides.reason  VARCHAR(255) -> VARCHAR(500).
	 * Guarded — reads current width via SHOW COLUMNS and only
	 * ALTERs when < 500. Idempotent.
	 */
	protected function alterOverridesReason(): void
	{
		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_compliance_overrides', 'reason' ) )
			{
				return;
			}
		}
		catch ( \Throwable ) { return; }

		$curLength = 0;
		try
		{
			$prefix = (string) \IPS\Db::i()->prefix;
			$rs     = \IPS\Db::i()->preparedQuery(
				'SHOW COLUMNS FROM `' . $prefix . 'gd_compliance_overrides` LIKE ?',
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
			try { \IPS\Log::log( 'upg_10651 SHOW COLUMNS overrides.reason: ' . $e->getMessage(), 'gdcompliance_upg_10651' ); } catch ( \Throwable ) {}
		}
		if ( $curLength >= 500 ) { return; }

		try
		{
			$prefix = (string) \IPS\Db::i()->prefix;
			\IPS\Db::i()->preparedQuery(
				'ALTER TABLE `' . $prefix . 'gd_compliance_overrides` MODIFY COLUMN `reason` VARCHAR(500) NULL DEFAULT NULL',
				[]
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10651 ALTER overrides.reason: ' . $e->getMessage(), 'gdcompliance_upg_10651' ); } catch ( \Throwable ) {}
		}
	}

	/**
	 * Add gd_compliance_overrides.firearm_type VARCHAR(20) NULL if
	 * not already present. Idempotent.
	 */
	protected function addOverridesFirearmType(): void
	{
		try
		{
			if ( \IPS\Db::i()->checkForColumn( 'gd_compliance_overrides', 'firearm_type' ) )
			{
				return;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10651 checkForColumn firearm_type: ' . $e->getMessage(), 'gdcompliance_upg_10651' ); } catch ( \Throwable ) {}
			return;
		}

		try
		{
			\IPS\Db::i()->addColumn( 'gd_compliance_overrides', [
				'name'       => 'firearm_type',
				'type'       => 'VARCHAR',
				'length'     => 20,
				'allow_null' => TRUE,
				'default'    => NULL,
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10651 addColumn firearm_type: ' . $e->getMessage(), 'gdcompliance_upg_10651' ); } catch ( \Throwable ) {}
		}
	}

	/**
	 * Seed the new Manual Flag tool lang keys. Rule #43 (6-col shape),
	 * rule #44 (per-row try/catch).
	 */
	protected function seedLangStrings(): void
	{
		$strings = [
			'gdcompliance_acp_lowers_mflag_launch_title' => 'Manually flag a product (per state)',
			'gdcompliance_acp_lowers_mflag_launch_intro' => 'Flag a product that the classifier missed (or that hasn\'t been through a recompute yet) for one or more specific states. Writes to gd_compliance_overrides AND to gd_compliance_flags immediately via Override::save(applyImmediately=true) — recompute-safe. Also supports "Manual Clear" for false positives.',
			'gdcompliance_acp_lowers_mflag_launch_btn'   => 'Open Manual Flag tool',
			'gdcompliance_acp_lowers_mflag_title'        => 'Manually flag a product',
			'gdcompliance_acp_lowers_mflag_intro'        => 'Enter a UPC, pick the states, review the auto-filled reasons, and click Apply. Uses the SAME sprintf() formatting that Engine::computeFlags() uses for awb_lower rows — the manual flag is byte-identical to an auto-flag in the flags table (except for the "[manual admin flag]" provenance tail). Every override persists in gd_compliance_overrides, survives recomputes (Override::applyAll runs after every computeFlags), and immediately writes/removes the matching gd_compliance_flags row.',
			'gdcompliance_acp_lowers_mflag_upc_label'    => 'UPC',
			'gdcompliance_acp_lowers_mflag_lookup'       => 'Look up',
			'gdcompliance_acp_lowers_mflag_not_found'    => 'No product found in gd_catalog for this UPC.',
			'gdcompliance_acp_lowers_mflag_product'      => 'Product',
			'gdcompliance_acp_lowers_mflag_col_brand'    => 'Brand',
			'gdcompliance_acp_lowers_mflag_col_title'    => 'Title',
			'gdcompliance_acp_lowers_mflag_col_model'    => 'Model',
			'gdcompliance_acp_lowers_mflag_col_category' => 'Category',
			'gdcompliance_acp_lowers_mflag_pick_states'  => 'Pick states — the auto-filled reason for each is editable',
			'gdcompliance_acp_lowers_mflag_action_flag'  => 'Flag (force_restrict)',
			'gdcompliance_acp_lowers_mflag_action_clear' => 'Clear (force_clear)',
			'gdcompliance_acp_lowers_mflag_select_all'   => 'Select all',
			'gdcompliance_acp_lowers_mflag_select_none'  => 'Select none',
			'gdcompliance_acp_lowers_mflag_apply_now'    => 'Apply to selected states',
			'gdcompliance_acp_lowers_mflag_apply_note'   => 'Applies instantly — no recompute needed. Writes to gd_compliance_overrides + gd_compliance_flags. Clears any pending review-queue row for this UPC.',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $strings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdcompliance',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10651 lang ' . $key . ': ' . $e->getMessage(), 'gdcompliance_upg_10651' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10651 lang loop: ' . $e->getMessage(), 'gdcompliance_upg_10651' ); } catch ( \Throwable ) {}
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
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
	}
}
class upgrade extends _upgrade {}
