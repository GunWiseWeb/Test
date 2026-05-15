<?php
/**
 * @brief       GD Dealer Manager - Category Map
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

class _CategoryMap
{
    protected static array $cache = [];

    public static function lookup( int $dealerId, string $rawValue ): string
    {
        $key = trim( $rawValue );
        if ( $key === '' )
        {
            return 'accessory';
        }

        if ( isset( self::$cache[ $dealerId ][ $key ] ) )
        {
            self::bumpOccurrence( $dealerId, $key );
            return self::$cache[ $dealerId ][ $key ];
        }

        try
        {
            $row = \IPS\Db::i()->select( 'canonical', 'gd_dealer_category_map',
                [ 'dealer_id=? AND source_value=?', $dealerId, $key ]
            )->first();

            $canonical = (string) $row;
            self::$cache[ $dealerId ][ $key ] = $canonical;
            self::bumpOccurrence( $dealerId, $key );
            return $canonical;
        }
        catch ( \UnderflowException )
        {
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'CategoryMap lookup error: ' . $e->getMessage(), 'gddealer_category_map' ); } catch ( \Throwable ) {}
            return CategoryNormalizer::normalize( $key );
        }

        $canonical = CategoryNormalizer::normalize( $key );
        $now = date( 'Y-m-d H:i:s' );

        try
        {
            \IPS\Db::i()->insert( 'gd_dealer_category_map', [
                'dealer_id'        => $dealerId,
                'source_value'     => mb_substr( $key, 0, 255 ),
                'canonical'        => $canonical,
                'auto_mapped'      => 1,
                'occurrence_count' => 1,
                'first_seen'       => $now,
                'last_seen'        => $now,
            ] );
        }
        catch ( \Throwable $e )
        {
            try
            {
                $canonical = (string) \IPS\Db::i()->select( 'canonical', 'gd_dealer_category_map',
                    [ 'dealer_id=? AND source_value=?', $dealerId, $key ]
                )->first();
            }
            catch ( \Throwable ) {}
        }

        self::$cache[ $dealerId ][ $key ] = $canonical;
        return $canonical;
    }

    protected static function bumpOccurrence( int $dealerId, string $key ): void
    {
        try
        {
            \IPS\Db::i()->update( 'gd_dealer_category_map',
                [
                    'occurrence_count' => new \IPS\Db\Expression( 'occurrence_count + 1' ),
                    'last_seen'        => date( 'Y-m-d H:i:s' ),
                ],
                [ 'dealer_id=? AND source_value=?', $dealerId, $key ]
            );
        }
        catch ( \Throwable ) {}
    }

    public static function allForDealer( int $dealerId ): array
    {
        $out = [];
        try
        {
            foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_category_map',
                [ 'dealer_id=?', $dealerId ],
                'occurrence_count DESC, source_value ASC'
            ) as $row )
            {
                $out[] = [
                    'id'               => (int) $row['id'],
                    'source_value'     => (string) $row['source_value'],
                    'canonical'        => (string) $row['canonical'],
                    'auto_mapped'      => (bool) $row['auto_mapped'],
                    'occurrence_count' => (int) $row['occurrence_count'],
                    'first_seen'       => (string) $row['first_seen'],
                    'last_seen'        => (string) $row['last_seen'],
                ];
            }
        }
        catch ( \Throwable ) {}
        return $out;
    }

    public static function applyDealerEdits( int $dealerId, array $updates ): int
    {
        $applied = 0;
        foreach ( $updates as $id => $canonical )
        {
            $canonical = (string) $canonical;
            if ( !CategoryNormalizer::isValid( $canonical ) ) { continue; }

            try
            {
                \IPS\Db::i()->update( 'gd_dealer_category_map',
                    [
                        'canonical'   => $canonical,
                        'auto_mapped' => 0,
                    ],
                    [ 'id=? AND dealer_id=?', (int) $id, $dealerId ]
                );
                $applied++;
            }
            catch ( \Throwable ) {}
        }

        unset( self::$cache[ $dealerId ] );

        return $applied;
    }

    public static function unreviewedCount( int $dealerId ): int
    {
        try
        {
            return (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_category_map',
                [ 'dealer_id=? AND auto_mapped=?', $dealerId, 1 ]
            )->first();
        }
        catch ( \Throwable )
        {
            return 0;
        }
    }
}

class CategoryMap extends _CategoryMap {}
