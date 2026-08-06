<?php
/**
 * @brief  GD Search — upgrade 1.0.87 (Buy Now referrerpolicy=strict-origin).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.87
 *   The v1.0.336 (gddealer) Traffic Sources dashboard revealed
 *   99.4% of dealer clicks were being categorized "Direct / No
 *   Referrer" — despite the Buy Now link only existing on
 *   gunrack.deals pages (so a real click should almost always
 *   carry a same-origin referrer). Real-traffic sample confirmed
 *   an iOS Safari click lost the referrer despite being
 *   same-origin.
 *
 *   Investigation (per ticket step 1): gddealer/modules/front/
 *   dealers/click.php reads $_SERVER['HTTP_REFERER'] on the
 *   INBOUND request to /dealers/click/ (BEFORE its outbound 302
 *   redirect to the dealer's external URL). That's the correct
 *   lifecycle point — same-origin browser navigation should
 *   preserve the referrer under the site's global
 *   Referrer-Policy: strict-origin-when-cross-origin. Capture
 *   logic is NOT a code bug.
 *
 *   Root cause: browser behavior on the FIRST hop, specifically
 *   Safari's known quirk of suppressing the Referer header more
 *   aggressively than the response-header referrer-policy would
 *   imply when the link uses target="_blank" + immediately hits
 *   a redirect. Site's global policy header can't override this
 *   at the browser level; the link-level `referrerpolicy`
 *   attribute can.
 *
 *   Fix: add referrerpolicy="strict-origin" directly on the
 *   Buy Now anchor in dev/html/front/search/product.phtml.
 *   Tells the browser explicitly: "when navigating via this
 *   link, send at minimum the origin as referrer, regardless
 *   of target=_blank + redirect quirks". Ensures at-least-origin
 *   survives, categorizing clicks correctly as "Internal
 *   (Gunrack)" in the dashboard breakdown.
 *
 *   Sacrifice: per-page path detail is lost (Detail sub-list will
 *   show "/" for every internal click rather than actual paths),
 *   because strict-origin sends origin-only (no path). Bucket
 *   categorization becomes reliable, which is the primary goal.
 *   Full-path detail could be recovered later by switching to
 *   `no-referrer-when-downgrade` if Derrick wants the granularity
 *   more than he wants the tighter referrer-privacy posture on
 *   the outbound-to-dealer redirect.
 *
 *   Global Referrer-Policy header UNCHANGED — the fix is scoped
 *   to just this one link, not a site-wide policy weakening.
 *
 * WHAT THIS UPGRADE DOES
 *   Re-seeds the product.phtml template row in core_theme_templates
 *   by require_once'ing setup/templates_10046.php (its existing
 *   $gdsearchSeedProductTemplate closure reads the freshly-shipped
 *   .phtml and replace()s the DB row — same helper used on fresh
 *   installs). Then a template/module/cache/opcache purge so the
 *   new anchor markup reaches the browser on the next request.
 *
 * NO schema change. NO lang change. gddealer click.php UNTOUCHED
 * (investigation confirmed capture logic is correct). Rule #79:
 * upg_10086 removed, exactly one upg dir per app.
 */

namespace IPS\gdsearch\setup\upg_10087;

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
		/* 1. Re-seed the product.phtml template row by re-running
		     the existing seed helper from setup/templates_10046.php
		     (reads the freshly-shipped .phtml and replaces the
		     core_theme_templates row — same helper install.php uses
		     on fresh installs, so both paths converge). */
		try
		{
			$seedFile = \IPS\ROOT_PATH . '/applications/gdsearch/setup/templates_10046.php';
			if ( is_file( $seedFile ) )
			{
				require_once $seedFile;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10087 reseed product template: ' . $e->getMessage(), 'gdsearch_upg_10087' ); } catch ( \Throwable ) {}
		}

		/* 2. Template + module + cache + opcache purge. */
		try { \IPS\Theme::deleteCompiledTemplate(); }              catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_cache' ); }              catch ( \Throwable ) {}
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
