<?php
namespace IPS\gdcatalog\setup\upg_10009;

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
		/* gdcatalog v1.0.9 - small patch to v1.0.8.
		 *
		 * v1.0.8 extended the auth_type ENUM in the database schema but
		 * the form dropdown in feeds.php::edit() was hardcoded with only
		 * 4 options ['none','basic','apikey','ftp']. Result: admins
		 * couldn't actually SELECT 'sportssouth' even though the DB
		 * accepted it, so Sports South feeds stayed at auth_type='apikey'
		 * and the Test Connection button (rendered conditionally on
		 * auth_type='sportssouth') never appeared.
		 *
		 * Fix: add 'sportssouth' to the form select options array. That's
		 * the entire change. No schema work, no template reseed.
		 *
		 * Per CLAUDE.md rule #51: sanity check compares against PREVIOUS
		 * version (10008). */

		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gdcatalog' ]
			)->first();

			$longVer = (int) ( $row['app_long_version'] ?? 0 );

			$msg = sprintf(
				'gdcatalog v1.0.9 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10009' ); } catch ( \Throwable ) {}

			if ( $longVer < 10008 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.9 WARNING: app_long_version=%d below 10008',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10009' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.9 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10009' ); } catch ( \Throwable ) {}
		}

		/* Cache invalidation. The form dropdown change is a code edit
		 * to feeds.php so opcache reset is what matters most. */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'extensions%' OR store_key LIKE 'applications%' OR store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'settings%'" ] ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/extensions*' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/applications*' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'gdcatalog v1.0.9 - add sportssouth to Auth Type form dropdown';
	}
}

class upgrade extends _upgrade {}
