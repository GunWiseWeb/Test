<?php
/**
 * @brief  GD Compliance — upgrade 1.6.23
 *
 * NOTE ON VERSION — the corresponding prompt asked for 1.6.22, but
 * v1.6.22 already shipped as the state-lookup Stage 1 (which is the
 * ship this version corrects). Next available number is 1.6.23.
 *
 * ROOT-CAUSE CORRECTION to v1.6.22:
 *
 * The state-lookup result page was rendering literal "UPC %s:" and
 * "in %s" instead of the real UPC / state name because the controller
 * used PHP sprintf on the result of \IPS\Member::loggedIn()->
 * language()->addToStack(...):
 *
 *   sprintf( (string) $lang->addToStack('key'), $arg )    // BROKEN
 *
 * addToStack() returns a DEFERRED language placeholder token, not
 * the resolved string. IPS substitutes the real lang value at output
 * resolution — by which time PHP sprintf has already run against the
 * placeholder token and gone. So the %s inside the real lang value
 * ("UPC %s:") never gets its argument.
 *
 * Fix: use IPS's native sprintf param:
 *
 *   $lang->addToStack('key', FALSE, [ 'sprintf' => [ $arg ] ])
 *
 * All 8 sprintf() call sites in modules/front/lookup/lookup.php are
 * converted (0 sprintf calls remain in the file).
 *
 * Also seeds the full lookup lang-key set — including the new
 * gdcompliance_lookup_norestrict_headline that the corrected code
 * uses for the "No restrictions found" green headline — via
 * core_sys_lang_words replace per-lang.
 *
 * No schema changes. No engine changes.
 */

namespace IPS\gdcompliance\setup\upg_10623;

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
		/* Defensive re-seed of v1.6.22 settings — the same
		   changeValues call is idempotent, ensures a broken 1.6.22
		   install still gets them. */
		$defaultDisclaimer =
			"State Firearm Compliance Lookup — Important Notice. This tool provides general information based on our product catalog and our understanding of current state law. It is not legal advice and is not a guarantee of legality. Firearm laws change frequently, vary by locality, and depend on individual circumstances. A result of 'no restrictions found' means our system did not flag this item for the selected state — it does not affirmatively certify the item is legal for you to purchase or possess. Always verify with your FFL and consult current state and local law before completing any purchase or transfer. Gun Wise LLC assumes no liability for reliance on this tool.";

		try
		{
			$currentDisclaimer = (string) ( \IPS\Settings::i()->gdcompliance_lookup_disclaimer ?? '' );
			$changes = [];
			if ( $currentDisclaimer === '' )
			{
				$changes['gdcompliance_lookup_disclaimer'] = $defaultDisclaimer;
			}
			if ( !isset( \IPS\Settings::i()->gdcompliance_lookup_enabled ) )
			{
				$changes['gdcompliance_lookup_enabled'] = 1;
			}
			if ( !empty( $changes ) )
			{
				\IPS\Settings::i()->changeValues( $changes );
			}
		}
		catch ( \Throwable ) {}

		/* ---------- Full lang seed / re-seed — every gdcompliance_lookup_*
		   key referenced by the corrected controller. Placeholder
		   count in each value must match the addToStack sprintf arg
		   count. Per-row try/catch (rule #44). ---------- */
		$newStrings = [
			/* ACP settings section */
			'gdcompliance_acp_settings_lookup_header'   => 'Public State Compliance Lookup (/state-lookup/)',
			'gdcompliance_lookup_enabled'               => 'Publish the /state-lookup/ page',
			'gdcompliance_lookup_enabled_desc'          => 'When off, visitors to /state-lookup/ see a "temporarily unavailable" notice instead of the lookup form.',
			'gdcompliance_lookup_disclaimer'            => 'Public disclaimer',
			'gdcompliance_lookup_disclaimer_desc'       => 'Shown at the top of the /state-lookup/ page. Legal-guidance framing recommended — this is customer-facing.',

			/* Public page — page chrome */
			'gdcompliance_lookup_page_title'            => 'State Firearm Compliance Lookup',
			'gdcompliance_lookup_intro'                 => 'Pick your state and enter a UPC or MPN to check whether that item is restricted for sale in your state. Read-only against our current catalog.',
			'gdcompliance_lookup_disclaimer_label'      => 'Important Notice',
			'gdcompliance_lookup_default_disclaimer'    => $defaultDisclaimer,
			'gdcompliance_lookup_field_state'           => 'Ship-to state',
			'gdcompliance_lookup_pick_state'            => 'Pick a state…',
			'gdcompliance_lookup_field_q'               => 'UPC or MPN',
			'gdcompliance_lookup_field_q_ph'            => 'e.g. 022188879834',
			'gdcompliance_lookup_submit'                => 'Look up',

			/* Result strings — %s placeholders that IPS native sprintf fills */
			'gdcompliance_lookup_product'               => 'UPC %s:',
			'gdcompliance_lookup_citation'              => 'Citation: %s',
			'gdcompliance_lookup_restricted_headline'   => 'Restricted for sale in %s',
			'gdcompliance_lookup_advisory_label'        => 'Buyer requirement',
			'gdcompliance_lookup_advisory_headline'     => 'Buyer requirement in %s',
			'gdcompliance_lookup_advisory_intro'        => 'This item can ship. The buyer must meet a state permit / training requirement at the FFL. Not a sale prohibition.',
			'gdcompliance_lookup_norestrict_headline'   => 'No restrictions found in %s',
			'gdcompliance_lookup_clear_body'            => 'No restrictions found for this item in %s.',
			'gdcompliance_lookup_clear_reminder'        => 'This is not a legal guarantee. Verify with your FFL and consult current state and local law before completing any purchase.',
			'gdcompliance_lookup_verify_reminder'       => 'This reflects our current data. Verify with your receiving FFL before purchase.',
			'gdcompliance_lookup_not_found'             => 'We could not find %s in our catalog, so we have no compliance information for it.',
			'gdcompliance_lookup_not_found_hint'        => 'Double-check the UPC / MPN. Only items currently in our catalog are covered by this tool.',
			'gdcompliance_lookup_disabled_msg'          => 'The state-lookup page is temporarily unavailable. Please check back later.',
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

		/* ---------- Cache purges — the lang cache MUST be cleared so
		   the re-seeded rows land immediately. ---------- */
		try { unset( \IPS\Data\Store::i()->lang ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpmenu ); }            catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->canonical_templates ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
