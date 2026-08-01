<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.335 (grouped category dropdown).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.335
 *   The unmatched-UPC review form's category dropdown was rendering
 *   every category (including subcategories like "Revolvers",
 *   "Pistols", "Derringers") as one FLAT alphabetical list with
 *   no hierarchy — so an admin couldn't tell which Handguns
 *   subcategory was which vs. picking the generic "Handguns"
 *   parent by mistake. That matters now because gdsearch v1.0.85's
 *   new facets filter by exact category_id (Revolvers=3 vs
 *   Handguns=1 are different IDs — assigning to the wrong one
 *   means a product won't show under category-specific filters).
 *
 *   Fix:
 *   * modules/admin/dealers/unmatched.php form() method now
 *     builds a $categoriesByParent structure alongside the
 *     existing flat $categories lookup, and passes both to the
 *     template. Nothing else touches the review flow — this is
 *     purely a presentation-data change.
 *   * dev/html/admin/dealers/unmatchedUpcReview.phtml now accepts
 *     $categoriesByParent as a defaulted param and renders the
 *     dropdown as native <optgroup>s (one per top-level category)
 *     with the top-level itself as a selectable "(general)"
 *     option plus each subcategory as an indented sibling. Falls
 *     back to the old flat list if $categoriesByParent is empty
 *     (defence against a not-yet-reseeded theme).
 *
 * WHAT THIS UPGRADE DOES
 *   Cache / template / opcache purge so the new PHP + updated
 *   .phtml body reach the browser on the next request. gddealer
 *   templates ship via dev/html/ and IPS 5.0.18 reads that path
 *   directly after the compiled template cache is dropped.
 *
 * NO schema change. NO lang change. NO submit-handler change
 * (form still POSTs `category_id` — the value shape is
 * unchanged; only rendering grouped). NO CanonicalTemplates::
 * ensure() call (standing project rule this session).
 * Rule #79: upg_10334 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10335;

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
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
