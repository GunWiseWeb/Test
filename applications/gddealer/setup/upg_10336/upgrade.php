<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.336 (click referrer capture + dashboard).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.336
 *   Traffic-source analysis this session couldn't be done because
 *   Apache access logs only retain ~1 day locally (confirmed —
 *   main log had 8,561 lines from today only, no rotated raw
 *   copies). Fix the root cause: capture HTTP referrer on every
 *   dealer click straight into gd_click_log so history accumulates
 *   permanently, and surface a Traffic Sources breakdown in the
 *   dealer analytics dashboard.
 *
 *   Three changes:
 *   1. NEW column gd_click_log.referrer VARCHAR(500) NULL —
 *      guarded ALTER (checkForColumn → addColumn).
 *   2. modules/front/dealers/click.php captures
 *      $_SERVER['HTTP_REFERER'] (trimmed, truncated to 500) and
 *      passes it into the existing bot-filtered insert. The
 *      existing insert already had a schema-lag fallback for
 *      user_agent (v1.0.330); we now nest THREE tiers: full
 *      insert → drop referrer → drop user_agent → minimal. Bot
 *      filter untouched (Derrick verified working via real
 *      traffic analysis this session — don't disturb).
 *   3. modules/front/dealers/dashboard.php analytics() computes
 *      a $trafficSources bucket list at DISPLAY time (raw
 *      referrer stored at capture time; categorization runs at
 *      render so buckets can improve later without needing to
 *      recapture history). Buckets: Direct / No Referrer,
 *      Internal (Gunrack), Search Engine, Social Media,
 *      External — {hostname}. Cap 12 rows to keep the tail
 *      readable for chatty dealers.
 *   4. dev/html/front/dealers/analytics.phtml renders a new
 *      Traffic Sources panel mirroring the existing Top-states
 *      geo panel styling — same gdGeoList / gdGeoRow layout for
 *      visual consistency with the rest of the analytics page.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Guarded ALTER — add the referrer column via
 *      checkForColumn / addColumn. Idempotent — safe to re-run.
 *   2. Re-seed the three new lang keys across every lang_id
 *      (Rule #43/#44 — 6-column core_sys_lang_words shape,
 *      per-row try/catch).
 *   3. Cache purge so the updated PHP + template body load
 *      on next request. Template body ships via dev/html/
 *      (rule #59 pattern — no CanonicalTemplates::ensure()
 *      per session standing rule).
 *
 * NO destructive change. No historical backfill (server logs
 * are gone; referrer starts accumulating from this deploy
 * forward). Rule #79: upg_10335 removed, exactly one upg dir
 * per app.
 */

namespace IPS\gddealer\setup\upg_10336;

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
		/* 1. Guarded ALTER — add referrer column. */
		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_click_log', 'referrer' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_click_log', [
					'name'       => 'referrer',
					'type'       => 'VARCHAR',
					'length'     => 500,
					'allow_null' => TRUE,
					'default'    => NULL,
				] );
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10336 addColumn referrer: ' . $e->getMessage(), 'gddealer_upg_10336' ); } catch ( \Throwable ) {} }

		/* 2. Re-seed new lang keys across every lang_id. */
		$strings = [
			'gddealer_traffic_sources_title' => 'Traffic Sources',
			'gddealer_traffic_sources_help'  => 'Where visitors were BEFORE they clicked one of your listings. Captured per-click from HTTP referrer.',
			'gddealer_traffic_sources_empty' => 'No referrer data yet for this range. Referrer capture began v1.0.336 &mdash; historical clicks from before that upgrade have no referrer recorded.',
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
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10336 lang ' . $key . ': ' . $e->getMessage(), 'gddealer_upg_10336' ); } catch ( \Throwable ) {} }
				}
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10336 lang loop: ' . $e->getMessage(), 'gddealer_upg_10336' ); } catch ( \Throwable ) {} }

		/* 3. Cache purge. */
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
