<?php
namespace IPS\gdcatalog\setup\upg_10017;

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
		/* gdcatalog v1.0.17 - Swap title source from Sports South IDESC
		 * to SHDESC + backfill existing 1012 product titles.
		 *
		 * Bug:
		 *   gd_catalog.title currently holds the truncated SKU-flavored
		 *   string from Sports South's IDESC field (e.g.
		 *     "DDF 0219103270047 DD4 RIII 5.56 16 30R BLK")
		 *   For frontend UPC search and customer-facing listings this is
		 *   garbage.
		 *
		 *   Sports South's SHDESC field has the readable product name
		 *   (e.g. "Daniel Defense 0219103270047 DD4 RIII 5.56x45mm NATO
		 *   16" 30+1, Black Rec/Furniture, OEM Stock & Grip..." - 143
		 *   chars for the same product).
		 *
		 *   Our v1.0.10 field_mapping mapped IDESC->title and SHDESC->
		 *   description. Wrong way around for our needs.
		 *
		 * Fix:
		 *   1. Update sportssouth feed's field_mapping JSON so SHDESC
		 *      maps to title. IDESC dropped from mapping (SKU noise).
		 *   2. Backfill existing gd_catalog rows: copy description into
		 *      title via raw SQL.
		 *   3. Description column stays empty until v1.0.18+ GetText work.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10016). */

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
				'gdcatalog v1.0.17 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10017' ); } catch ( \Throwable ) {}

			if ( $longVer < 10016 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.17 WARNING: app_long_version=%d below 10016',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10017' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.17 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10017' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Update field_mapping for sportssouth feeds.
		 *
		 * Final mapping after this update:
		 *   ITUPC        -> upc
		 *   SHDESC       -> title         (readable product name)
		 *   _BRAND_NAME  -> brand         (from v1.0.11 enrichment)
		 *   _CATEGORY_ID -> category_id   (from v1.0.15 enrichment)
		 *   IMODEL       -> model
		 *   PRC1         -> msrp
		 *   WTPBX        -> weight_oz
		 *   PICREF       -> image_url */
		$updatedMapping = [
			'ITUPC'        => 'upc',
			'SHDESC'       => 'title',
			'_BRAND_NAME'  => 'brand',
			'_CATEGORY_ID' => 'category_id',
			'IMODEL'       => 'model',
			'PRC1'         => 'msrp',
			'WTPBX'        => 'weight_oz',
			'PICREF'       => 'image_url',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'id, feed_name, field_mapping', 'gd_distributor_feeds', [ 'auth_type=?', 'sportssouth' ] ) as $feedRow )
			{
				try
				{
					\IPS\Db::i()->update(
						'gd_distributor_feeds',
						[ 'field_mapping' => json_encode( $updatedMapping ) ],
						[ 'id=?', (int) $feedRow['id'] ]
					);
					try { \IPS\Log::log( 'Updated sportssouth field_mapping for feed_id=' . $feedRow['id'] . ' (SHDESC now maps to title)', 'gdcatalog_upg_10017' ); } catch ( \Throwable ) {}
				}
				catch ( \Throwable $rowException )
				{
					try { \IPS\Log::log( 'Failed updating field_mapping for feed_id=' . $feedRow['id'] . ': ' . $rowException->getMessage(), 'gdcatalog_upg_10017' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.17 field_mapping update outer failed: ' . $e->getMessage(), 'gdcatalog_upg_10017' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Backfill existing gd_catalog rows.
		 *
		 * Copy description -> title, then clear description. Only affects
		 * sports_south products with substantive descriptions (>= 30 chars)
		 * to avoid edge cases where description happens to be shorter than
		 * the existing title.
		 *
		 * Raw SQL because IPS\Db::update doesn't support column-to-column
		 * assignment in its array API. */
		try
		{
			$prefix = \IPS\Db::i()->prefix;
			\IPS\Db::i()->query(
				"UPDATE {$prefix}gd_catalog
				 SET title = description,
				     description = '',
				     last_updated = NOW()
				 WHERE primary_source = 'sports_south'
				   AND CHAR_LENGTH(description) >= 30"
			);

			$readableCount = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				'gd_catalog',
				[ "primary_source = 'sports_south' AND CHAR_LENGTH(title) >= 30" ]
			)->first();

			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.17 title backfill complete: %d sports_south products now have readable titles', $readableCount ), 'gdcatalog_upg_10017' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.17 title backfill query failed: ' . $e->getMessage(), 'gdcatalog_upg_10017' ); } catch ( \Throwable ) {}
		}

		/* Step 4: Cache invalidation */
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
		return 'gdcatalog v1.0.17 - swap title source from IDESC to SHDESC + backfill existing rows';
	}
}

class upgrade extends _upgrade {}
