<?php
namespace IPS\gdcatalog\setup\upg_10010;

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
		/* gdcatalog v1.0.10 - Bake disk fixes + wire SportsSouthClient
		 * into the real Importer pipeline.
		 *
		 * Disk edits being baked in:
		 *   1. SportsSouthClient.php: HTTP 200 (int) cast + <string> wrapper
		 *      parser + getElementsByTagNameNS for namespace handling
		 *   2. feeds.php: testConnection uses date 30 days ago, not 1/1/1990
		 *
		 * New code in v1.0.10:
		 *   1. Importer.php: handles auth_type='sportssouth' through
		 *      SportsSouthClient::dailyItemUpdate, returns pre-parsed records
		 *   2. Importer.php: MAX_RECORDS_PER_RUN=1000 safety cap
		 *   3. parseFeed: short-circuits the XML/JSON/CSV dispatch for
		 *      sportssouth (records already parsed by client)
		 *
		 * Database changes in this upgrade:
		 *   1. Seed default field_mapping JSON for existing sportssouth feeds
		 *      so the Importer's FieldMapper has something to work with on
		 *      Run Import. Maps Sports South's field names (ITUPC, IDESC etc)
		 *      to gd_catalog canonical column names.
		 *
		 * Per CLAUDE.md rule #51: sanity check compares against PREVIOUS
		 * version (10009). */

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
				'gdcatalog v1.0.10 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10010' ); } catch ( \Throwable ) {}

			if ( $longVer < 10009 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.10 WARNING: app_long_version=%d below 10009',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10010' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.10 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10010' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Seed default field_mapping for sportssouth feeds.
		 * Maps Sports South response field names to gd_catalog canonical
		 * column names per FieldMapper::VALID_FIELDS. Only sets the mapping
		 * if it's currently empty - won't override admin customizations. */
		$sportssouthFieldMapping = [
			'ITUPC'   => 'upc',
			'IDESC'   => 'title',
			'SHDESC'  => 'description',
			'IMODEL'  => 'model',
			'PRC1'    => 'msrp',
			'WTPBX'   => 'weight_oz',
			'PICREF'  => 'image_url',
		];

		try
		{
			$emptyMappings = \IPS\Db::i()->select(
				'id, feed_name',
				'gd_distributor_feeds',
				[ "auth_type=? AND ( field_mapping IS NULL OR field_mapping='' OR field_mapping='{}' OR field_mapping='[]' )", 'sportssouth' ]
			);

			foreach ( $emptyMappings as $feedRow )
			{
				try
				{
					\IPS\Db::i()->update(
						'gd_distributor_feeds',
						[ 'field_mapping' => json_encode( $sportssouthFieldMapping ) ],
						[ 'id=?', (int) $feedRow['id'] ]
					);
					try { \IPS\Log::log( 'Seeded sportssouth field_mapping for feed_id=' . $feedRow['id'] . ' (' . $feedRow['feed_name'] . ')', 'gdcatalog_upg_10010' ); } catch ( \Throwable ) {}
				}
				catch ( \Throwable $rowException )
				{
					try { \IPS\Log::log( 'Failed seeding field_mapping for feed_id=' . $feedRow['id'] . ': ' . $rowException->getMessage(), 'gdcatalog_upg_10010' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.10 field_mapping seed outer failed: ' . $e->getMessage(), 'gdcatalog_upg_10010' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Cache invalidation */
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
		return 'gdcatalog v1.0.10 - Sports South real Importer wiring + bake disk fixes';
	}
}

class upgrade extends _upgrade {}
