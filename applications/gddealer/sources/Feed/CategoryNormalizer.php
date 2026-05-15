<?php
/**
 * @brief       GD Dealer Manager - Category Normalizer
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.188
 */

namespace IPS\gddealer\Feed;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class _CategoryNormalizer
{
    public const VALID = [
        'firearm', 'ammo', 'part', 'accessory',
        'optic', 'reloading', 'knife', 'apparel',
        'skip',
    ];

    protected static function rules(): array
    {
        return [
            [ 'patterns' => [ 'membership', 'gift card', 'gift certificate', 'service fee', 'donation', 'subscription' ],
              'canonical' => 'skip' ],

            [ 'patterns' => [ 'firearm', 'handgun', 'pistol', 'rifle', 'shotgun', 'long gun', 'revolver' ],
              'canonical' => 'firearm' ],

            [ 'patterns' => [ 'ammunition', 'ammo' ],
              'canonical' => 'ammo' ],

            [ 'patterns' => [ 'reloading', 'reload', 'brass cartridge', 'primer' ],
              'canonical' => 'reloading' ],

            [ 'patterns' => [ 'optic', 'scope', 'red dot', 'reflex sight', 'sight' ],
              'canonical' => 'optic' ],

            [ 'patterns' => [ 'knife', 'knives', 'blade' ],
              'canonical' => 'knife' ],

            [ 'patterns' => [ 'apparel', 'shirt', 'hoodie', 'hat', 'cap', 'pants', 'jacket', 'clothing' ],
              'canonical' => 'apparel' ],

            [ 'patterns' => [ 'magazine', 'gun parts', 'gun part', 'rail', 'barrel', 'trigger', 'grip', 'stock', 'upper', 'lower', 'bolt' ],
              'canonical' => 'part' ],
        ];
    }

    public static function normalize( string $raw ): string
    {
        $haystack = strtolower( trim( $raw ) );
        if ( $haystack === '' )
        {
            return 'accessory';
        }

        foreach ( self::rules() as $rule )
        {
            foreach ( $rule['patterns'] as $needle )
            {
                if ( strpos( $haystack, $needle ) !== false )
                {
                    return $rule['canonical'];
                }
            }
        }

        return 'accessory';
    }

    public static function isValid( string $canonical ): bool
    {
        return in_array( $canonical, self::VALID, true );
    }
}

class CategoryNormalizer extends _CategoryNormalizer {}
