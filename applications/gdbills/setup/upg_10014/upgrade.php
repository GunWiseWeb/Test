<?php
/**
 * @brief  GD Bills — upgrade 1.0.14
 *
 *  - Adds gd_bills.state_link VARCHAR(255) NULL — official state-legislature
 *    page stored separately from LegiScan's url so we always have both.
 *  - (Defensive, idempotent) Also re-applies the gd_bills.history MEDIUMTEXT
 *    NULL column from v1.0.13, so a Derrick going straight 1.0.12 -> 1.0.14
 *    still lands the history column. Both ALTERs are guarded with an
 *    information_schema check and a \Throwable catch — re-runs no-op.
 *  - Adds LegiScan::refetchLinks() + ACP button "Re-fetch official links":
 *    one getBill per existing bill missing state_link/history, throttled,
 *    state + batch limited, resumable.
 *  - parseBill now stores url and state_link in their own columns. The
 *    display layer (Bill::applyDisplayUrl) overrides $row['url'] to the
 *    state_link when present so the template still reads $b['url'] and
 *    always shows the best link.
 *
 *  Self-contained per rule #79 (supersedes upg_10013).
 */

namespace IPS\gdbills\setup\upg_10014;

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
		$prefix = (string) \IPS\Db::i()->prefix;

		/* (1) gd_bills.history MEDIUMTEXT NULL — guarded ALTER. */
		try
		{
			$has = false;
			try
			{
				$has = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.COLUMNS', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
					$prefix . 'gd_bills',
					'history',
				] )->first();
			}
			catch ( \Throwable ) {}

			if ( !$has )
			{
				\IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_bills ADD COLUMN history MEDIUMTEXT NULL DEFAULT NULL' );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10014 ALTER history: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (2) gd_bills.state_link VARCHAR(255) NULL — same guarded pattern. */
		try
		{
			$has = false;
			try
			{
				$has = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.COLUMNS', [
					'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
					$prefix . 'gd_bills',
					'state_link',
				] )->first();
			}
			catch ( \Throwable ) {}

			if ( !$has )
			{
				\IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_bills ADD COLUMN state_link VARCHAR(255) NULL DEFAULT NULL' );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10014 ALTER state_link: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (3) Re-seed dev/lang.php into core_sys_lang_words so the new
		   gdbills_acp_refetch_* + gdbills_acp_reparse_* keys land. */
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

		/* (4) Defensive existing-laws re-seed. */
		try
		{
			\IPS\gdbills\LegiScan::seedExistingLaws();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10014 seedExistingLaws: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (5) Caches + opcache so the new method bodies + ACP panel land. */
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
