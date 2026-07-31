<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.333 (unmatched-UPC AI-assist).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.333
 *   Two related additions to the unmatched-UPC review flow so
 *   Derrick spends less time typing when a dealer's feed snapshot
 *   was thin for a product (verified example: UPC 082442886459,
 *   a Beretta Optimachoke, whose snapshot had title/brand/image_url
 *   only — no mpn/model/msrp/caliber/description).
 *
 *   1. sources/Feed/Importer.php — capture the dealer's own
 *      product-page link (listing_url) into snapshot_json
 *      alongside the other fields, using the same $canonical /
 *      $raw probing pattern already in place for title/brand/etc.
 *
 *   2. modules/admin/dealers/unmatched.php — surface listing_url
 *      in $prefill so the ACP review screen can display it as a
 *      clickable reference. Add a new `do=fetchdetails` action:
 *      a CSRF-protected admin-triggered button (rendered only
 *      when listing_url is present AND at least one core field
 *      is missing from the snapshot) that:
 *        * fetches listing_url with realistic Chrome-on-Windows
 *          headers (reusing the proven gdrebates Parser.php
 *          v1.0.12 pattern — defeats basic UA sniffing / mild
 *          WAFs; does not defeat JS-challenge protection);
 *        * strips <script>/<style>/comment blocks BEFORE
 *          strip_tags so the ~350000-char budget is spent on
 *          real product copy (gdrebates Parser::callAnthropic
 *          v1.0.6 pattern);
 *        * sends the cleaned text to Claude with a product
 *          extraction prompt, requesting a JSON object with
 *          title/brand/mpn/model/msrp/caliber/description/
 *          image_url (nulls for missing);
 *        * FILL-BLANKS-ONLY merge into snapshot_json — never
 *          overwrites a field that already had a value from the
 *          original dealer feed;
 *        * flashes the count of newly-filled fields to the ACP
 *          admin on success, or a clear error to the admin on
 *          failure (never silently fails).
 *
 *   3. dev/html/admin/dealers/unmatchedUpcReview.phtml — new
 *      template parameters ($fetchDetailsUrl, $canFetch,
 *      $flashMessage), listing-URL reference display, flash-
 *      message banner, and the "Fetch details" button block.
 *
 *   API key: reuses the existing site-wide gdrebates_api_key
 *   setting (no new setting added — one shared Anthropic key
 *   across apps rather than fragmenting ownership per app).
 *   Same for the model default (falls back to
 *   claude-haiku-4-5-20251001 if gdrebates_model is empty).
 *
 * WHAT THIS UPGRADE DOES
 *   1. Re-seeds the new lang keys across every lang_id
 *      (Rule #43/#44 — 6-column core_sys_lang_words shape,
 *      per-row try/catch).
 *   2. Clears module / template / datastore / opcache so
 *      the new PHP + template body reach the browser on the
 *      next request. Template body ships as dev/html/admin/
 *      dealers/unmatchedUpcReview.phtml; IPS 5.0.18 reads it
 *      on next request after the caches are purged.
 *
 * NO schema change (listing_url lives inside the existing
 * snapshot_json JSON blob on gd_unmatched_upcs, not a new
 * column). NO CanonicalTemplates::ensure() call (standing
 * project rule this session; CanonicalTemplates::ensure has
 * been rewritten to only purge cached .tpl files anyway per
 * its class docstring). Rule #79: upg_10332 removed, exactly
 * one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10333;

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
		/* 1. Re-seed the new lang keys across every lang_id. */
		$strings = [
			'gddealer_unmatched_listing_url'   => 'Dealer listing URL',
			'gddealer_unmatched_fetch_details' => "Fetch details from dealer's listing",
			'gddealer_unmatched_fetch_success' => 'Fetched %s additional field(s) from the dealer listing.',
			'gddealer_unmatched_fetch_no_url'  => "This UPC's snapshot doesn't include a listing_url (older row, or the dealer feed omitted it). Nothing to fetch.",
			'gddealer_unmatched_fetch_no_key'  => 'No Anthropic API key configured (gdrebates_api_key setting is empty). Set it in ACP → Rebates → Settings and try again.',
			'gddealer_unmatched_fetch_fail'    => 'Could not fetch or extract details &mdash; check the gddealer log for the exact error and try filling the fields manually.',
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
							'word_app'     => 'gddealer',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'gddealer upg_10333 lang ' . $key . ': ' . $e->getMessage(), 'gddealer_upg_10333' ); } catch ( \Throwable ) {} }
				}
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gddealer upg_10333 lang loop: ' . $e->getMessage(), 'gddealer_upg_10333' ); } catch ( \Throwable ) {} }

		/* 2. Template + module + datastore cache purge so the new
		     .phtml body (with the fetch-details button + flash
		     banner + extra template parameters) is read on next
		     request. */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
