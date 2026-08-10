<?php
/**
 * @brief  GD Deals — upgrade 1.0.57 ("← Back to Deals" nav on deal detail).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.57
 *   Deal detail page (dev/html/front/deals/view.phtml) had no way
 *   back to the deals listing. A user browsing page 8 of the deals
 *   feed who clicked into a deal was stranded — the only "back"
 *   was the browser button, and even that lost their category /
 *   sort / quick-filter selection if they had opened the deal in
 *   a new tab or navigated away and back.
 *
 *   Three moving parts land the fix:
 *
 *   1. modules/front/deals/browse.php — before rendering deal
 *      cards, builds a $backState array from the current
 *      $page / $catId / $sort / $qf (only non-default values) and
 *      appends it to every card's URL via setQueryString(). Keys
 *      are prefixed with `b` (bp / bcat / bsort / bqf) because
 *      view.php's own ?page= is already consumed for comment
 *      pagination — a plain `page` would collide.
 *
 *   2. modules/front/deals/view.php — reads the b*-prefixed
 *      params, translates them back to canonical
 *      page / category / sort / qf for the browse URL, and stashes
 *      the result in $d['back_url']. Always populated — falls back
 *      to plain /deals/ (page 1, no filters) when nothing was
 *      carried in.
 *
 *   3. dev/html/front/deals/view.phtml — renders the link near the
 *      top of the deal card, immediately above `.gd-deal-layout`,
 *      inside a `.gd-deal-backnav` wrapper. Minimal `.gd-deal-back`
 *      styling in dev/css/front/deals.css (muted text color, hover
 *      to accent).
 *
 * WHAT THIS UPGRADE DOES
 *   1. Re-seeds the new gddeals_back_to_deals lang key across
 *      every lang_id (Rule #43/#44 — 6-col core_sys_lang_words,
 *      per-row try/catch).
 *   2. Cache / module / opcache purge so the updated controllers,
 *      template and CSS all load on the next request.
 *
 * NO schema change. NO template DB seed (view.phtml is a dev/html
 * file — installed via IPS's standard dev-file registration on
 * fresh install; no core_theme_templates row to reseed).
 * Rule #79: upg_10056 removed, exactly one upg dir per app.
 */

namespace IPS\gddeals\setup\upg_10057;

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
		/* 1. Re-seed new lang key across every lang_id. */
		$strings = [
			'gddeals_back_to_deals' => 'Back to Deals',
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
							'word_app'     => 'gddeals',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10057 lang ' . $key . ': ' . $e->getMessage(), 'gddeals_upg_10057' ); } catch ( \Throwable ) {} }
				}
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'upg_10057 lang loop: ' . $e->getMessage(), 'gddeals_upg_10057' ); } catch ( \Throwable ) {} }

		/* 2. Cache purge. */
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
