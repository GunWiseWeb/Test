<?php
/**
 * @brief  GD Bills — upgrade 1.0.13
 *
 *  - Adds gd_bills.history column (MEDIUMTEXT NULL) so future syncs can
 *    persist the raw LegiScan history JSON. Once stored, the
 *    "Re-parse stored bills" ACP button can re-derive progress/status
 *    OFFLINE — no LegiScan API quota — every time the parser improves.
 *  - Extracts the parser into a shared LegiScan::deriveProgress() so the
 *    live sync and the re-parse button are literally the same code path.
 *  - Adds LegiScan::reparseStored() + an ACP button. DB-only, advance-only
 *    (terminal became_law/vetoed/failed are never downgraded).
 *
 * The upgrade itself does NOT run reparseStored — Derrick triggers it
 * from the button. The ALTER is guarded so re-running the upgrade is safe.
 *
 * Self-contained per rule #79 (supersedes upg_10012). Re-seeds lang +
 * existing-laws.
 */

namespace IPS\gdbills\setup\upg_10013;

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
		/* (1) Add gd_bills.history column (guarded). Catch any "duplicate
		   column" error so re-runs are no-ops. Use \Throwable so undefined-
		   method / driver-specific errors don't escape (rule #35). */
		try
		{
			$has = false;
			try
			{
				$has = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.COLUMNS', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
					\IPS\Db::i()->prefix . 'gd_bills',
					'history',
				] )->first();
			}
			catch ( \Throwable ) { /* If information_schema isn't readable, the ALTER below will tell us via duplicate-column. */ }

			if ( !$has )
			{
				\IPS\Db::i()->query( 'ALTER TABLE ' . \IPS\Db::i()->prefix . 'gd_bills ADD COLUMN history MEDIUMTEXT NULL DEFAULT NULL' );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10013 ALTER history: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (2) Re-seed dev/lang.php into core_sys_lang_words for every language
		   so the new gdbills_acp_reparse_* keys land on existing installs. */
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
			try { \IPS\Log::log( 'upg_10013 seedExistingLaws: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (4) Caches + opcache so the new method bodies + ACP panel land. */
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
