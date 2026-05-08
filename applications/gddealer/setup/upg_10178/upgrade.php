<?php
namespace IPS\gddealer\setup\upg_10178;

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
		/* v1.0.178 - clean up v1.0.177 issues.
		 *
		 * v1.0.177 had two bugs:
		 *   1. Lang key seeded was 'core_profile_mydealers' but IPS 5.0.18
		 *      actually uses 'profile_{app}_{ClassName}' - i.e.
		 *      'profile_gddealer_MyDealers'. Result: tab title displayed as
		 *      raw key in the UI.
		 *   2. MyDealers.php outer wrapper had padding:8px 0 (vertical only),
		 *      no horizontal padding. Result: card content was edge-hugging.
		 *
		 * Bug 1 is fixed in this upgrade.php (seeds the correct lang key).
		 * Bug 2 is fixed by shipping a corrected MyDealers.php in the tarball
		 * (no upgrade action needed - just a file replacement).
		 *
		 * Additionally, this version includes a DEFENSIVE check: if
		 * app_long_version is suspiciously low (under 10000), log a warning.
		 * On Derrick's server, v1.0.177 install caused app_long_version to
		 * reset to 10, triggering mass re-execution of all 175 prior upgrade
		 * scripts. Root cause never identified. This check at minimum gives
		 * us early warning if it happens again. */

		/* Step 1: Defensive version sanity check */
		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gddealer' ]
			)->first();

			$longVer = (int) ( $row['app_long_version'] ?? 0 );

			if ( $longVer < 10000 )
			{
				$msg = sprintf(
					'v1.0.178 SANITY: app_long_version=%d (expected >=10000). app_version=%s. ' .
					'This indicates an upgrade tracking regression. Manual fix needed:  ' .
					'UPDATE core_applications SET app_long_version=10178 WHERE app_directory=\'gddealer\'',
					$longVer,
					(string) ( $row['app_version'] ?? '' )
				);
				try { \IPS\Log::log( $msg, 'gddealer_upg_10178' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.178 sanity check failed: ' . $e->getMessage(), 'gddealer_upg_10178' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Seed the correct lang key for the My Dealers profile tab. */
		$correctKey = 'profile_gddealer_MyDealers';
		$correctVal = 'My Dealers';

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'      => (int) $langId,
						'word_app'     => 'gddealer',
						'word_key'     => $correctKey,
						'word_default' => $correctVal,
						'word_js'      => 0,
						'word_export'  => 1,
					] );
				}
				catch ( \Throwable $rowException )
				{
					try { \IPS\Log::log( 'v1.0.178 lang seed failed for lang_id=' . $langId . ': ' . $rowException->getMessage(), 'gddealer_upg_10178' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.178 lang seed outer failed: ' . $e->getMessage(), 'gddealer_upg_10178' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Cache invalidation - force IPS to rebuild extensions index
		 * and lang words from disk + DB. */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'extensions%' OR store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'lang%' OR store_key LIKE 'words%' OR store_key LIKE 'applications%'" ] ); } catch ( \Throwable ) {}

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
		return 'v1.0.178 - fix MyDealers tab lang key + outer padding + defensive version check';
	}
}

class upgrade extends _upgrade {}
