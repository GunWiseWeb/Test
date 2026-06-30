<?php
/**
 * @brief  GD Bills — upgrade 1.0.15
 *
 * FURL collision fix:
 *  - Removed the gdbills_action page from data/furl.json. Its
 *    "friendly":"{@do}" was a BARE single-segment wildcard that
 *    shadowed core URL resolution — broke forum profile + edit-profile
 *    links (/profile/{#id}-{?}, /profile/{#id}-{?}/edit). The two AJAX
 *    endpoints that used it (stateBills, mapData) now ship as plain
 *    query-string URLs from widgets/billMap.php — pretty URLs for
 *    internal AJAX have no value.
 *  - The compiled FURL map is cached. This upgrade clears the
 *    furl_configuration / urls / settings datastore keys + flushes
 *    Store/Cache so the new pattern set takes effect immediately on
 *    next request. Without the bust, links stay broken until the
 *    cache rebuilds organically.
 *
 *  - Defensive idempotent ALTERs from v1.0.13/v1.0.14 still re-applied
 *    here so a deployer going straight v1.0.12 -> v1.0.15 still lands
 *    the history + state_link columns.
 *
 *  Self-contained per rule #79 (supersedes upg_10014).
 */

namespace IPS\gdbills\setup\upg_10015;

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

		/* (A) Defensive ALTERs from prior versions — guarded so re-runs
		   are no-ops. Keep them here so a 1.0.12 -> 1.0.15 hop still lands
		   the history + state_link columns. */
		foreach ( [
			'history'    => 'MEDIUMTEXT NULL DEFAULT NULL',
			'state_link' => 'VARCHAR(255) NULL DEFAULT NULL',
		] as $col => $type )
		{
			try
			{
				$has = false;
				try
				{
					$has = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.COLUMNS', [
						'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
						$prefix . 'gd_bills',
						$col,
					] )->first();
				}
				catch ( \Throwable ) {}

				if ( !$has )
				{
					\IPS\Db::i()->query( 'ALTER TABLE ' . $prefix . 'gd_bills ADD COLUMN ' . $col . ' ' . $type );
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10015 ALTER ' . $col . ': ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
			}
		}

		/* (B) The actual 1.0.15 fix — bust the FURL cache so the trimmed
		   data/furl.json (no more "{@do}" pattern) takes effect. The
		   compiled URL map sits in Data\Store under several keys
		   depending on IPS version; unset each candidate plus a full
		   clearAll so we cover the install. */
		foreach ( [
			'urls',
			'urlIndex',
			'furl_configuration',
			'urlFriendly',
			'modules',
			'applications',
			'settings',
			'acpmenu',
			'extensions',
		] as $k )
		{
			try { unset( \IPS\Data\Store::i()->$k ); } catch ( \Throwable ) {}
		}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}

		/* Datastore on-disk cache files — best effort delete. */
		try
		{
			$dsDir = \IPS\ROOT_PATH . '/datastore';
			if ( is_dir( $dsDir ) )
			{
				foreach ( glob( $dsDir . '/*' ) as $f )
				{
					if ( is_file( $f ) ) { @unlink( $f ); }
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10015 datastore prune: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (C) Re-seed dev/lang.php (no new keys this release, but keeps
		   existing keys consistent for an upgrader who skipped releases). */
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

		/* (D) Defensive existing-laws re-seed. */
		try
		{
			\IPS\gdbills\LegiScan::seedExistingLaws();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10015 seedExistingLaws: ' . $e->getMessage(), 'gdbills_upgrade' ); } catch ( \Throwable ) {}
		}

		/* (E) opcache so the new widget body lands. */
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
