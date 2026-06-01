<?php
namespace IPS\gdcatalog\Feed;

use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

class CompletenessScorer
{
	const REQUIRED_FIELDS = [
		1  => ['caliber', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		2  => ['caliber', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		3  => ['caliber', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		4  => ['caliber', 'action_type', 'barrel_length', 'image_url'],
		5  => ['caliber', 'action_type', 'barrel_length', 'image_url'],
		7  => ['caliber', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'overall_length', 'image_url'],
		8  => ['caliber', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'overall_length', 'image_url'],
		9  => ['caliber', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'overall_length', 'image_url'],
		10 => ['caliber', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'overall_length', 'image_url'],
		11 => ['caliber', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'overall_length', 'image_url'],
		12 => ['caliber', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		13 => ['caliber', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		14 => ['caliber', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		15 => ['caliber', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		16 => ['gauge', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		17 => ['gauge', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		18 => ['gauge', 'capacity', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		19 => ['gauge', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		20 => ['gauge', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		21 => ['gauge', 'action_type', 'barrel_length', 'weight_lbs', 'image_url'],
		22 => ['gauge', 'action_type', 'barrel_length', 'image_url'],
		23 => ['caliber', 'bullet_type', 'bullet_weight', 'rounds_per_box', 'image_url'],
		38 => ['caliber', 'capacity', 'image_url'],
		44 => ['magnification', 'image_url'],
		31 => ['caliber', 'overall_length', 'image_url'],
	];

	const BASE_REQUIRED = ['title', 'brand', 'msrp', 'image_url'];

	public static function score( array $product ): int
	{
		$catId    = (int) ( $product['category_id'] ?? 0 );
		$required = array_merge( self::BASE_REQUIRED, self::REQUIRED_FIELDS[ $catId ] ?? [] );

		if ( empty( $required ) )
		{
			return 100;
		}

		$filled = 0;
		foreach ( $required as $field )
		{
			if ( !empty( $product[ $field ] ) )
			{
				$filled++;
			}
		}

		return (int) round( ( $filled / count( $required ) ) * 100 );
	}
}
