<?php
/**
 * @brief  GD Rebates — upgrade 1.0.12
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.12 — Parser::fetchPage() realistic browser headers
 * + error logging.
 *
 *   sources/Parser.php fetchPage() replaces the single
 *     'User-Agent' => 'GunRack-Rebates/1.0'
 *   header with a full Chrome-on-Windows header set (UA + Accept
 *   + Accept-Language + Accept-Encoding + full sec-ch-ua family
 *   + Sec-Fetch-* + Upgrade-Insecure-Requests). Also switches
 *   the silent catch to log the URL + exception to core_log
 *   category 'gdrebates' so future fetch failures surface
 *   without a manual CLI reproduction.
 *
 *   Expected wins:
 *     * Manufacturer sites with basic UA sniffing / lightweight
 *       WAF rules that were 403'ing the old obvious-scraper UA
 *       may now respond normally.
 *   Explicitly NOT in scope:
 *     * JS-challenge bot protection (Cloudflare, Incapsula,
 *       PerimeterX, etc.). Those require actual JavaScript
 *       execution (headless browser) to solve. Beretta and a
 *       few similar sources will keep failing after this
 *       upgrade — that's expected, not a regression.
 *
 * No schema. No lang. No template changes. Cache clear so the
 * updated Parser.php PHP loads on the next request / task run.
 *
 * NO CanonicalTemplates::ensure() call.
 * Rule #79: upg_10011 removed, exactly one upg dir per app.
 */

namespace IPS\gdrebates\setup\upg_10012;

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
