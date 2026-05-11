<?php
namespace IPS\gdcatalog\setup\upg_10014;

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
		/* gdcatalog v1.0.14 - Fix Sports South brand name population.
		 *
		 * Bug: gd_sportssouth_brands.brdnam is empty for all 729 rows
		 * because v1.0.11's processBrandLookup helper tried BRDNAM,
		 * BRDNAME, BRDDESC - but Sports South's actual field name is
		 * BRDNM (no A). Easy off-by-one in field naming.
		 *
		 * Knock-on effect: gd_catalog.brand is empty for all 1000
		 * imported products because the Importer's enrichment looked
		 * up an empty string.
		 *
		 * Fix in this version:
		 *
		 * 1. Backfill brdnam from raw_data JSON (preserved from the
		 *    refreshLookups call - no re-fetch needed).
		 *
		 * 2. Update feeds.php processBrandLookup helper to check BRDNM
		 *    first (the correct field), falling back to the previous
		 *    guesses for resilience.
		 *
		 * After install, admin must click "Run Import" in the Dashboard
		 * to refresh gd_catalog.brand from the now-populated brand
		 * lookup table.
		 *
		 * Per CLAUDE.md rule #51: sanity check compares against PREVIOUS
		 * version (10013). */

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
				'gdcatalog v1.0.14 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10014' ); } catch ( \Throwable ) {}

			if ( $longVer < 10013 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.14 WARNING: app_long_version=%d below 10013',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10014' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.14 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10014' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Backfill brdnam from raw_data JSON.
		 *
		 * We iterate all rows where brdnam is empty AND raw_data has BRDNM.
		 * Decode the raw_data, extract BRDNM, write to brdnam column. */
		$updated = 0;
		$skipped = 0;
		$errored = 0;

		try
		{
			$rows = \IPS\Db::i()->select(
				'brdno, raw_data',
				'gd_sportssouth_brands',
				[ "brdnam='' OR brdnam IS NULL" ]
			);

			foreach ( $rows as $row )
			{
				$rawJson = (string) ( $row['raw_data'] ?? '' );
				if ( $rawJson === '' )
				{
					$skipped++;
					continue;
				}

				$decoded = json_decode( $rawJson, true );
				if ( !is_array( $decoded ) )
				{
					$skipped++;
					continue;
				}

				/* Real field is BRDNM. Keep fallbacks for resilience. */
				$brdnam = (string) (
					$decoded['BRDNM']
					?? $decoded['BRDNAM']
					?? $decoded['BRDNAME']
					?? $decoded['BRDDESC']
					?? ''
				);

				if ( $brdnam === '' )
				{
					$skipped++;
					continue;
				}

				try
				{
					\IPS\Db::i()->update(
						'gd_sportssouth_brands',
						[ 'brdnam' => $brdnam ],
						[ 'brdno=?', (int) $row['brdno'] ]
					);
					$updated++;
				}
				catch ( \Throwable $rowException )
				{
					$errored++;
					try { \IPS\Log::log( 'Brand backfill failed brdno=' . $row['brdno'] . ': ' . $rowException->getMessage(), 'gdcatalog_upg_10014' ); } catch ( \Throwable ) {}
				}
			}

			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.14 brand backfill: updated=%d skipped=%d errored=%d', $updated, $skipped, $errored ), 'gdcatalog_upg_10014' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.14 brand backfill outer failed: ' . $e->getMessage(), 'gdcatalog_upg_10014' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Cache invalidation. */
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
		return 'gdcatalog v1.0.14 - backfill brand names from Sports South raw_data';
	}
}

class upgrade extends _upgrade {}
