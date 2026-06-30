<?php
/**
 * @brief  GD Bills — upgrade 1.0.7
 *
 * Two UX fixes:
 *  - Front: state-tile clicks now navigate inline (?state=XX) instead of
 *    opening a modal popup. Modal markup + JS removed.
 *  - ACP "Tracked Bills": rebuilt with filter form (State/Type/q),
 *    IPS core pagination, type pills, responsive table.
 *
 * Self-contained per rule #79 (supersedes upg_10006). Re-seeds lang +
 * existing-laws on every upgrade so an upgrader who skipped a release
 * still lands the bundled data + new keys.
 */

namespace IPS\gdbills\setup\upg_10007;

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
		/* (1) Re-seed lang for new keys (gdbills_back_all_states,
		   gdbills_acp_search_*, gdbills_acp_bills_none). */
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

		/* (2) Re-seed existing laws (idempotent, guards skipped-release upgrader). */
		try
		{
			$res = \IPS\gdbills\LegiScan::seedExistingLaws();
			try { \IPS\Log::log( 'upg_10007 seedExistingLaws: ' . json_encode( $res ), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10007 seedExistingLaws: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
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
