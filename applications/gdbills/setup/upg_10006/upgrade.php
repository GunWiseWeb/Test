<?php
/**
 * @brief  GD Bills — upgrade 1.0.6
 *
 * Phase 2 UX matches the WordPress plugin:
 *  - Type filter (All / Existing Laws / Recently Enacted / Pending)
 *  - Date range filter (date_from / date_to)
 *  - "Last Updated" display + "Showing N items" count bar
 *  - Existing Laws section surfaced on the state view + modal
 *
 * Self-contained per rule #79 (supersedes upg_10005). Re-seeds the
 * marquee existing laws so an upgrade from a fresh-1.0.0 state still
 * lands the bundled rows, alongside the new lang keys for filters /
 * last-updated / showing-count.
 */

namespace IPS\gdbills\setup\upg_10006;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* (1) Re-seed lang for new keys (gdbills_filter_all/law/enacted/pending,
		   gdbills_filter_date/from/to, gdbills_last_updated, gdbills_showing_count). */
		$langFile = \IPS\ROOT_PATH . '/applications/gdbills/dev/lang.php';
		if ( is_readable( $langFile ) )
		{
			$lang = [];
			include $langFile;
			if ( is_array( $lang ) && !empty( $lang ) )
			{
				try
				{
					foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
					{
						foreach ( $lang as $key => $val )
						{
							try
							{
								\IPS\Db::i()->replace( 'core_sys_lang_words', [
									'lang_id'      => (int) $langId,
									'word_app'     => 'gdbills',
									'word_key'     => (string) $key,
									'word_default' => (string) $val,
									'word_js'      => 0,
									'word_export'  => 1,
								] );
							}
							catch ( \Throwable ) {}
						}
					}
				}
				catch ( \Throwable ) {}
			}
		}

		/* (2) Re-seed existing laws (idempotent — Bill::upsert matches on
		   (bill_number, state_code) so re-runs just update in place). Same
		   call as upg_10005 — guards against an upgrader who skipped 10005. */
		try
		{
			$res = \IPS\gdbills\LegiScan::seedExistingLaws();
			try { \IPS\Log::log( 'upg_10006 seedExistingLaws: ' . json_encode( $res ), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10006 seedExistingLaws: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (3) Caches + opcache so new template/controller bodies land. */
		try { unset( \IPS\Data\Store::i()->acpmenu ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }            catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
