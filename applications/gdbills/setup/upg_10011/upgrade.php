<?php
/**
 * @brief  GD Bills — upgrade 1.0.11
 *
 * Relevance fix:
 *  - Per-hit LegiScan relevance gate (gdbills_relevance_threshold, default 50)
 *    in both fetchAllBills and detectPriorSessionLaws loops — drops low-score
 *    hits before the getBill detail fetch (saves API quota too).
 *  - isFirearmsRelated() rewritten title-weighted: pass when ANY allow term
 *    in the TITLE or a STRONG multi-word phrase anywhere; otherwise fail.
 *    Drops incidental-mention junk (KS HB2329 juvenile-justice bill that
 *    mentions "firearm" once; KS SB82 tax bill mentioning "lockable gun
 *    storage") while keeping real concealed-carry / assault-weapon /
 *    Glock-switch bills.
 *
 *  - New setting gdbills_relevance_threshold seeded with default 50 + lang.
 *
 * Existing stored junk rows are NOT auto-deleted (too risky — could remove
 * legit bills). The filter prevents NEW junk; Derrick deletes existing
 * junk manually via ACP → Tracked Bills.
 *
 * Self-contained per rule #79 (supersedes upg_10010). Re-seeds lang +
 * existing-laws + clears cache/opcache.
 */

namespace IPS\gdbills\setup\upg_10011;

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
		/* (1) Seed the new relevance-threshold setting on existing installs
		   if not yet present. saveAsSettings via core_sys_conf_settings replace. */
		try
		{
			\IPS\Db::i()->replace( 'core_sys_conf_settings', [
				'conf_key'     => 'gdbills_relevance_threshold',
				'conf_value'   => '50',
				'conf_default' => '50',
				'conf_app'     => 'gdbills',
				'conf_report'  => 'full',
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10011 seed threshold: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (2) Re-seed dev/lang.php into core_sys_lang_words for every language
		   so the new gdbills_relevance_threshold + _desc keys land. */
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

		/* (3) Defensive existing-laws re-seed. */
		try
		{
			\IPS\gdbills\LegiScan::seedExistingLaws();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10011 seedExistingLaws: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (4) Caches + opcache so the new method bodies land. settings cache
		   must clear so gdbills_relevance_threshold reads the new default. */
		try { unset( \IPS\Data\Store::i()->settings ); }     catch ( \Throwable ) {}
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
