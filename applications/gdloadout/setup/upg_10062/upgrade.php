<?php
/**
 * @brief  GD Loadout — upgrade 1.0.62.
 *
 * WHAT SHIPS IN 1.0.62 — state compliance panel on the builder.
 *
 *   Adds a "State Compliance Check" panel above the JS loadout
 *   builder that reads gd_compliance_flags (READ-ONLY) for every
 *   item currently on the loadout and highlights whether each
 *   item is restricted / advisory-flagged in the buyer's chosen
 *   state, plus a build-level summary ("2 of 6 items restricted
 *   in Illinois"). Every item can also be expanded to show all
 *   states where it's flagged.
 *
 *   State persistence is a per-browser cookie (`gdlo_state`),
 *   set by a new `setComplianceState` action on the builder
 *   controller. No schema change; nothing new on gd_loadouts or
 *   gd_loadout_items.
 *
 *   gd_catalog + gd_compliance_flags remain READ-ONLY. The save /
 *   delete / search / suggest / hub-topic flows are byte-for-byte
 *   unchanged — this stage is purely additive display.
 *
 * Lang re-seed (20 new keys) per rules #43/#44. Cache clear.
 */

namespace IPS\gdloadout\setup\upg_10062;

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
		$v1062 = [
			'gdloadout_compliance_title'          => 'State Compliance Check',
			'gdloadout_compliance_state_label'    => 'Your state:',
			'gdloadout_compliance_select_state'   => '— Select a state —',
			'gdloadout_compliance_apply'          => 'Check this build',
			'gdloadout_compliance_empty'          => 'Add items to your loadout to see per-state compliance.',
			'gdloadout_compliance_pick_state'     => 'Select your state to check this build for restrictions.',
			'gdloadout_compliance_summary_restricted' => '⛔ {x} of {total} items in this loadout are restricted in {state}.',
			'gdloadout_compliance_summary_advisory'   => 'ⓘ {y} items have buyer requirements in {state}.',
			'gdloadout_compliance_summary_clear'      => '✓ No items in this build are restricted in {state}.',
			'gdloadout_compliance_badge_restricted'   => 'Restricted',
			'gdloadout_compliance_badge_advisory'     => 'Buyer requirement',
			'gdloadout_compliance_badge_clear'        => 'No known restrictions',
			'gdloadout_compliance_badge_clear_here'   => 'Clear here',
			'gdloadout_compliance_item_pick_state'    => 'Restricted in %d state(s). Select your state above to check.',
			'gdloadout_compliance_item_other_states'  => 'Restricted in %d other state(s) — expand for details.',
			'gdloadout_compliance_expand_all'         => 'All flagged states',
			'gdloadout_compliance_all_restricted'     => 'restricted',
			'gdloadout_compliance_all_advisory'       => 'buyer requirement',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $v1062 as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdloadout',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
