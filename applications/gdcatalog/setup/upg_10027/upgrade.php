<?php
namespace IPS\gdcatalog\setup\upg_10027;

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
		/* gdcatalog v1.0.27 - Master record fields for product detail display.
		 *
		 * Schema additions to support the firearm product detail layout:
		 *
		 *   mpn                  VARCHAR(50)   - manufacturer part number (Sports South MFGINO)
		 *   manufacturer         VARCHAR(100)  - actual maker (may differ from brand for imports)
		 *   importer             VARCHAR(100)  - reserved for future use (FFL Type 08 importers)
		 *   gun_type             VARCHAR(50)   - "Pistol", "Revolver", etc (ATTR1=Type slot)
		 *   safety_type          VARCHAR(100)  - "Ambidextrous Thumb" (ATTR6=Safety slot)
		 *   stock_type           VARCHAR(100)  - "Black Grips" (ATTR7=Grips slot)
		 *   sight_type           VARCHAR(100)  - "Adjustable Sights" (ATTR8=Sight Configuration)
		 *   receiver_type        VARCHAR(255)  - "CNC Machined Aluminum Slide" (ATTR13=Slide Description)
		 *   frame_material       VARCHAR(100)  - (ATTR11=Frame Material)
		 *   raw_distributor_data LONGTEXT      - JSON of raw distributor record for re-extraction
		 *
		 * Also fixes the WEIGHT bug:
		 *   Before: WTPBX (Sports South shipping-weight-per-box in POUNDS) was mapped
		 *           directly to weight_oz, giving wildly wrong values
		 *           (e.g. Desert Eagle showing 0.00 instead of ~70 oz, Glock-style
		 *           pistols showing 2-3 instead of 21-30 oz).
		 *   After:  WTPBX is removed from field_mapping; weight comes from ATTR9
		 *           "Weight" slot (in oz) via the new _WEIGHT_OZ synthetic key.
		 *   Backfill: NULL all weight_oz values for sports_south products so the
		 *             new import overwrites with correct values. The bad data is
		 *             worse than NULL.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10026). */

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
				'gdcatalog v1.0.27 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10027' ); } catch ( \Throwable ) {}

			if ( $longVer < 10026 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.27 WARNING: app_long_version=%d below 10026',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10027' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.27 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10027' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Add new columns to gd_catalog. Introspect first to avoid
		 * "Duplicate column" errors on re-runs. */
		try
		{
			$prefix = \IPS\Db::i()->prefix;
			$describe = \IPS\Db::i()->query( "DESCRIBE {$prefix}gd_catalog" );
			$existingCols = [];
			foreach ( $describe as $c )
			{
				$existingCols[] = (string) ( $c['Field'] ?? '' );
			}

			$toAdd = [
				'mpn'                  => "ADD COLUMN mpn VARCHAR(50) NULL AFTER model",
				'manufacturer'         => "ADD COLUMN manufacturer VARCHAR(100) NULL AFTER brand",
				'importer'             => "ADD COLUMN importer VARCHAR(100) NULL AFTER manufacturer",
				'gun_type'             => "ADD COLUMN gun_type VARCHAR(50) NULL AFTER subcategory",
				'safety_type'          => "ADD COLUMN safety_type VARCHAR(100) NULL AFTER finish",
				'stock_type'           => "ADD COLUMN stock_type VARCHAR(100) NULL AFTER safety_type",
				'sight_type'           => "ADD COLUMN sight_type VARCHAR(100) NULL AFTER stock_type",
				'receiver_type'        => "ADD COLUMN receiver_type VARCHAR(255) NULL AFTER sight_type",
				'frame_material'       => "ADD COLUMN frame_material VARCHAR(100) NULL AFTER receiver_type",
				'raw_distributor_data' => "ADD COLUMN raw_distributor_data LONGTEXT NULL AFTER distributor_last_seen",
			];

			foreach ( $toAdd as $col => $ddl )
			{
				if ( !in_array( $col, $existingCols, true ) )
				{
					try
					{
						\IPS\Db::i()->query( "ALTER TABLE {$prefix}gd_catalog {$ddl}" );
						try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.27 added column gd_catalog.%s', $col ), 'gdcatalog_upg_10027' ); } catch ( \Throwable ) {}
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.27 column add FAILED for %s: %s', $col, $e->getMessage() ), 'gdcatalog_upg_10027' ); } catch ( \Throwable ) {}
					}
				}
			}

			/* Add indexes - check for existence first */
			$indexCheck = \IPS\Db::i()->query( "SHOW INDEX FROM {$prefix}gd_catalog WHERE Key_name IN ('idx_mpn', 'idx_manufacturer')" );
			$existingIndexes = [];
			foreach ( $indexCheck as $idx )
			{
				$existingIndexes[] = (string) ( $idx['Key_name'] ?? '' );
			}

			if ( !in_array( 'idx_mpn', $existingIndexes, true ) )
			{
				try
				{
					\IPS\Db::i()->query( "ALTER TABLE {$prefix}gd_catalog ADD INDEX idx_mpn (mpn)" );
				}
				catch ( \Throwable ) {}
			}
			if ( !in_array( 'idx_manufacturer', $existingIndexes, true ) )
			{
				try
				{
					\IPS\Db::i()->query( "ALTER TABLE {$prefix}gd_catalog ADD INDEX idx_manufacturer (manufacturer)" );
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.27 schema add failed: ' . $e->getMessage(), 'gdcatalog_upg_10027' ); } catch ( \Throwable ) {}
		}

		/* Step 3: NULL out weight_oz for sports_south products. The current values
		 * are WTPBX (shipping weight in lbs) which is wrong. New imports will
		 * populate via ATTR9 with correct values. */
		try
		{
			$affected = \IPS\Db::i()->update( 'gd_catalog',
				[ 'weight_oz' => null ],
				[ 'primary_source=?', 'sports_south' ]
			);
			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.27 nulled %d weight_oz values (was WTPBX in lbs, now awaiting ATTR9 reimport)', (int) $affected ), 'gdcatalog_upg_10027' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.27 weight nulling failed: ' . $e->getMessage(), 'gdcatalog_upg_10027' ); } catch ( \Throwable ) {}
		}

		/* Step 4: Update Sports South feed field_mapping JSON.
		 * - Remove WTPBX:weight_oz (the bug)
		 * - Add MFGINO:mpn
		 * - Add _WEIGHT_OZ:weight_oz (synthetic from ATTR9 extraction)
		 * - Add _MPN:mpn (alternate path)
		 * - Add _MANUFACTURER:manufacturer
		 * - Add _SAFETY_TYPE:safety_type
		 * - Add _STOCK_TYPE:stock_type
		 * - Add _SIGHT_TYPE:sight_type
		 * - Add _RECEIVER_TYPE:receiver_type
		 * - Add _FRAME_MATERIAL:frame_material
		 * - Add _GUN_TYPE:gun_type
		 *
		 * Only applies to the Sports South feed (distributor='sports_south'). */
		try
		{
			foreach ( \IPS\Db::i()->select( 'id, field_mapping', 'gd_distributor_feeds', [ 'distributor=?', 'sports_south' ] ) as $feed )
			{
				$mapping = json_decode( (string) ( $feed['field_mapping'] ?? '{}' ), true );
				if ( !is_array( $mapping ) )
				{
					$mapping = [];
				}

				/* Remove WTPBX bug */
				if ( isset( $mapping['WTPBX'] ) )
				{
					unset( $mapping['WTPBX'] );
				}

				/* Add the new mappings (idempotent via array merge) */
				$newMappings = [
					'MFGINO'           => 'mpn',
					'_MPN'             => 'mpn',
					'_MANUFACTURER'    => 'manufacturer',
					'_WEIGHT_OZ'       => 'weight_oz',
					'_SAFETY_TYPE'     => 'safety_type',
					'_STOCK_TYPE'      => 'stock_type',
					'_SIGHT_TYPE'      => 'sight_type',
					'_RECEIVER_TYPE'   => 'receiver_type',
					'_FRAME_MATERIAL'  => 'frame_material',
					'_GUN_TYPE'        => 'gun_type',
				];

				foreach ( $newMappings as $src => $dst )
				{
					$mapping[ $src ] = $dst;
				}

				\IPS\Db::i()->update( 'gd_distributor_feeds',
					[ 'field_mapping' => json_encode( $mapping ) ],
					[ 'id=?', (int) $feed['id'] ]
				);

				try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.27 updated field_mapping for feed_id=%d', (int) $feed['id'] ), 'gdcatalog_upg_10027' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.27 field_mapping update failed: ' . $e->getMessage(), 'gdcatalog_upg_10027' ); } catch ( \Throwable ) {}
		}

		/* Step 5: Cache invalidation */
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
		return 'gdcatalog v1.0.27 - master record fields (mpn/manufacturer/safety/stock/sight/receiver/etc) + weight bug fix';
	}
}

class upgrade extends _upgrade {}
