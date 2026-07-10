<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.330 (bot filter + geo template + UA col).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHY v1.0.330 EXISTS:
 *   Two dealer analytics bugs reported together.
 *
 *   1. GEO STATE BREAKDOWN — the analytics template rendered
 *      the click count and the percentage in two adjacent
 *      spans with no textual separator, so when the CSS grid
 *      layout failed on prod the text collapsed into a
 *      single string ("CA 3066.7%" instead of the intended
 *      "CA 30 clicks (66.7%)"). Fixed by adding the word
 *      "clicks" to the count span and wrapping the percentage
 *      in parentheses in the template body — the text is
 *      readable now even with zero CSS. See
 *      dev/html/front/dealers/analytics.phtml and the overlay
 *      setup/templates_10078.php (both edited to match).
 *      This upgrade re-seeds the analytics template DB row
 *      from the overlay so existing installs get the fix.
 *
 *   2. BOT CLICK POLLUTION — modules/front/dealers/click.php
 *      was logging every hit including crawlers, empty-UA
 *      scripts, and repeat visits, inflating dealer click
 *      counts (verified: single IPs hit 30+ distinct items
 *      inside 3 minutes). click.php now:
 *        * Reads $_SERVER['HTTP_USER_AGENT'] and treats empty
 *          UA or any of ~25 known-bot substrings (bot, crawl,
 *          spider, curl, wget, gptbot, bytespider, etc.) as
 *          a bot. Bots are still REDIRECTED to the dealer
 *          (never break click-through) but nothing is written
 *          to gd_click_log / gd_click_daily / listing counters.
 *        * Dedupes non-bot clicks within a 30-minute window
 *          on (dealer_id, upc, ip_hash). Same visitor + same
 *          listing + inside 30min → skip logging + rollup.
 *        * Captures the truncated UA (max 255 chars) into a
 *          new gd_click_log.user_agent column for future
 *          auditing. click.php's insert falls back to the
 *          pre-330 shape if the column doesn't exist yet
 *          (schema-lag safe).
 *
 *   This upgrade adds:
 *     - gd_click_log.user_agent VARCHAR(255) NULL (guarded
 *       ALTER — DESCRIBE-first check via checkForColumn).
 *     - re-seed of the analytics template (require_once the
 *       overlay file, whose replace() call is idempotent).
 *     - cache purge (module dispatcher + template caches).
 *
 * NO CanonicalTemplates::ensure() call — per current architecture
 * ensure() only purges .tpl caches and doesn't rewrite DB rows,
 * and a prior version's identical call wiped the dashboard. We
 * re-seed the ONE template we need by requiring the overlay
 * directly, which mirrors what install.php does for fresh
 * installs. No other templates are touched.
 *
 * MANUAL CLEANUP (NOT AUTOMATED — do NOT run in this upgrade):
 *   The 400+ existing gd_click_log rows for early dealers are
 *   mostly crawlers (before v1.0.330 they were logged
 *   unfiltered). Derrick can review + purge with:
 *
 *     -- 1) identify suspect ip_hashes: > 15 distinct UPCs
 *     --    logged within any 10-minute window per dealer.
 *     SELECT dealer_id, ip_hash, COUNT(DISTINCT upc) AS items,
 *            MIN(clicked_at) AS first_seen,
 *            MAX(clicked_at) AS last_seen
 *     FROM   gd_click_log
 *     WHERE  ip_hash IS NOT NULL
 *     GROUP  BY dealer_id, ip_hash
 *     HAVING items > 15
 *        AND TIMESTAMPDIFF(MINUTE, first_seen, last_seen) < 10
 *     ORDER  BY items DESC;
 *
 *     -- 2) after review, delete the offending rows:
 *     DELETE FROM gd_click_log
 *     WHERE  ip_hash IN ( ... hashes from step 1 ... );
 *
 *     -- 3) rebuild daily rollups from the cleaned log:
 *     TRUNCATE gd_click_daily;
 *     INSERT INTO gd_click_daily (dealer_id, click_date, click_count)
 *     SELECT dealer_id, DATE(clicked_at), COUNT(*)
 *     FROM   gd_click_log
 *     GROUP  BY dealer_id, DATE(clicked_at);
 *
 *   This is documented, not executed — the upgrade never
 *   mass-deletes user-visible data automatically.
 */

namespace IPS\gddealer\setup\upg_10330;

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
		/* ------------------------------------------------------------
		 * 1. Guarded ALTER — add gd_click_log.user_agent VARCHAR(255)
		 *    NULL if not already present. Idempotent (rule #22): a
		 *    re-run finds the column and does nothing.
		 * ------------------------------------------------------------ */
		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_click_log', 'user_agent' ) )
			{
				try
				{
					\IPS\Db::i()->addColumn( 'gd_click_log', [
						'name'       => 'user_agent',
						'type'       => 'VARCHAR',
						'length'     => 255,
						'allow_null' => TRUE,
						'default'    => NULL,
					] );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'gddealer upg_10330 addColumn user_agent: ' . $e->getMessage(), 'gddealer_upg_10330' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer upg_10330 checkForColumn user_agent: ' . $e->getMessage(), 'gddealer_upg_10330' ); } catch ( \Throwable ) {}
		}

		/* ------------------------------------------------------------
		 * 2. Re-seed the analytics template from the v10078 overlay.
		 *    The overlay contains ONE template (analytics) and uses
		 *    \IPS\Db::i()->replace('core_theme_templates', [...]),
		 *    which is idempotent — safe to re-run. This is the same
		 *    file install.php requires for fresh installs (rule #52).
		 *    No other templates are touched. NO ensure() call.
		 * ------------------------------------------------------------ */
		try
		{
			$overlay = \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10078.php';
			if ( is_file( $overlay ) )
			{
				require_once $overlay;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer upg_10330 reseed analytics: ' . $e->getMessage(), 'gddealer_upg_10330' ); } catch ( \Throwable ) {}
		}

		/* ------------------------------------------------------------
		 * 3. Cache purge — settings + module dispatchers + template
		 *    caches must re-resolve so the new click.php PHP dispatches
		 *    and the new analytics template body renders on next hit.
		 * ------------------------------------------------------------ */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                             catch ( \Throwable ) {}
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
