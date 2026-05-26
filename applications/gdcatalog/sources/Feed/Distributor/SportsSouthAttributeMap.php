<?php
namespace IPS\gdcatalog\Feed\Distributor;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class SportsSouthAttributeMap
{
	const MAP = [
		'handguns' => [
			1 => 'caliber',
			2 => 'capacity',
			3 => 'action_type',
			4 => 'barrel_length',
			5 => 'finish',
			6 => 'frame_material',
			7 => 'receiver_type',
			8 => 'sight_type',
		],
		'rifles' => [
			1 => 'caliber',
			2 => 'action_type',
			3 => 'capacity',
			4 => 'barrel_length',
			5 => 'finish',
			6 => 'stock_type',
		],
		'shotguns' => [
			1 => 'action_type',
			2 => 'capacity',
			3 => 'barrel_length',
			4 => 'finish',
			5 => 'stock_type',
		],
		'ammunition' => [
			1 => 'description',
			2 => 'caliber',
			3 => 'weight_oz',
			4 => 'caliber',
		],
	];

	public static function resolve( string $categorySlug, int $itatrPosition ): ?string
	{
		$slugLower = mb_strtolower( $categorySlug );

		foreach ( self::MAP as $key => $positions )
		{
			if ( str_contains( $slugLower, $key ) )
			{
				return $positions[ $itatrPosition ] ?? null;
			}
		}

		return null;
	}
}
