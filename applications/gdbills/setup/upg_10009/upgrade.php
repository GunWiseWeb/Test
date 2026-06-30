<?php
/**
 * @brief  GD Bills — upgrade 1.0.9
 *
 * ACP "Tracked Bills" rebuilt as a native \IPS\Helpers\Table\Db:
 *  - Sortable column headers, native pagination, row-action menu
 *    (Edit / Delete) — matches the look of other IPS ACP pages.
 *  - Type tabs (All / Existing Laws / Recently Enacted / Pending) +
 *    State select + title-or-bill# search + Add bill button stay above
 *    the table; they drive Table\Db's WHERE clauses + baseUrl so
 *    pagination and sort preserve the active filters.
 *
 * Re-seeds dev/lang.php so the new column header keys (gdbills_acp_col_*)
 * land on existing installs. Defensive re-run of seedExistingLaws.
 * Cache + opcache clear.
 *
 * Self-contained per rule #79 (supersedes upg_10008).
 */

namespace IPS\gdbills\setup\upg_10009;

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
		/* (1) Re-seed dev/lang.php into core_sys_lang_words for every language
		   so the new gdbills_acp_col_* column headers land on existing installs.
		   6-col format + per-row try/catch per rule #43/#44. */
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

		/* (2) Re-seed existing laws (idempotent — guards a skipped-release upgrader). */
		try
		{
			$res = \IPS\gdbills\LegiScan::seedExistingLaws();
			try { \IPS\Log::log( 'upg_10009 seedExistingLaws: ' . json_encode( $res ), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10009 seedExistingLaws: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (3) Caches + opcache so the new controller body lands. */
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
