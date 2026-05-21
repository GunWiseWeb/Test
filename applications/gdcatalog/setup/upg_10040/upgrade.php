<?php
namespace IPS\gdcatalog\setup\upg_10040;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) {
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}
class _upgrade
{
	public function step1(): bool
	{
		/* v1.0.40 — Template param mismatches + feed CSRF fixes:
		 * 1. dashboard.phtml: 11 params → 10 (dropped $categoryCounts,
		 *    $pendingCompliance; added $taskUrls; reordered)
		 * 2. productList.phtml: 10 params → 14 (added $imageStatus,
		 *    $addProductUrl, $importCsvUrl, $downloadCsvTemplateUrl)
		 * 3. feedList.phtml: 2 params → 5 (added $addUrl, $reorderUrl, $csrfKey)
		 * 4. feeds.php: removed ->csrf() on edit/add GET URLs,
		 *    wrapped csrfCheck() in POST guard for add() and edit()
		 * No DB changes — source-only fixes. */
		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
