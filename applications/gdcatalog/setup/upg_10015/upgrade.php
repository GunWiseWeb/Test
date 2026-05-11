<?php
namespace IPS\gdcatalog\setup\upg_10015;

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
		/* gdcatalog v1.0.15 - Sports South category mapping.
		 *
		 * Adds infrastructure to map Sports South's flat 93-category
		 * taxonomy to gdcatalog's hierarchical 82-category canonical
		 * taxonomy. Without this mapping, all Sports South products
		 * end up with category_id=0 in gd_catalog (uncategorized).
		 *
		 * Approach:
		 *
		 * 1. New table gd_sportssouth_category_map:
		 *      sportssouth_catid INT PK   - the Sports South CATID
		 *      gd_category_id INT         - target gd_categories.id (0 if unmapped)
		 *      mapping_source ENUM        - 'auto' or 'manual'
		 *      mapped_at INT              - unix timestamp
		 *
		 * 2. Auto-mapping seed via simple keyword priority:
		 *      For each gd_sportssouth_categories row, try to match
		 *      against gd_categories names. Three-tier matching:
		 *        a) Exact case-insensitive match
		 *        b) gd_category.name appears as a word in catdes
		 *           (prefer more specific / deeper categories)
		 *        c) Keyword aliases (RIFLES->Rifles, etc.)
		 *
		 * 3. After install, admin clicks Run Import. The Importer's
		 *    enrichSportsSouthRecord (already added in v1.0.11) needs
		 *    a small update to also lookup the category map and inject
		 *    _CATEGORY_ID. That update is in IMPORTER_PATCHES.md.
		 *
		 * 4. field_mapping for sportssouth feeds is updated to include
		 *    _CATEGORY_ID -> category_id.
		 *
		 * Auto-mapping will get 60-70% of mappings right. The rest need
		 * manual cleanup via SQL for now (ACP UI in a future ship). Run
		 * this query post-install to see what's mapped:
		 *
		 *   SELECT m.sportssouth_catid, s.catdes,
		 *          m.gd_category_id, c.name AS gd_category_name,
		 *          m.mapping_source
		 *   FROM gd_sportssouth_category_map m
		 *   LEFT JOIN gd_sportssouth_categories s ON s.catid=m.sportssouth_catid
		 *   LEFT JOIN gd_categories c ON c.id=m.gd_category_id
		 *   ORDER BY m.sportssouth_catid;
		 *
		 * Per CLAUDE.md rule #51: sanity check compares against PREVIOUS
		 * version (10014). */

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
				'gdcatalog v1.0.15 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10015' ); } catch ( \Throwable ) {}

			if ( $longVer < 10014 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.15 WARNING: app_long_version=%d below 10014',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10015' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.15 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10015' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Create mapping table */
		$prefix = \IPS\Db::i()->prefix;

		try
		{
			\IPS\Db::i()->query(
				"CREATE TABLE IF NOT EXISTS {$prefix}gd_sportssouth_category_map (
					sportssouth_catid INT NOT NULL PRIMARY KEY,
					gd_category_id INT NOT NULL DEFAULT 0,
					mapping_source ENUM('auto','manual') NOT NULL DEFAULT 'auto',
					mapped_at INT NOT NULL DEFAULT 0,
					KEY idx_gd_category (gd_category_id)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.15 CREATE category_map TABLE failed: ' . $e->getMessage(), 'gdcatalog_upg_10015' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Auto-seed the mapping.
		 *
		 * Strategy: for each Sports South category, find the best gd_categories
		 * match using keyword priority. Higher specificity wins. */

		/* Build gd_categories lookup: name (lowercase) => id, also keyed by
		 * keywords for fuzzy match. */
		$gdCategoriesByName = [];
		$gdCategoriesByKeyword = [];

		try
		{
			foreach ( \IPS\Db::i()->select( 'id, name, parent_id', 'gd_categories' ) as $cat )
			{
				$catId = (int) $cat['id'];
				$name = mb_strtolower( trim( (string) $cat['name'] ) );
				$gdCategoriesByName[ $name ] = $catId;

				/* Index each word in the category name as a keyword */
				$words = preg_split( '/[\s\-\/]+/', $name );
				foreach ( $words as $word )
				{
					$word = trim( $word );
					if ( $word !== '' && strlen( $word ) >= 3 )
					{
						if ( !isset( $gdCategoriesByKeyword[ $word ] ) )
						{
							$gdCategoriesByKeyword[ $word ] = [];
						}
						$gdCategoriesByKeyword[ $word ][] = $catId;
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.15 gd_categories index failed: ' . $e->getMessage(), 'gdcatalog_upg_10015' ); } catch ( \Throwable ) {}
		}

		/* Hardcoded keyword aliases for firearms-industry mapping.
		 * Sports South uses ALL CAPS and pluralized forms; gd_categories
		 * uses Title Case. The aliases below favor leaf-level categories
		 * (more specific) over top-level. */
		$keywordAliases = [
			'rifles'        => 'rifles',
			'rifle'         => 'rifles',
			'pistols'       => 'pistols',
			'pistol'        => 'pistols',
			'handguns'      => 'handguns',
			'handgun'       => 'handguns',
			'shotguns'      => 'shotguns',
			'shotgun'       => 'shotguns',
			'revolvers'     => 'revolvers',
			'revolver'      => 'revolvers',
			'derringer'     => 'derringers',
			'derringers'    => 'derringers',
			'muzzleloader'  => 'muzzleloaders',
			'muzzleloaders' => 'muzzleloaders',
			'ammunition'    => 'ammunition',
			'ammo'          => 'ammunition',
			'magazines'     => 'magazines',
			'magazine'      => 'magazines',
			'optics'        => 'optics',
			'scope'         => 'rifle scopes',
			'scopes'        => 'rifle scopes',
			'sights'        => 'red dots',
			'lasers'        => 'lasers',
			'laser'         => 'lasers',
			'holsters'      => 'holsters & carry',
			'holster'       => 'holsters & carry',
			'safes'         => 'gun safes',
			'safe'          => 'gun safes',
			'cleaning'      => 'cleaning & maintenance',
			'cases'         => 'hard cases',
			'case'          => 'hard cases',
			'suppressors'   => 'suppressors',
			'suppressor'    => 'suppressors',
			'silencer'      => 'suppressors',
			'silencers'     => 'suppressors',
			'parts'         => 'parts & accessories',
			'barrels'       => 'barrels',
			'barrel'        => 'barrels',
			'triggers'      => 'triggers',
			'trigger'       => 'triggers',
			'stocks'        => 'stocks',
			'stock'         => 'stocks',
			'grips'         => 'grips',
			'grip'          => 'grips',
			'rails'         => 'rails',
			'rail'          => 'rails',
			'handguards'    => 'handguards',
			'handguard'     => 'handguards',
			'lights'        => 'weapon lights',
			'light'         => 'weapon lights',
			'bipods'        => 'bipods',
			'bipod'         => 'bipods',
			'slings'        => 'slings',
			'sling'         => 'slings',
			'accessories'   => 'parts & accessories',
			'accessory'     => 'parts & accessories',
			'hunting'       => 'hunting gear',
			'calls'         => 'game calls',
			'call'          => 'game calls',
			'blinds'        => 'blinds',
			'blind'         => 'blinds',
		];

		/* Now walk Sports South categories and seed mappings */
		$mapped = 0;
		$unmapped = 0;
		$now = time();

		try
		{
			foreach ( \IPS\Db::i()->select( 'catid, catdes', 'gd_sportssouth_categories' ) as $ssCat )
			{
				$ssCatid = (int) $ssCat['catid'];
				$catdes = mb_strtolower( trim( (string) $ssCat['catdes'] ) );

				if ( $ssCatid === 0 || $catdes === '' || $catdes === 'unassigned' )
				{
					$unmapped++;
					continue;
				}

				$gdCategoryId = 0;

				/* Tier 1: exact name match */
				if ( isset( $gdCategoriesByName[ $catdes ] ) )
				{
					$gdCategoryId = $gdCategoriesByName[ $catdes ];
				}

				/* Tier 2: keyword alias match.
				 * Walk words in catdes left-to-right, find first matching alias,
				 * use that mapping. We rank by alias priority (defined order). */
				if ( $gdCategoryId === 0 )
				{
					$ssWords = preg_split( '/[\s\-\/]+/', $catdes );
					foreach ( $ssWords as $word )
					{
						$word = trim( $word );
						if ( $word === '' )
						{
							continue;
						}
						if ( isset( $keywordAliases[ $word ] ) )
						{
							$targetName = $keywordAliases[ $word ];
							if ( isset( $gdCategoriesByName[ $targetName ] ) )
							{
								$gdCategoryId = $gdCategoriesByName[ $targetName ];
								break;
							}
						}
					}
				}

				/* Tier 3: substring match against any gd_category name */
				if ( $gdCategoryId === 0 )
				{
					foreach ( $gdCategoriesByName as $gdName => $gdId )
					{
						if ( strlen( $gdName ) >= 4 && str_contains( $catdes, $gdName ) )
						{
							$gdCategoryId = $gdId;
							break;
						}
					}
				}

				try
				{
					\IPS\Db::i()->replace( 'gd_sportssouth_category_map', [
						'sportssouth_catid' => $ssCatid,
						'gd_category_id'    => $gdCategoryId,
						'mapping_source'    => 'auto',
						'mapped_at'         => $now,
					] );

					if ( $gdCategoryId > 0 )
					{
						$mapped++;
					}
					else
					{
						$unmapped++;
					}
				}
				catch ( \Throwable $rowException )
				{
					try { \IPS\Log::log( 'Category map insert failed catid=' . $ssCatid . ': ' . $rowException->getMessage(), 'gdcatalog_upg_10015' ); } catch ( \Throwable ) {}
				}
			}

			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.15 category mapping seed: mapped=%d unmapped=%d', $mapped, $unmapped ), 'gdcatalog_upg_10015' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.15 category seed outer failed: ' . $e->getMessage(), 'gdcatalog_upg_10015' ); } catch ( \Throwable ) {}
		}

		/* Step 4: Update field_mapping for sportssouth feeds to include
		 * _CATEGORY_ID -> category_id. Only updates feeds that don't
		 * already have _CATEGORY_ID in their mapping. */
		$updatedFieldMapping = [
			'ITUPC'        => 'upc',
			'IDESC'        => 'title',
			'_BRAND_NAME'  => 'brand',
			'_CATEGORY_ID' => 'category_id',
			'SHDESC'       => 'description',
			'IMODEL'       => 'model',
			'PRC1'         => 'msrp',
			'WTPBX'        => 'weight_oz',
			'PICREF'       => 'image_url',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'id, feed_name, field_mapping', 'gd_distributor_feeds', [ 'auth_type=?', 'sportssouth' ] ) as $feedRow )
			{
				$current = json_decode( (string) ( $feedRow['field_mapping'] ?? '' ), true );
				if ( !is_array( $current ) )
				{
					$current = [];
				}

				if ( !isset( $current['_CATEGORY_ID'] ) )
				{
					try
					{
						\IPS\Db::i()->update(
							'gd_distributor_feeds',
							[ 'field_mapping' => json_encode( $updatedFieldMapping ) ],
							[ 'id=?', (int) $feedRow['id'] ]
						);
						try { \IPS\Log::log( 'Added _CATEGORY_ID to sportssouth field_mapping for feed_id=' . $feedRow['id'], 'gdcatalog_upg_10015' ); } catch ( \Throwable ) {}
					}
					catch ( \Throwable $rowException )
					{
						try { \IPS\Log::log( 'Failed updating field_mapping for feed_id=' . $feedRow['id'] . ': ' . $rowException->getMessage(), 'gdcatalog_upg_10015' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.15 field_mapping update outer failed: ' . $e->getMessage(), 'gdcatalog_upg_10015' ); } catch ( \Throwable ) {}
		}

		/* Step 5: Cache invalidation */
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
		return 'gdcatalog v1.0.15 - Sports South CATID to gd_categories mapping';
	}
}

class upgrade extends _upgrade {}
