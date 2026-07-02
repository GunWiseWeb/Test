<?php
/**
 * @brief  GD Compliance — upgrade 1.6.7
 *
 * Feature landing:
 *   - Adds gd_compliance_awb_rules.exemption_note (TEXT NULL) — free-text,
 *     per-state disclaimer that renders in the customer restriction popup
 *     AND in the ACP. Editable in the AWB States dashboard without a
 *     deploy so Derrick can reword when a product is reported.
 *   - Enables Connecticut AWB (Conn. Gen. Stat. §53-202a et seq.) as the
 *     first fully-verified real state, and seeds its default exemption
 *     note. The AWB flag STAYS ON for the general public — the note is
 *     a buyer-side disclaimer, NOT a green light.
 *
 * Overrides (Override::applyAll) already run AFTER the rule/AWB pass in
 * computeFlags and operate on gd_compliance_flags regardless of source,
 * so a force_clear / force_restrict on a CT AWB flag survives recompute
 * with no change here. Verified in this version.
 *
 * DO NOT re-run prior upgrades. IPS only runs upg_XXXXX dirs whose
 * integer key is greater than the currently-installed app_long_version.
 * Prior upgrades (upg_10604/5/6) have been consolidated out per rule #79.
 * Idempotent: safe to re-run on partial-crash.
 */

namespace IPS\gdcompliance\setup\upg_10607;

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
		/* ---------- FIX 1: add exemption_note column (guarded) ---------- */
		$hasCol = FALSE;
		try
		{
			$hasCol = (bool) \IPS\Db::i()->checkForColumn( 'gd_compliance_awb_rules', 'exemption_note' );
		}
		catch ( \Throwable )
		{
			$hasCol = FALSE;
		}
		if ( !$hasCol )
		{
			try
			{
				\IPS\Db::i()->addColumn( 'gd_compliance_awb_rules', [
					'name'           => 'exemption_note',
					'type'           => 'TEXT',
					'length'         => 0,
					'decimals'       => null,
					'allow_null'     => TRUE,
					'default'        => null,
					'auto_increment' => FALSE,
					'binary'         => FALSE,
					'unsigned'       => FALSE,
					'zerofill'       => FALSE,
					'values'         => [],
					'comment'        => 'free-text disclaimer surfaced in the front-end popup and ACP for this state\'s AWB flags',
				] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10607 addColumn exemption_note: ' . $e->getMessage(), 'gdcompliance_upg_10607' ); }
				catch ( \Throwable ) {}
				return FALSE;
			}
		}

		/* ---------- FIX 2: CT AWB rule — enable + seed exemption_note ----------
		   CT models were seeded in prior versions (v1.6.0 statutorySeed
		   includes CT with §53-202a citation). If CT rule doesn't exist
		   for some reason (partial prior install), fall back to the
		   canonical seeder which will INSERT the row with enabled=1 and
		   the exemption text. Otherwise UPDATE in place. */
		$ctExemption =
			"Restricted for sale to the general public under Connecticut's assault weapons law "
			. "(Conn. Gen. Stat. §53-202a et seq.). Limited exemptions may apply — including "
			. "active sworn law enforcement, qualified retired law enforcement, and military "
			. "personnel acting within official duties. Eligibility and all required "
			. "documentation must be verified by your FFL at the time of transfer. "
			. "This listing does not constitute a determination that any individual buyer qualifies.";

		try
		{
			$ctRule = null;
			try
			{
				$ctRule = \IPS\Db::i()->select( '*', 'gd_compliance_awb_rules',
					[ 'state_code=? AND firearm_class=?', 'CT', 'rifle' ] )->first();
			}
			catch ( \Throwable ) { $ctRule = null; }

			if ( is_array( $ctRule ) && isset( $ctRule['id'] ) )
			{
				\IPS\Db::i()->update( 'gd_compliance_awb_rules', [
					'enabled'                 => 1,
					'feature_count_threshold' => 1,
					'centerfire_only'         => 1,
					'max_overall_length_in'   => 30.0,
					'citation'                => 'Conn. Gen. Stat. §53-202a',
					'exemption_note'          => $ctExemption,
					'updated_at'              => time(),
				], [ 'id=?', (int) $ctRule['id'] ] );
			}
			else
			{
				\IPS\Db::i()->insert( 'gd_compliance_awb_rules', [
					'state_code'              => 'CT',
					'firearm_class'           => 'rifle',
					'feature_count_threshold' => 1,
					'centerfire_only'         => 1,
					'max_overall_length_in'   => 30.0,
					'min_capacity_fixed'      => 10,
					'citation'                => 'Conn. Gen. Stat. §53-202a',
					'effective_date'          => null,
					'expires_date'            => null,
					'enabled'                 => 1,
					'notes'                   => 'One-feature; also fixed-mag>10 and OAL<30 as independent triggers per §53-202a',
					'exemption_note'          => $ctExemption,
					'updated_at'              => time(),
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10607 CT rule: ' . $e->getMessage(), 'gdcompliance_upg_10607' ); }
			catch ( \Throwable ) {}
		}

		/* Refresh named-model seeding so CT's list (which was already
		   included in the v1.6.0 statutorySeed under §53-202a citation)
		   is verified present without duplicating. Non-destructive. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/AwbModels.php';
			\IPS\gdcompliance\AwbModels::seedMissingModels();
			\IPS\gdcompliance\AwbModels::clearCache();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10607 seedMissingModels: ' . $e->getMessage(), 'gdcompliance_upg_10607' ); }
			catch ( \Throwable ) {}
		}

		/* ---------- FIX 4: lang for new ACP field label ---------- */
		$newStrings = [
			'gdcompliance_acp_awbstates_exemption_note'      => 'Exemption note (customer disclaimer)',
			'gdcompliance_acp_awbstates_exemption_note_help' => 'Shown in the customer restriction popup below the reason and citation, and in the ACP UPC lookup. The AWB flag stays ON — this is a disclaimer pointing the buyer to verify eligibility with their FFL. Leave blank to hide.',
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

		/* ---------- cache purges ---------- */
		try { unset( \IPS\Data\Store::i()->settings ); }     catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }            catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
