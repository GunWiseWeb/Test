<?php
namespace IPS\gdcatalog\setup\upg_10007;

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
		/* gdcatalog v1.0.7 - Foundational versions.json structure fix.
		 *
		 * Mirrors gddealer v1.0.179 fix. gdcatalog's data/versions.json had
		 * the wrong structure {"1.0.6": 10006} since v1.0.0. IPS 5.0.18
		 * expects {"10006": "1.0.6"} (int-key, string-value).
		 *
		 * Per CLAUDE.md rule #50: wrong shape causes app_long_version to
		 * receive truncated garbage values from MySQL string->int cast,
		 * making IPS think the app is at version 10 (or similar) and
		 * re-running all historical upgrade scripts on every install.
		 *
		 * gdcatalog hadn't manifested this bug yet because the only install
		 * was v1.0.6 from a fresh install. Without this fix, the next time
		 * we ship a new gdcatalog version, install would trigger mass
		 * upgrade re-runs of upg_10001-10006.
		 *
		 * v1.0.7 fixes this by:
		 *   - Shipping the flipped versions.json (correct {long:human} shape)
		 *   - Logging post-install version to core_log for verification
		 *
		 * Per CLAUDE.md rule #51: this sanity check compares against the
		 * PREVIOUS version (10006), not the current one (10007), because
		 * IPS updates core_applications.app_long_version AFTER all upgrade
		 * scripts complete. Reading app_long_version during step1() always
		 * sees the pre-upgrade value.
		 *
		 * REQUIRED PRE-INSTALL SQL (must be run BEFORE installing v1.0.7
		 * tarball, otherwise IPS will think app is at the broken garbage
		 * version and re-run all upgrades 1-6):
		 *
		 *   UPDATE core_applications SET app_long_version=10006,
		 *     app_version='1.0.6' WHERE app_directory='gdcatalog';
		 */

		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gdcatalog' ]
			)->first();

			$longVer  = (int) ( $row['app_long_version'] ?? 0 );
			$humanVer = (string) ( $row['app_version'] ?? '' );

			$msg = sprintf(
				'gdcatalog v1.0.7 sanity (pre-version-write): app_long_version=%d, app_version=%s. ' .
				'IPS will update this row to 10007/1.0.7 AFTER step1() returns.',
				$longVer,
				$humanVer
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10007' ); } catch ( \Throwable ) {}

			/* If we see app_long_version below 10006, the structure fix is
			 * needed but the pre-install SQL was not run. IPS will now run
			 * upgrades 1-6 to "catch up", which re-runs already-completed
			 * migrations. They should all be idempotent (duplicate column
			 * errors are non-fatal) but logs will be noisy. */
			if ( $longVer < 10006 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.7 WARNING: app_long_version=%d below 10006 entering this upgrade. ' .
					'Pre-install SQL was likely not run. ' .
					'IPS may re-run upgrades 1-6 to catch up. Logs may be noisy. ' .
					'After install completes, manually verify with: ' .
					'SELECT app_long_version FROM core_applications WHERE app_directory=\'gdcatalog\'',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10007' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.7 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10007' ); } catch ( \Throwable ) {}
		}

		/* Cache invalidation - rebuild applications cache. */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'extensions%' OR store_key LIKE 'applications%' OR store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'lang%' OR store_key LIKE 'words%' OR store_key LIKE 'settings%'" ] ); } catch ( \Throwable ) {}

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
		return 'gdcatalog v1.0.7 - foundational versions.json structure fix (mirrors gddealer v1.0.179)';
	}
}

class upgrade extends _upgrade {}
