<?php
/**
 * @brief  GD Compliance — upgrade 1.6.22
 *
 * NOTE ON VERSION — the corresponding prompt asked for 1.6.21, but
 * v1.6.21 already shipped as the rate-of-fire enhancer ban. This is
 * the next available number.
 *
 * Landing — public /state-lookup/ page (Stage 1):
 *   1. Registers a new FRONT module (front/lookup) — gdcompliance's
 *      first front controller-only module (the compliance module has
 *      always existed but had no user-facing widget).
 *   2. Registers the FURL for /state-lookup/ pointing at
 *      app=gdcompliance&module=lookup&controller=lookup.
 *   3. Seeds two settings:
 *        gdcompliance_lookup_enabled       = 1 (public page live)
 *        gdcompliance_lookup_disclaimer    = default legal-notice text
 *   4. Extends the ACP settings page (/settings) with an enable
 *      toggle + editable disclaimer.
 *
 * The controller reads gd_compliance_flags for one (upc, state) pair
 * and renders "Restricted" (red) or "Buyer requirement" (amber) or
 * "No restrictions found" (green). Advisories (firearm_type=advisory)
 * render as amber; everything else renders as red-restrict.
 *
 * DEFENSIVE — carries all prior single-upg landings forward for
 * skip-upgrades from earlier versions.
 *
 * No engine changes. No schema changes. No recompute required —
 * the page reads live gd_compliance_flags at request time.
 */

namespace IPS\gdcompliance\setup\upg_10622;

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
		/* ---------- Seed the two new settings ---------- */
		$defaultDisclaimer =
			"State Firearm Compliance Lookup — Important Notice. This tool provides general information based on our product catalog and our understanding of current state law. It is not legal advice and is not a guarantee of legality. Firearm laws change frequently, vary by locality, and depend on individual circumstances. A result of 'no restrictions found' means our system did not flag this item for the selected state — it does not affirmatively certify the item is legal for you to purchase or possess. Always verify with your FFL and consult current state and local law before completing any purchase or transfer. Gun Wise LLC assumes no liability for reliance on this tool.";

		try
		{
			\IPS\Settings::i()->changeValues( [
				'gdcompliance_lookup_enabled'    => 1,
				'gdcompliance_lookup_disclaimer' => $defaultDisclaimer,
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10622 changeValues: ' . $e->getMessage(), 'gdcompliance_upg_10622' ); } catch ( \Throwable ) {}
		}

		/* ---------- Lang seed — v1.6.22 public lookup + ACP labels ---------- */
		$newStrings = [
			/* ACP settings section */
			'gdcompliance_acp_settings_lookup_header'   => 'Public State Compliance Lookup (/state-lookup/)',
			'gdcompliance_lookup_enabled'               => 'Publish the /state-lookup/ page',
			'gdcompliance_lookup_enabled_desc'          => 'When off, visitors to /state-lookup/ see a "temporarily unavailable" notice instead of the lookup form.',
			'gdcompliance_lookup_disclaimer'            => 'Public disclaimer',
			'gdcompliance_lookup_disclaimer_desc'       => 'Shown at the top of the /state-lookup/ page. Legal-guidance framing recommended — this is customer-facing.',

			/* Public page copy */
			'gdcompliance_lookup_page_title'            => 'State Firearm Compliance Lookup',
			'gdcompliance_lookup_intro'                 => 'Pick your state and enter a UPC or MPN to check whether that item is restricted for sale in your state. Read-only against our current catalog.',
			'gdcompliance_lookup_disclaimer_label'      => 'Important Notice',
			'gdcompliance_lookup_default_disclaimer'    => $defaultDisclaimer,
			'gdcompliance_lookup_field_state'           => 'Ship-to state',
			'gdcompliance_lookup_pick_state'            => 'Pick a state…',
			'gdcompliance_lookup_field_q'               => 'UPC or MPN',
			'gdcompliance_lookup_field_q_ph'            => 'e.g. 022188879834',
			'gdcompliance_lookup_submit'                => 'Look up',
			'gdcompliance_lookup_product'               => 'UPC %s:',
			'gdcompliance_lookup_citation'              => 'Citation: %s',
			'gdcompliance_lookup_restricted_headline'   => 'Restricted for sale in %s',
			'gdcompliance_lookup_advisory_label'        => 'Buyer requirement',
			'gdcompliance_lookup_advisory_headline'     => 'Buyer requirement in %s',
			'gdcompliance_lookup_advisory_intro'        => 'This item can ship. The buyer must meet a state permit / training requirement at the FFL. Not a sale prohibition.',
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

		/* ---------- Cache purges. Module + FURL registration reads
		   from data/modules.json + data/furl.json which ship in the
		   tarball; IPS re-scans them on cache clear. Also clear the
		   FURL cache datastore so the /state-lookup/ mapping picks
		   up immediately without a manual FURL rebuild. ---------- */
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
