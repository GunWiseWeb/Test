<?php
namespace IPS\gddealer\setup\upg_10180;

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
		/* gddealer v1.0.180 - Capture snapshot data on unmatched UPCs.
		 *
		 * Adds a snapshot_json LONGTEXT column to gd_unmatched_upcs so the
		 * Importer can persist the dealer's title/brand/mpn/image_url/msrp
		 * for each unmatched UPC at feed-parse time. Without this, the
		 * planned gdcatalog admin review queue (v1.0.40 there) has nothing
		 * useful to display - only UPC + dealer + count.
		 *
		 * This is the GDDEALER-side prerequisite. After install:
		 *   - Existing rows have snapshot_json IS NULL (data prior to v180)
		 *   - New unmatched UPCs from new feed runs populate snapshot_json
		 *   - Subsequent occurrences of an already-tracked UPC overwrite
		 *     snapshot_json with the freshest values (typo fixes win)
		 *
		 * UnmatchedUpc::record() picks up an optional 3rd $snapshot parameter
		 * (PHP patch). Importer.php line 92 patched to pass canonical record
		 * fields through to it (PHP patch).
		 *
		 * Backward-compatible: every existing reader of gd_unmatched_upcs
		 * (the dealer dashboard, admin unmatched view, DealerShellTrait
		 * badge count) ignores the new column. The dealer-facing exclude
		 * flow is preserved.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10179). */

		/* Step 1: Sanity check */
		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gddealer' ]
			)->first();

			$longVer = (int) ( $row['app_long_version'] ?? 0 );
			$msg = sprintf(
				'gddealer v1.0.180 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gddealer_upg_10180' ); } catch ( \Throwable ) {}

			if ( $longVer < 10179 )
			{
				$warning = sprintf(
					'gddealer v1.0.180 WARNING: app_long_version=%d below 10179',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gddealer_upg_10180' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.180 sanity check failed: ' . $e->getMessage(), 'gddealer_upg_10180' ); } catch ( \Throwable ) {}
		}

		/* Step 2: ALTER TABLE gd_unmatched_upcs add snapshot_json column.
		 *
		 * Idempotent - check SHOW COLUMNS first. Per CLAUDE.md memory: always
		 * verify actual table schema before ALTER. */
		$alreadyExists = false;
		try
		{
			foreach ( \IPS\Db::i()->query( "SHOW COLUMNS FROM gd_unmatched_upcs" ) as $col )
			{
				if ( ( $col['Field'] ?? '' ) === 'snapshot_json' )
				{
					$alreadyExists = true;
					break;
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddealer v1.0.180 SHOW COLUMNS failed: ' . $e->getMessage(), 'gddealer_upg_10180' ); } catch ( \Throwable ) {}
		}

		if ( $alreadyExists )
		{
			try { \IPS\Log::log( 'gddealer v1.0.180 column gd_unmatched_upcs.snapshot_json already exists, skipping ALTER', 'gddealer_upg_10180' ); } catch ( \Throwable ) {}
		}
		else
		{
			try
			{
				\IPS\Db::i()->query(
					"ALTER TABLE gd_unmatched_upcs ADD COLUMN snapshot_json LONGTEXT NULL AFTER admin_excluded"
				);
				try { \IPS\Log::log( 'gddealer v1.0.180 added column gd_unmatched_upcs.snapshot_json', 'gddealer_upg_10180' ); } catch ( \Throwable ) {}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'gddealer v1.0.180 ALTER snapshot_json FAILED: ' . $e->getMessage(), 'gddealer_upg_10180' ); } catch ( \Throwable ) {}
			}
		}

		/* Step 3: Cache invalidation */
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
		return 'gddealer v1.0.180 - snapshot_json for unmatched UPCs';
	}
}

class upgrade extends _upgrade {}
