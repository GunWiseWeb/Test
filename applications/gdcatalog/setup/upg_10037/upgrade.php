<?php
namespace IPS\gdcatalog\setup\upg_10037;

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
		/* gdcatalog v1.0.37 - HOTFIX: dashboard template arg order mismatch.
		 *
		 * The dashboard template's template_data declares positional args
		 * in this order:
		 *   $totalProducts, $activeProducts, $reviewProducts,
		 *   $pendingConflicts, $distributorStats, $taskUrls,
		 *   $osExists, $osStats, $reindexQueue, $lockedFields
		 *
		 * But dashboard.php manage() was calling ->dashboard() with a
		 * different arg order including 2 extra args ($categoryCounts and
		 * $pendingCompliance) that the template never expects. Positional
		 * matching put $categoryCounts (an array) where the template
		 * expected $pendingConflicts (int), causing the htmlspecialchars
		 * TypeError ('Argument #1 must be of type ?string, array given').
		 *
		 * This bug pre-existed v1.0.36 - it was always present but didn't
		 * crash until v1.0.36's template reseed triggered a recompile under
		 * strict argument checking.
		 *
		 * Fix: reorder controller args to match template_data declaration.
		 * Patch applied to dashboard.php via v137_PHP_PATCH.md.
		 *
		 * No schema changes. No template changes. PHP-only fix.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10036). */

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
				'gdcatalog v1.0.37 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10037' ); } catch ( \Throwable ) {}

			if ( $longVer < 10036 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.37 WARNING: app_long_version=%d below 10036',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10037' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.37 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10037' ); } catch ( \Throwable ) {}
		}

		try { \IPS\Log::log( 'gdcatalog v1.0.37 installed: dashboard arg-order fix', 'gdcatalog_upg_10037' ); } catch ( \Throwable ) {}

		/* Step 2: Cache invalidation - force template recompile */
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
		return 'gdcatalog v1.0.37 - hotfix dashboard arg-order';
	}
}

class upgrade extends _upgrade {}
