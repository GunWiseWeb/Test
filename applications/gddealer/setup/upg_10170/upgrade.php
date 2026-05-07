<?php
namespace IPS\gddealer\setup\upg_10170;

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
		/* v1.0.170 - Phase A leaderboard fix.
		 *
		 * The DealerFollow ContentRouter extension registered IPS\gddealer\Dealer\Dealer
		 * as a content class, but Dealer extends ActiveRecord (not Content\Item).
		 * IPS leaderboard at applications/core/modules/front/discover/popular.php:96
		 * iterates registered content classes calling supportsComments() on each ->
		 * fatal "Call to undefined method" -> EX0 on every leaderboard page load.
		 *
		 * Existing custom follow functionality uses core_follow table directly via
		 * directory.php - does NOT depend on this extension. Removing it has no
		 * user-visible effect except unbreaking the leaderboard.
		 *
		 * The actual file deletion (extensions/core/ContentRouter/DealerFollow.php)
		 * happens at build time. The extensions.json entry under ContentRouter is
		 * also removed at build time. This upgrade just defensively self-heals
		 * any cached registration and clears caches.
		 *
		 * Phase B (later versions) will add notifications via the existing
		 * core_follow system without needing IPS Content\Item integration.
		 */

		/* Defensive self-heal: re-read extensions.json from disk, drop any
		 * stale ContentRouter entry from datastore cache. Per CLAUDE.md rule #16,
		 * IPS can occasionally overwrite extensions.json from a stale cache. */
		try
		{
			$extPath = \IPS\ROOT_PATH . '/applications/gddealer/data/extensions.json';
			if ( file_exists( $extPath ) )
			{
				$ext = json_decode( file_get_contents( $extPath ), true );
				if ( isset( $ext['core']['ContentRouter'] ) )
				{
					unset( $ext['core']['ContentRouter'] );
					file_put_contents( $extPath, json_encode( $ext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.170 extensions.json self-heal failed: ' . $e->getMessage(), 'gddealer_upg_10170' ); } catch ( \Throwable ) {}
		}

		/* Standard cache invalidation */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'extensions%' OR store_key LIKE 'applications%'" ] ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/extensions_*' ) ?: [] as $f )
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
		return 'v1.0.170 - remove broken DealerFollow ContentRouter extension (fixes leaderboard EX0)';
	}
}

class upgrade extends _upgrade {}
