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

		if ( !isset( $this->map[ $normalised ] ) )
		{
			$this->unmatched[ $normalised ] = $distributorCategory;
			return null;
		}

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
				$this->unmatched[ $normalised ] = $distributorCategory;
				return null;
			}
		}

		return $this->idCache[ $slug ];
	}

	public function resolve( int|string $rawCatId ): int
	{
		$key = (string) $rawCatId;

		if ( isset( $this->map[ $key ] ) )
		{
			return (int) $this->map[ $key ];
		}

		if ( is_numeric( $key ) && isset( $this->canonicalById[ $key ] ) )
		{
			return $this->canonicalById[ $key ];
		}

		$lower = strtolower( $key );
		if ( isset( $this->canonicalByName[ $lower ] ) )
		{
			return $this->canonicalByName[ $lower ];
		}

		if ( isset( $this->canonicalBySlug[ $lower ] ) )
		{
			return $this->canonicalBySlug[ $lower ];
		}

		return 58;
	}

	public function resolveFromCsv( string $value ): int
	{
		$value = trim( $value );
		if ( $value === '' )
		{
			return 58;
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

		return 58;
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
