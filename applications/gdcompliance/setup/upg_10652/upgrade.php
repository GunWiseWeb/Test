<?php
/**
 * @brief  GD Compliance — upgrade 1.6.52
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.6.52 — consolidate the Manual Flag tool.
 *
 *   v1.6.51 added a Manual Flag tool INSIDE the Lowers ACP page
 *   (`modules/admin/compliance/lowers.php`). That worked but was
 *   scoped to lowers only, and — per the follow-up ticket —
 *   Derrick finds problem products by browsing the catalog, not
 *   from a category-specific admin page. v1.6.52 moves the tool
 *   to the Product Lookup page so it works for ANY product type
 *   with smart category narrowing.
 *
 *   NEW `sources/ReasonBuilder.php`
 *     Static class centralising the exact sprintf() patterns
 *     Engine::computeFlags() uses for every category's flag
 *     reason (awb_lower, awb rifle Tier 1/2, magazine, fixed-mag,
 *     melting_point, rate_of_fire, advisory passthrough). Pure
 *     functions — no DB, no side effects. The Manual Flag tool
 *     calls these so its rows are byte-identical to auto-flags.
 *
 *   MODIFIED `modules/admin/compliance/lookup.php`
 *     The UPC detail view now includes a "Manually flag / clear
 *     this product (per state, per category)" panel below the
 *     existing per-state override table. The panel narrows
 *     available compliance categories to what's plausible for
 *     the product's category_id (never shows Melting Point for
 *     a magazine, never shows Magazine Capacity for a stripped
 *     lower, etc.). Narrowing keys off the SAME constants each
 *     classifier uses (Lowers::CATEGORY_LOWER,
 *     Lowers::CATEGORY_FRAMES_JUNK, MeltingPoint::HANDGUN_CATEGORIES,
 *     Engine::TOP_LEVEL_TYPES via buildTypeMap()) plus locally-
 *     mirrored ammo/knife/magazine category lists. For each
 *     applicable category the panel queries the enabled
 *     per-state rule table and emits a (state × category)
 *     checkbox with the reason preview pre-filled from
 *     ReasonBuilder. Submit → new POST endpoint mflagApply()
 *     calls `Override::save()` per selected tuple with the
 *     right `firearm_type` so applyOne() upserts a
 *     gd_compliance_flags row instantly. Also clears any
 *     gd_compliance_review row for the UPC on success.
 *
 *   REMOVED from `modules/admin/compliance/lowers.php`
 *     v1.6.51's launcher card + mflag() action + supporting
 *     code are gone. The Lowers page returns to its original
 *     shape (summary + state chips + flagged list + curated
 *     overrides + tester). The Product Lookup page is now the
 *     single reachable home for manual flagging.
 *
 * NO schema changes (v1.6.51's firearm_type column on
 * gd_compliance_overrides is already present and correct).
 * NO CanonicalTemplates::ensure() call.
 *
 * upgrade step1() re-seeds the new lang keys (12 new
 * gdcompliance_acp_lookup_mflag_* strings) into
 * core_sys_lang_words per lang_id (rule #43, per-row try/catch
 * per rule #44) and clears caches so the dispatcher picks up
 * the new lookup.php + ReasonBuilder.php.
 *
 * Rule #79: upg_10651 removed, exactly one upg dir per app.
 */

namespace IPS\gdcompliance\setup\upg_10652;

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
		$this->seedLangStrings();
		$this->clearCaches();
		return TRUE;
	}

	protected function seedLangStrings(): void
	{
		$strings = [
			'gdcompliance_acp_lookup_mflag_title'        => 'Manually flag / clear this product (per state, per category)',
			'gdcompliance_acp_lookup_mflag_intro'        => 'Pick states and compliance categories. Reasons are auto-filled using the same sprintf() formatting Engine::computeFlags() uses — the manual flag is byte-identical to an auto-flag in gd_compliance_flags. Every override persists in gd_compliance_overrides, survives recomputes (Override::applyAll runs after every computeFlags), and immediately writes/removes the matching gd_compliance_flags row via applyOne().',
			'gdcompliance_acp_lookup_mflag_applies'      => 'Categories applicable to this product',
			'gdcompliance_acp_lookup_mflag_type'         => 'firearm type',
			'gdcompliance_acp_lookup_mflag_none'         => 'No compliance categories apply to this product\'s type / category.',
			'gdcompliance_acp_lookup_mflag_no_rules'     => 'No enabled rules for the applicable categories in any state.',
			'gdcompliance_acp_lookup_mflag_action_flag'  => 'Flag (force_restrict)',
			'gdcompliance_acp_lookup_mflag_action_clear' => 'Clear (force_clear)',
			'gdcompliance_acp_lookup_mflag_select_all'   => 'Select all',
			'gdcompliance_acp_lookup_mflag_select_none'  => 'Select none',
			'gdcompliance_acp_lookup_mflag_apply_now'    => 'Apply to selected (state, category) pairs',
			'gdcompliance_acp_lookup_mflag_apply_note'   => 'Applies instantly — no recompute needed. Writes to gd_compliance_overrides + gd_compliance_flags. Clears any pending review-queue row for this UPC.',
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
						try { \IPS\Log::log( 'upg_10652 lang ' . $key . ': ' . $e->getMessage(), 'gdcompliance_upg_10652' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10652 lang loop: ' . $e->getMessage(), 'gdcompliance_upg_10652' ); } catch ( \Throwable ) {}
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
