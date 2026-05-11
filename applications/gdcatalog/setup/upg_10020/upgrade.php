<?php
namespace IPS\gdcatalog\setup\upg_10020;

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
		/* gdcatalog v1.0.20 - Category-aware ITATR extraction for product
		 * attributes (caliber, action_type, barrel_length, capacity, finish).
		 *
		 * Background:
		 *   Sports South stores per-product attribute VALUES in ITATR1..N
		 *   slots on each product record. The LABEL for each slot is defined
		 *   per-category in gd_sportssouth_categories.raw_data (ATTR1..N).
		 *
		 *   Example for category "AIR GUNS":
		 *     ATTR1 = 'Type'
		 *     ATTR2 = 'Action'
		 *     ATTR3 = 'Caliber'
		 *     ATTR4 = 'Capacity'
		 *     ATTR5 = 'Finish'
		 *     ...
		 *
		 *   A product in AIR GUNS might have:
		 *     ITATR3 = '.177'      <- caliber value
		 *     ITATR4 = '10'        <- capacity value
		 *     ITATR5 = 'Black'     <- finish value
		 *
		 *   Each Sports South category defines its OWN attribute label set,
		 *   so the slot-to-meaning mapping varies. The Importer (patched in
		 *   v1.0.20) walks the category's label map and matches labels
		 *   case-insensitively to canonical column names.
		 *
		 * This upgrade.php updates the sportssouth feed_mapping JSON to
		 * include 5 new synthetic field mappings:
		 *   _CALIBER       -> caliber
		 *   _ACTION_TYPE   -> action_type
		 *   _BARREL_LENGTH -> barrel_length
		 *   _CAPACITY      -> capacity
		 *   _FINISH        -> finish
		 *
		 * The Importer's enrichSportsSouthRecord (v1.0.20 patch) injects
		 * these synthetic keys onto each record before FieldMapper runs.
		 *
		 * After install, admin must Run Import to populate these columns
		 * on existing 1012 products. There's no SQL backfill option - the
		 * ITATRn data is NOT in gd_catalog; it comes fresh from the API.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10019). */

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
				'gdcatalog v1.0.20 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10020' ); } catch ( \Throwable ) {}

			if ( $longVer < 10019 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.20 WARNING: app_long_version=%d below 10019',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10020' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.20 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10020' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Update field_mapping for sportssouth feeds.
		 *
		 * Builds on v1.0.17's mapping. Adds 5 new synthetic fields for
		 * the v1.0.20 attribute enrichment. Final mapping after update:
		 *
		 *   ITUPC          -> upc
		 *   SHDESC         -> title
		 *   _BRAND_NAME    -> brand
		 *   _CATEGORY_ID   -> category_id
		 *   _CALIBER       -> caliber          (NEW v1.0.20)
		 *   _ACTION_TYPE   -> action_type      (NEW v1.0.20)
		 *   _BARREL_LENGTH -> barrel_length    (NEW v1.0.20)
		 *   _CAPACITY      -> capacity         (NEW v1.0.20)
		 *   _FINISH        -> finish           (NEW v1.0.20)
		 *   IMODEL         -> model
		 *   PRC1           -> msrp
		 *   WTPBX          -> weight_oz
		 *   PICREF         -> image_url */
		$updatedMapping = [
			'ITUPC'          => 'upc',
			'SHDESC'         => 'title',
			'_BRAND_NAME'    => 'brand',
			'_CATEGORY_ID'   => 'category_id',
			'_CALIBER'       => 'caliber',
			'_ACTION_TYPE'   => 'action_type',
			'_BARREL_LENGTH' => 'barrel_length',
			'_CAPACITY'      => 'capacity',
			'_FINISH'        => 'finish',
			'IMODEL'         => 'model',
			'PRC1'           => 'msrp',
			'WTPBX'          => 'weight_oz',
			'PICREF'         => 'image_url',
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
					try { \IPS\Log::log( 'Updated sportssouth field_mapping for feed_id=' . $feedRow['id'] . ' with v1.0.20 ITATR synthetic fields', 'gdcatalog_upg_10020' ); } catch ( \Throwable ) {}
				}
				catch ( \Throwable $rowException )
				{
					try { \IPS\Log::log( 'Failed updating field_mapping for feed_id=' . $feedRow['id'] . ': ' . $rowException->getMessage(), 'gdcatalog_upg_10020' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.20 field_mapping update outer failed: ' . $e->getMessage(), 'gdcatalog_upg_10020' ); } catch ( \Throwable ) {}
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
		return 'gdcatalog v1.0.20 - category-aware ITATR extraction (caliber/action/barrel/capacity/finish)';
	}
}

class upgrade extends _upgrade {}
