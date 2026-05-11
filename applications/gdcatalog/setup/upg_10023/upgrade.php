<?php
namespace IPS\gdcatalog\setup\upg_10023;

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
		/* gdcatalog v1.0.23 - Parent-aware category filter in products.php.
		 *
		 * Bug:
		 *   gd_categories is hierarchical: Ammunition (17) has children
		 *   Handgun Ammo (18), Rifle Ammo (19), Shotgun Ammo (20),
		 *   Rimfire (21), Specialty/Exotic (22).
		 *
		 *   v1.0.22's category remap correctly put ammo products into the
		 *   leaf categories (18, 19, 20, 21, 22). But the filter in
		 *   products.php manage() did exact-match only:
		 *
		 *     $where[] = [ 'category_id=?', $catId ];
		 *
		 *   So selecting "Ammunition" (17) in the filter dropdown returned
		 *   0 results - none of the products are AT category_id=17, they're
		 *   in its children.
		 *
		 *   Same problem for Optics (34) -> Red Dots/Rifle Scopes/etc,
		 *   Hunting Gear (77) -> Game Calls/etc, Storage & Safety (58) ->
		 *   Hard Cases/Gun Safes/etc, Parts & Accessories (42) -> Stocks/
		 *   Grips/Rails/etc.
		 *
		 * Fix:
		 *   Patched products.php manage() to call a new helper method
		 *   collectCategoryDescendants($catId) that recursively walks the
		 *   gd_categories tree and returns all descendant IDs.
		 *
		 *   When admin selects a parent with children, the WHERE clause
		 *   becomes 'category_id IN (parent, child1, child2, ...)'.
		 *
		 *   Leaf categories (no children) still use exact match.
		 *
		 * No schema changes. No template changes. Just the controller patch.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10022). */

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
				'gdcatalog v1.0.23 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10023' ); } catch ( \Throwable ) {}

			if ( $longVer < 10022 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.23 WARNING: app_long_version=%d below 10022',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10023' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.23 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10023' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Cache invalidation to pick up the modified products.php */
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
		return 'gdcatalog v1.0.23 - parent-aware category filter';
	}
}

class upgrade extends _upgrade {}
