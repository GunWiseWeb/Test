<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.334 (listing_url pipeline fix).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.334
 *   Root-cause fix for the listing_url capture regression reported
 *   against v1.0.333. That version's snapshot builder was
 *   supposed to store dealer product-page URLs into
 *   gd_unmatched_upcs.snapshot_json (unlocking the "Fetch details
 *   from dealer's listing" AI-assist button), but real production
 *   data from dealer 4 (Defense Depot, LitCommerce XML) showed
 *   ALL 10 recent unmatched UPCs had NO listing_url key even for
 *   rows imported after v1.0.333 deployed.
 *
 *   Reproduced against the XmlParser locally: parser produces flat
 *   $raw['<tag>'] entries. Dealer 4's mapping says the URL raw
 *   field is called `url`, but the actual feed uses one of the
 *   many other common URL naming conventions (link, permalink,
 *   productLink, page_url, href, external_url, item_url, ...);
 *   v1.0.333's rawProbe covered only 5 of these, so the specific
 *   name dealer 4's feed uses was missed silently.
 *
 *   Three-layer fix, so any of the three catches the URL if the
 *   layer above missed it:
 *
 *   Layer 1 — CanonicalFields::suggestionDictionary()
 *     Expanded the `url` alias list from 11 → 22 entries covering
 *     LitCommerce/Shopify/WordPress/BigCommerce/WooCommerce
 *     naming (permalink, product_page_url, item_url, item_link,
 *     page_link, catalog_url, merchant_url, storefront_url,
 *     source_url, canonical_url, shop_url, web_url). Adding a
 *     variant here is now the SINGLE PLACE to teach the whole
 *     pipeline about a new URL tag — the mapping wizard, the
 *     mapper auto-recovery, and the snapshot fallback all read
 *     this dictionary.
 *
 *   Layer 2 — FieldMapper::apply() URL auto-recovery
 *     If the dealer's field_mapping specifies `"raw":"url"` but
 *     $record[raw] doesn't exist in the parsed feed, fall through
 *     to a URL-alias scan of the raw record before giving up.
 *     Scoped tightly to the `url` canonical so it can't
 *     mis-populate any other field. Recovers `$canonical['listing_url']`
 *     for dealers whose mapping config is out of sync with the
 *     feed's actual tag names (typical for wizard misconfig or
 *     mid-life feed schema drift).
 *
 *   Layer 3 — Importer::extractSnapshot() rawProbe expansion
 *     The snapshot's rawProbe candidate list is now sourced from
 *     the expanded CanonicalFields URL alias set (not the fixed
 *     5-entry list from v1.0.333). Even if Layers 1+2 both miss,
 *     the snapshot itself gets one last chance to surface the URL
 *     for the ACP admin.
 *
 *   Diagnostic bonus — extractSnapshot() also logs the raw record's
 *   key list to core_log category 'gddealer_snapshot_no_url' when
 *   a product's title is captured but no URL-like field was found
 *   anywhere. Actionable: Derrick sees the exact key name in the
 *   log, we add it to CanonicalFields, one release fixes future
 *   dealers with the same schema.
 *
 * WHAT THIS UPGRADE DOES
 *   Cache / datastore / opcache clear so the updated Importer,
 *   FieldMapper, and CanonicalFields PHP loads on the next
 *   request / scheduled import.
 *
 *   Note: existing gd_unmatched_upcs.snapshot_json rows are NOT
 *   backfilled here — that would require re-fetching every
 *   dealer's feed. The next scheduled import (or a manual ACP
 *   "Import now") rewrites snapshot_json for every UPC seen on
 *   that run per UnmatchedUpc::record()'s upsert path (line 82-85),
 *   so recent dealer-4 rows will get their listing_url on the
 *   next import.
 *
 * NO schema change. NO template touched. NO CanonicalTemplates::
 * ensure() call (standing project rule this session).
 * Rule #79: upg_10333 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10334;

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
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
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
