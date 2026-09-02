<?php
namespace IPS\gdcatalog\Feed;

use IPS\gdcatalog\Catalog\Category;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class CategoryMapper
{
	protected array $map = [];

	protected array $idCache = [];

	protected array $unmatched = [];

	protected array $canonicalById = [];

	protected array $canonicalByName = [];

	protected array $canonicalBySlug = [];

	public function __construct( ?string $mappingJson )
	{
		if ( $mappingJson !== null && $mappingJson !== '' )
		{
			$decoded = json_decode( $mappingJson, true );
			if ( \is_array( $decoded ) )
			{
				foreach ( $decoded as $distString => $slug )
				{
					$this->map[ mb_strtolower( trim( $distString ) ) ] = trim( $slug );
				}
			}
		}

		try
		{
			foreach ( \IPS\Db::i()->select( 'id, name, slug', 'gd_categories' ) as $cat )
			{
				$this->canonicalById[ (string) $cat['id'] ]                   = (int) $cat['id'];
				$this->canonicalByName[ strtolower( (string) $cat['name'] ) ] = (int) $cat['id'];
				$this->canonicalBySlug[ strtolower( (string) $cat['slug'] ) ] = (int) $cat['id'];
			}
		}
		catch ( \Throwable ) {}
	}

	public function map( string $distributorCategory ): ?int
	{
		$normalised = mb_strtolower( trim( $distributorCategory ) );

		if ( $normalised === '' )
		{
			return null;
		}

		/* 1) Explicit per-feed mapping wins — this is what an admin
		 * configured for distributor-specific strings that don't
		 * match our canonical names verbatim. */
		if ( isset( $this->map[ $normalised ] ) )
		{
			$slug = $this->map[ $normalised ];

			if ( !isset( $this->idCache[ $slug ] ) )
			{
				try
				{
					$category = Category::loadBySlug( $slug );
					$this->idCache[ $slug ] = (int) $category->id;
				}
				catch ( \OutOfRangeException )
				{
					/* Configured slug doesn't exist — fall through to the
					 * canonical fallbacks below rather than giving up. */
				}
			}

			if ( isset( $this->idCache[ $slug ] ) )
			{
				return $this->idCache[ $slug ];
			}
		}

		/* 2) v1.0.136: fall back to a direct canonical lookup. This is
		 * what makes the Review Queue CSV round-trip work — an AI
		 * enrichment step that writes real category names ("Pistols")
		 * or slugs ("handguns-pistols") straight from categories.csv
		 * should resolve without every manual-upload source needing
		 * an explicit category_mapping JSON. */
		if ( isset( $this->canonicalByName[ $normalised ] ) )
		{
			return $this->canonicalByName[ $normalised ];
		}
		if ( isset( $this->canonicalBySlug[ $normalised ] ) )
		{
			return $this->canonicalBySlug[ $normalised ];
		}

		/* 3) Breadcrumb form ("Handguns > Pistols") — take the last
		 * segment and recurse. Matches how categories.csv presents
		 * full_path values to the AI. */
		if ( str_contains( $normalised, '>' ) )
		{
			$parts = explode( '>', $normalised );
			$tail  = trim( (string) end( $parts ) );
			if ( $tail !== '' && $tail !== $normalised )
			{
				return $this->map( $tail );
			}
		}

		$this->unmatched[ $normalised ] = $distributorCategory;
		return null;
	}

	protected ?int $uncatId = null;

	/**
	 * Resolve a distributor CATID strictly through the explicit mapping table.
	 * Unmapped CATIDs go to the visible "Uncategorized" holding category.
	 * The old numeric-id / name / slug fallbacks were removed: a CATID that
	 * happened to equal one of our category IDs was being silently miscategorised
	 * (e.g. SS CATID 47 "Targets" landing in category 47 "Rifle Scopes").
	 */
	public function resolve( int|string $rawCatId ): int
	{
		$key = (string) $rawCatId;

		if ( isset( $this->map[ $key ] ) )
		{
			return (int) $this->map[ $key ];
		}

		return $this->uncategorizedId();
	}

	protected function uncategorizedId(): int
	{
		if ( $this->uncatId !== null )
		{
			return $this->uncatId;
		}
		try
		{
			$this->uncatId = (int) \IPS\Db::i()->select( 'id', 'gd_categories', [ 'name=? AND parent_id=?', 'Uncategorized', 0 ] )->first();
		}
		catch ( \Throwable )
		{
			$this->uncatId = 58;
		}
		if ( $this->uncatId <= 0 )
		{
			$this->uncatId = 58;
		}
		return $this->uncatId;
	}

	public function resolveFromCsv( string $value ): int
	{
		$value = trim( $value );
		if ( $value === '' )
		{
			return $this->uncategorizedId();
		}

		$result = $this->resolve( $value );
		if ( $result !== 58 )
		{
			return $result;
		}

		$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $value ) );
		if ( isset( $this->canonicalBySlug[ $slug ] ) )
		{
			return $this->canonicalBySlug[ $slug ];
		}

		if ( strpos( $value, '>' ) !== false )
		{
			$parts = explode( '>', $value );
			return $this->resolveFromCsv( trim( end( $parts ) ) );
		}

		return $this->uncategorizedId();
	}

	public function getUnmatched(): array
	{
		return $this->unmatched;
	}

	public function hasUnmatched(): bool
	{
		return \count( $this->unmatched ) > 0;
	}

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
