<?php
namespace IPS\gdcatalog\setup\upg_10019;

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
		/* gdcatalog v1.0.19 - Add image preview to product edit page.
		 *
		 * No schema changes. No template changes. Just adds inline HTML
		 * for an image preview block before the IPS form output, in
		 * products.php::edit().
		 *
		 * This upgrade.php only handles sanity check + cache invalidation
		 * to ensure the modified products.php is picked up immediately
		 * (without waiting for opcache to expire).
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10018). */

		/* Step 1: Sanity check */
		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gdcatalog' ]
			)->first();

			$longVer = (int) ( $row['app_long_version'] ?? 0 );
			$msg = sprintf(
				'gdcatalog v1.0.19 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10019' ); } catch ( \Throwable ) {}

			if ( $longVer < 10018 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.19 WARNING: app_long_version=%d below 10018',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10019' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.19 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10019' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Cache invalidation. Forces IPS to pick up the modified
		 * products.php controller. */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'extensions%' OR store_key LIKE 'applications%'" ] ); } catch ( \Throwable ) {}

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

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'gdcatalog v1.0.19 - image preview on product edit page';
	}
}

class upgrade extends _upgrade {}
