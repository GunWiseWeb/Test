<?php
namespace IPS\gdcatalog\setup\upg_10030;

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
		/* gdcatalog v1.0.30 - OpenSearch HEAD request fix + real index probe.
		 *
		 * Two bug fixes:
		 *
		 *   1. OpenSearchIndexer::request() with HEAD method hung for 30s
		 *      because CURLOPT_NOBODY was not set. PHP curl with
		 *      CURLOPT_CUSTOMREQUEST='HEAD' but without CURLOPT_NOBODY=true
		 *      will wait for a response body that never arrives, then time
		 *      out. Fix: set CURLOPT_NOBODY=true when $method === 'HEAD'.
		 *
		 *   2. dashboard.php hardcoded $osExists = FALSE and $osStats = []
		 *      to avoid that exact timeout. With the HEAD bug fixed, we
		 *      can now do a real bounded probe via $indexer->indexExists()
		 *      and $indexer->getStats() to populate the OpenSearch panel
		 *      properly. URLs for Build/Rebuild Index Now and Process
		 *      Queue Now are now built in the controller.
		 *
		 * No schema changes. No template changes. Both fixes are PHP code.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10029). */

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
				'gdcatalog v1.0.30 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10030' ); } catch ( \Throwable ) {}

			if ( $longVer < 10029 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.30 WARNING: app_long_version=%d below 10029',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10030' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.30 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10030' ); } catch ( \Throwable ) {}
		}

		try { \IPS\Log::log( 'gdcatalog v1.0.30 installed: OpenSearch HEAD fix + real dashboard probe', 'gdcatalog_upg_10030' ); } catch ( \Throwable ) {}

		/* Step 2: Cache invalidation */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/*.php' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/static/templates/*.php' ) ?: [] as $f )
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
		return 'gdcatalog v1.0.30 - OpenSearch HEAD fix + dashboard probe';
	}
}

class upgrade extends _upgrade {}
