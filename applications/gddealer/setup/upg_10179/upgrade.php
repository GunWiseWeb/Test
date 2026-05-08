<?php
namespace IPS\gddealer\setup\upg_10179;

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
		/* v1.0.179 - Foundational versions.json structure fix.
		 *
		 * ROOT CAUSE OF v177/v178 INSTALL DRAMA:
		 * Our versions.json has been wrong since v1.0.0. We were using
		 * { "1.0.178": 10178 } (string-key, int-value) but IPS 5.0.18
		 * expects { "10178": "1.0.178" } (int-key, string-value).
		 *
		 * IPS code at applications/.../system/Application/Application.php
		 * line 2092:
		 *   $longVersions = array_keys($versions);    // expects [10000, 10001, ...]
		 *   $humanVersions = array_values($versions); // expects ["1.0.0", "1.0.1", ...]
		 *   $latestLVersion = array_pop($longVersions);   // last LONG int
		 *   $latestHVersion = array_pop($humanVersions);  // last HUMAN string
		 *   Db::i()->update('core_applications', [
		 *     'app_version' => $latestHVersion,
		 *     'app_long_version' => $latestLVersion
		 *   ]);
		 *
		 * With our old (wrong) JSON shape, IPS got:
		 *   app_version = 10178  (an int, but column is VARCHAR so OK)
		 *   app_long_version = "1.0.178"  (string, INT column truncates)
		 *
		 * MySQL truncating the version string into the INT column gave
		 * weird values like 10 (from "10000" prefix being read mid-cast),
		 * which made IPS think the app was at version 10 → re-running 175
		 * upgrade scripts on every install.
		 *
		 * v179 fixes this by:
		 *   - Shipping the flipped versions.json (correct {long:human} shape)
		 *   - Logging a sanity check post-install to confirm app_long_version
		 *     stuck correctly
		 *
		 * IMPORTANT: this version requires a manual SQL UPDATE before install
		 * to set app_long_version=10178 (matching reality). Otherwise IPS
		 * sees "current=10, target=10179" and tries to run all upgrades again.
		 * The pre-install SQL is documented in the bundled prompt.
		 */

		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gddealer' ]
			)->first();

			$longVer  = (int) ( $row['app_long_version'] ?? 0 );
			$humanVer = (string) ( $row['app_version'] ?? '' );

			$msg = sprintf(
				'v1.0.179 post-install sanity: app_long_version=%d, app_version=%s',
				$longVer,
				$humanVer
			);
			try { \IPS\Log::log( $msg, 'gddealer_upg_10179' ); } catch ( \Throwable ) {}

			if ( $longVer < 10179 )
			{
				$warning = sprintf(
					'v1.0.179 WARNING: app_long_version=%d is below 10179 after install. ' .
					'This means versions.json structure fix did not stick. ' .
					'Manual fix: UPDATE core_applications SET app_long_version=10179, app_version=\'1.0.179\' WHERE app_directory=\'gddealer\'',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gddealer_upg_10179' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.179 sanity check failed: ' . $e->getMessage(), 'gddealer_upg_10179' ); } catch ( \Throwable ) {}
		}

		/* Cache invalidation - rebuild applications cache and store. */
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
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/lang*' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings );     } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'v1.0.179 - foundational versions.json structure fix + sanity logging';
	}
}

class upgrade extends _upgrade {}
