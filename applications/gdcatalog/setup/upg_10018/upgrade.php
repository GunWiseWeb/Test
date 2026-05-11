<?php
namespace IPS\gdcatalog\setup\upg_10018;

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
		/* gdcatalog v1.0.18 - Backfill image_url for existing 1000 Sports
		 * South products that have just the PICREF number instead of the
		 * full image URL.
		 *
		 * Bug:
		 *   v1.0.10's initial import wrote raw PICREF values (e.g. "97857")
		 *   into image_url because the Importer didn't enrich the field
		 *   yet - v1.0.11 added enrichment to transform PICREF into a full
		 *   URL.
		 *
		 *   When we re-ran the import in v1.0.14, the 12 new products got
		 *   full URLs (https://media.server.theshootingwarehouse.com/large/
		 *   1533.jpg) but the 1000 existing products kept their raw PICREF
		 *   numbers because the Importer's ConflictResolver compared the
		 *   incoming URL to the existing numeric value and either kept the
		 *   existing or treated it as a conflict.
		 *
		 * Fix:
		 *   Direct SQL UPDATE - for any image_url value that's just digits
		 *   (matches ^\d+$), wrap it in the Sports South large-image URL
		 *   template.
		 *
		 *   Pattern from SportsSouthClient::imageUrlForPicref:
		 *     https://media.server.theshootingwarehouse.com/large/{PICREF}.jpg
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10017). */

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
				'gdcatalog v1.0.18 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10018' ); } catch ( \Throwable ) {}

			if ( $longVer < 10017 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.18 WARNING: app_long_version=%d below 10017',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10018' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.18 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10018' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Backfill numeric image_url values into full URLs.
		 *
		 * MySQL REGEXP '^[0-9]+$' matches digit-only strings. We use CONCAT
		 * to wrap the digit value in the URL template. Only affects rows
		 * where primary_source='sports_south' to avoid touching products
		 * from other distributors that might use different URL schemes. */
		try
		{
			$prefix = \IPS\Db::i()->prefix;
			\IPS\Db::i()->query(
				"UPDATE {$prefix}gd_catalog
				 SET image_url = CONCAT('https://media.server.theshootingwarehouse.com/large/', image_url, '.jpg'),
				     last_updated = NOW()
				 WHERE primary_source = 'sports_south'
				   AND image_url REGEXP '^[0-9]+$'"
			);

			/* Count how many products now have full URLs */
			$fullUrlCount = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				'gd_catalog',
				[ "primary_source = 'sports_south' AND image_url LIKE 'http%'" ]
			)->first();

			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.18 image_url backfill complete: %d sports_south products now have full URLs', $fullUrlCount ), 'gdcatalog_upg_10018' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.18 image_url backfill query failed: ' . $e->getMessage(), 'gdcatalog_upg_10018' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Cache invalidation */
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
		return 'gdcatalog v1.0.18 - backfill image_url to full URLs for existing Sports South products';
	}
}

class upgrade extends _upgrade {}
