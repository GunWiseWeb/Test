<?php
namespace IPS\gdcatalog\setup\upg_10033;

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
		/* gdcatalog v1.0.33 - Discontinue safety guard hardening.
		 *
		 * Root cause: Importer::execute() truncates records to 1000 (the
		 * MAX_RECORDS_PER_RUN cap) and then calls processDiscontinuations()
		 * on a $seenUpcs map containing only those 1000 UPCs. With Sports
		 * South having 58,338 products in the catalog, this means each
		 * scheduled cron run marks ~57,338 products as "missed" - after 3
		 * such runs they discontinue.
		 *
		 * The v1.0.29 safety guard checked seenCount < 100, but 1000 >= 100
		 * so it passed and processDiscontinuations ran. We needed a guard
		 * that scales with catalog size.
		 *
		 * Fix: change the guard to "skip if seenCount < 80% of total active
		 * catalog for this distributor". Patches Importer.php directly via
		 * code change in this ship.
		 *
		 * Pre-install state (set by user via direct SQL):
		 *   - 73 wrongly-discontinued products reverted to active
		 *   - consecutive_misses reset to 0 for all 58,338 sports_south products
		 *   - ImportFeeds task disabled
		 *
		 * This upgrade.php:
		 *   1. Sanity check vs v1.0.32
		 *   2. Re-enable the ImportFeeds task (now safe)
		 *   3. Belt-and-suspenders: re-revert any discontinued sports_south
		 *      that have raw_distributor_data (idempotent - if user already
		 *      did this, ROW_COUNT=0)
		 *   4. Belt-and-suspenders: reset consecutive_misses to 0
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10032). */

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
				'gdcatalog v1.0.33 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10033' ); } catch ( \Throwable ) {}

			if ( $longVer < 10032 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.33 WARNING: app_long_version=%d below 10032',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10033' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.33 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10033' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Defensive revert + miss-reset (idempotent).
		 *
		 * If the user already did this manually via SQL, ROW_COUNT=0 and we
		 * log accordingly. If somehow new discontinues happened between the
		 * manual fix and v1.0.33 install, this catches them. */
		try
		{
			$beforeDisc = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [
				'record_status=? AND primary_source=? AND raw_distributor_data IS NOT NULL',
				'discontinued', 'sports_south'
			] )->first();

			if ( $beforeDisc > 0 )
			{
				try
				{
					$revertCount = \IPS\Db::i()->update(
						'gd_catalog',
						[
							'record_status' => 'active',
							'last_updated'  => date( 'Y-m-d H:i:s' ),
						],
						[
							'record_status=? AND primary_source=? AND raw_distributor_data IS NOT NULL',
							'discontinued', 'sports_south'
						]
					);
					try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.33 reverted %d discontinued products', (int) $revertCount ), 'gdcatalog_upg_10033' ); } catch ( \Throwable ) {}
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'gdcatalog v1.0.33 revert failed: ' . $e->getMessage(), 'gdcatalog_upg_10033' ); } catch ( \Throwable ) {}
				}
			}
			else
			{
				try { \IPS\Log::log( 'gdcatalog v1.0.33 revert: 0 discontinued sports_south products to revert (already clean)', 'gdcatalog_upg_10033' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.33 discontinue check failed: ' . $e->getMessage(), 'gdcatalog_upg_10033' ); } catch ( \Throwable ) {}
		}

		/* Reset consecutive_misses for all sports_south products.
		 *
		 * Uses raw SQL via heredoc since the UPDATE has a JSON_SET with $
		 * inside the path which IPS->update() can't handle cleanly. */
		try
		{
			$resetSql = <<<'SQL'
UPDATE gd_catalog
SET distributor_last_seen = JSON_SET(
    COALESCE(distributor_last_seen, '{}'),
    '$.sports_south.consecutive_misses', 0
)
WHERE primary_source = 'sports_south'
  AND JSON_EXTRACT(distributor_last_seen, '$.sports_south.consecutive_misses') > 0
SQL;
			$stmt = \IPS\Db::i()->query( $resetSql );
			try { \IPS\Log::log( 'gdcatalog v1.0.33 reset consecutive_misses for sports_south products with misses>0', 'gdcatalog_upg_10033' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.33 miss-reset failed: ' . $e->getMessage(), 'gdcatalog_upg_10033' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Re-enable ImportFeeds task. The Importer.php code change in
		 * this ship makes processDiscontinuations safe by requiring seenCount
		 * >= 80% of catalog to run. The scheduled cron path sees only 1000
		 * records (1.7% of catalog) so will always skip - safe. The queue
		 * path's full pass sees ~58k UPCs and will properly mark misses. */
		try
		{
			\IPS\Db::i()->update(
				'core_tasks',
				[ 'enabled' => 1 ],
				[ '`key`=?', 'ImportFeeds' ]
			);
			try { \IPS\Log::log( 'gdcatalog v1.0.33 re-enabled ImportFeeds task', 'gdcatalog_upg_10033' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.33 re-enable failed: ' . $e->getMessage(), 'gdcatalog_upg_10033' ); } catch ( \Throwable ) {}
		}

		/* Step 4: Cache invalidation */
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
		return 'gdcatalog v1.0.33 - discontinue safety guard hardening';
	}
}

class upgrade extends _upgrade {}
