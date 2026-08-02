<?php
/**
 * @brief  GD Catalog — upgrade 1.0.115 (pagination on Platform Review queue).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.115
 *   The v1.0.113 Platform Review admin page had a hardcoded LIMIT
 *   of 100 rows and no pagination controls — so with 755 pending
 *   review items on Derrick's queue, 655 items were completely
 *   unreachable. $reviewCount was fetched correctly (so the true
 *   total was known), but nothing used it to render a pager.
 *
 *   modules/admin/catalog/platformreview.php manage() now reads
 *   a `page` request param, computes the offset dynamically
 *   (`( $page - 1 ) * $per`, per=50), and passes $page /
 *   $totalPages / $per / $pageBaseUrl to the template. Same
 *   pattern used across the other admin lists this session.
 *
 *   dev/html/admin/catalog/platformReview.phtml gains a
 *   pagination bar ABOVE the review table (with row-range +
 *   Prev/Next + jump-to-page form) and a compact one BELOW
 *   (Prev/Next only), plus new defaulted template parameters
 *   ($page=1, $totalPages=1, $per=50, $pageBaseUrl='') so a
 *   theme that hasn't been reseeded doesn't error.
 *
 *   The overrides list stays a fixed 50-cap — admin-curated,
 *   unlikely to grow to needing a pager. Commented as such
 *   in the controller so a future maintainer knows it's
 *   intentional, not overlooked.
 *
 *   Row action URLs (reassign / confirm) still reference each
 *   row's actual id, so navigating to page 7, clicking
 *   "→ Rifles" on a row, and getting redirected back keeps
 *   the same page context via the flash / query preservation.
 *
 * WHAT THIS UPGRADE DOES
 *   Cache / template / opcache purge so the updated controller
 *   PHP and template body load on next request.
 *
 * NO schema change. NO lang change (all new UI text is inline
 * in the template — "Page X of Y", "Prev", "Next", "Jump to"
 * are trivial and un-i18n-worthy at this admin-page scale).
 * Rule #79: upg_10114 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10115;

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
