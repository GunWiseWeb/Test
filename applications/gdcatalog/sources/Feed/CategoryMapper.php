<?php
/**
 * @brief       GD Master Catalog — Category Mapper
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Translates each distributor's raw category strings into canonical
 * gd_categories IDs using the per-distributor category_mapping JSON
 * stored in gd_distributor_feeds.
 *
 * Category mapping JSON format (stored per feed):
 * {
 *     "Distributor Category String": "canonical-slug",
 *     "HANDGUNS - SEMI-AUTO": "handguns-pistols",
 *     "RIFLES": "rifles",
 *     ...
 * }
 *
 * The mapper normalises the incoming string (trim, lowercase) before lookup
 * so mappings are case-insensitive.
 */

namespace IPS\gdcatalog\Feed;

/* To prevent PHP errors (extending class does not exist) revealing path */

use IPS\gdcatalog\Catalog\Category;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class CategoryMapper
{
	/**
	 * @brief Normalised mapping: lowercase distributor string => category slug
	 */
	protected array $map = [];

	/**
	 * @brief Resolved slug-to-ID cache for this mapper instance
	 */
	protected array $idCache = [];

	/**
	 * @brief Unmatched strings collected during this run
	 */
	protected array $unmatched = [];

	/**
	 * Constructor — takes the raw category_mapping JSON from the feed config.
	 *
	 * @param  string|null $mappingJson  JSON string from gd_distributor_feeds.category_mapping
	 */
	public function __construct( ?string $mappingJson )
	{
		if ( $mappingJson === null || $mappingJson === '' )
		{
			return;
		}

		$decoded = json_decode( $mappingJson, true );
		if ( !\is_array( $decoded ) )
		{
			return;
		}

		/* Normalise keys to lowercase for case-insensitive matching */
		foreach ( $decoded as $distString => $slug )
		{
			$this->map[ mb_strtolower( trim( $distString ) ) ] = trim( $slug );
		}
	}

	/**
	 * Map a distributor category string to a gd_categories ID.
	 *
	 * @param  string $distributorCategory  The raw category value from the feed
	 * @return int|null  Category ID, or NULL if no mapping found
	 */
	public function map( string $distributorCategory ): ?int
	{
		$normalised = mb_strtolower( trim( $distributorCategory ) );

		if ( $normalised === '' )
		{
			return null;
		}

		/* Check mapping table */
		if ( !isset( $this->map[ $normalised ] ) )
		{
			$this->unmatched[ $normalised ] = $distributorCategory;
			return null;
		}

		$slug = $this->map[ $normalised ];

		/* Resolve slug to ID (cached per instance) */
		if ( !isset( $this->idCache[ $slug ] ) )
		{
			try
			{
				$category = Category::loadBySlug( $slug );
				$this->idCache[ $slug ] = (int) $category->id;
			}
			catch ( \OutOfRangeException )
			{
				/* Slug in mapping doesn't match any category — treat as unmatched */
				$this->unmatched[ $normalised ] = $distributorCategory;
				return null;
			}
		}

		return $this->idCache[ $slug ];
	}

	/**
	 * Resolve a raw numeric category ID to a canonical category ID using the mapping.
	 * Used when the field mapping maps CATID directly to category_id (numeric → numeric).
	 *
	 * @param  int|string $rawCatId  Raw distributor category ID
	 * @return int  Canonical category ID, or the raw value if no mapping found
	 */
	public function resolve( int|string $rawCatId ): int
	{
		$key = (string) $rawCatId;
		if ( isset( $this->map[ $key ] ) )
		{
			return (int) $this->map[ $key ];
		}
		return (int) $rawCatId;
	}

	/**
	 * Get all distributor category strings that could not be mapped during this run.
	 *
	 * @return array<string, string>  normalised => original
	 */
	public function getUnmatched(): array
	{
		return $this->unmatched;
	}

	/**
	 * Whether any unmatched categories were encountered.
	 *
	 * @return bool
	 */
	public function hasUnmatched(): bool
	{
		return \count( $this->unmatched ) > 0;
	}

	/**
	 * Build a suggested mapping template from the current category taxonomy.
	 * Useful for admin to populate the category_mapping field for a new distributor.
	 *
	 * @return array<string, string>  slug => display name (breadcrumb)
	 */
	public static function buildTemplate(): array
	{
		$template = [];
		foreach ( Category::roots() as $root )
		{
			$template[ $root->slug ] = $root->name;
			foreach ( $root->children() as $child )
			{
				$template[ $child->slug ] = $child->breadcrumb();
			}
		}
		return $template;
	}
}
