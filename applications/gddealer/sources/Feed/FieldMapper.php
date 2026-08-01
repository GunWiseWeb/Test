<?php
/**
 * @brief       GD Dealer Manager - Field Mapping applier
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 * @updated     v1.0.148 - Now uses the CanonicalFields registry as the source
 *              of truth for valid mapping targets. Adds a storage-translation
 *              layer so the wizard/validator/docs can speak v1.1 canonical
 *              names (price, sku, url) while persistence stays on the
 *              existing storage columns (dealer_price, dealer_sku,
 *              listing_url) until a future schema migration renames them.
 *
 * Given a raw parsed record and a JSON field map (dealer_field => canonical
 * field), produce a normalized canonical record ready for upsert into
 * gd_dealer_listings.
 */

namespace IPS\gddealer\Feed;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class FieldMapper
{
    /**
     * Map of v1.1 canonical slug => current storage column name.
     *
     * For fields where the canonical name and storage column already match,
     * the entry is omitted (we use array_key_exists for membership and the
     * input slug as the storage name when no override is present).
     *
     * @return array<string, string>
     */
    protected static function canonicalToStorage(): array
    {
        return [
            'price'         => 'dealer_price',
            'sku'           => 'dealer_sku',
            'url'           => 'listing_url',
        ];
    }

    /**
     * Backwards-compat: storage-side names used by older callers (the
     * pre-v148 importer wrote directly to these). Anyone calling
     * apply() with old-style keys still works.
     */
    protected static function legacyStorageAliases(): array
    {
        return [
            'dealer_price' => 'price',
            'dealer_sku'   => 'sku',
            'listing_url'  => 'url',
        ];
    }

    /**
     * Translate a canonical field name (v1.1) to the storage column name
     * used by gd_dealer_listings. Returns the input unchanged when no
     * translation is needed.
     */
    /**
     * Like apply(), but outputs records keyed by CANONICAL field names
     * (price, url, sku) instead of STORAGE column names (dealer_price,
     * listing_url, dealer_sku). Suitable for passing to Validator,
     * which was written against the v1.1 canonical spec.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $fieldMap
     * @return array<string, mixed>
     */
    public static function applyCanonical( array $record, array $fieldMap ): array
    {
        $storage = self::apply( $record, $fieldMap );

        /* Reverse the storage mapping. {dealer_price=>price, ...} */
        $storageToCanonical = array_flip( self::canonicalToStorage() );

        $out = [];
        foreach ( $storage as $key => $value )
        {
            $canonicalKey = $storageToCanonical[ $key ] ?? $key;
            $out[ $canonicalKey ] = $value;
        }
        return $out;
    }

    public static function canonicalToStorageColumn( string $canonical ): string
    {
        $map = self::canonicalToStorage();
        return $map[ $canonical ] ?? $canonical;
    }

    /**
     * Apply a field map to a raw feed record, producing a canonical record
     * ready for the validator and the upsert step.
     *
     * Output keys are STORAGE column names (dealer_price, listing_url,
     * dealer_sku) for backward compatibility with the existing importer
     * and gd_dealer_listings schema. The validator and wizard work with
     * canonical v1.1 names internally; this method is the boundary.
     *
     * Category-specific fields (ammo.caliber, etc.) are preserved as-is
     * with their dotted slug since the importer does not yet persist them.
     *
     * @param array<string, mixed>  $record     Raw record from the feed parser
     * @param array<string, string> $fieldMap   dealer_field => canonical_field
     * @return array<string, mixed>             Canonical record (storage keys)
     */
    public static function apply( array $record, array $fieldMap ): array
    {
        $validCanonical = CanonicalFields::allSlugs();
        $aliases = self::legacyStorageAliases();
        $storageMap = self::canonicalToStorage();

        /* Pull _defaults out of the map before the loop. They're applied
         * AFTER the dealer-field map below, only to canonicals that
         * weren't otherwise populated (so a feed value beats a default). */
        $defaults = [];
        if ( isset( $fieldMap['_defaults'] ) && is_array( $fieldMap['_defaults'] ) )
        {
            $defaults = $fieldMap['_defaults'];
            unset( $fieldMap['_defaults'] );
        }

        $out = [];
        foreach ( $fieldMap as $dealerField => $canonical )
        {
            /* Tolerate legacy storage names in the field map (pre-v148 maps
             * may use 'dealer_price' instead of 'price'). */
            if ( isset( $aliases[ $canonical ] ) )
            {
                $canonical = $aliases[ $canonical ];
            }

            if ( !in_array( $canonical, $validCanonical, true ) )
            {
                continue;
            }
            if ( !array_key_exists( $dealerField, $record ) )
            {
                /* v1.0.334 — auto-recovery for the URL canonical only:
                 * if the dealer's field_mapping says the raw URL field is
                 * called (e.g.) "url" but the real feed actually uses
                 * "link" / "permalink" / "product_page_url" / etc., fall
                 * through to the CanonicalFields URL alias list and take
                 * the first matching raw key. Scoped to `url` so it can't
                 * mis-populate a different canonical if the two fields
                 * happen to share an alias name.
                 *
                 * Why URL only: URL naming conventions vary wildly across
                 * feed formats (Google Merchant, LitCommerce, Shopify,
                 * BigCommerce, custom XML), whereas title/brand/upc
                 * naming is far more standardized. Broadening this to all
                 * canonicals would risk silent mis-mappings. */
                if ( $canonical !== 'url' )
                {
                    continue;
                }
                $recovered = self::_findUrlByAlias( $record );
                if ( $recovered === null )
                {
                    continue;
                }
                $storageKey = $storageMap[ 'url' ] ?? 'url';
                $out[ $storageKey ] = $recovered;
                continue;
            }

            /* Translate to storage column name. */
            $storageKey = $storageMap[ $canonical ] ?? $canonical;
            $out[ $storageKey ] = $record[ $dealerField ];
        }

        /* Apply defaults for canonicals not populated by the feed. */
        foreach ( $defaults as $canonical => $value )
        {
            $canonical = isset( $aliases[ $canonical ] ) ? $aliases[ $canonical ] : $canonical;
            if ( !in_array( $canonical, $validCanonical, true ) ) { continue; }
            $storageKey = $storageMap[ $canonical ] ?? $canonical;
            if ( !array_key_exists( $storageKey, $out ) )
            {
                $out[ $storageKey ] = $value;
            }
        }

        return self::normalize( $out );
    }

    /**
     * Normalize types on the canonical record. Accepts STORAGE column
     * names as keys (the output of apply()).
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    protected static function normalize( array $r ): array
    {
        if ( isset( $r['upc'] ) )
        {
            $r['upc'] = preg_replace( '/[^0-9A-Za-z]/', '', (string) $r['upc'] ) ?? '';
        }

        if ( isset( $r['dealer_price'] ) )
        {
            $r['dealer_price'] = (float) preg_replace( '/[^0-9.]/', '', (string) $r['dealer_price'] );
        }

        if ( isset( $r['map_price'] ) && $r['map_price'] !== null && $r['map_price'] !== '' )
        {
            $r['map_price'] = (float) preg_replace( '/[^0-9.]/', '', (string) $r['map_price'] );
        }

        $freeHint = isset( $r['free_shipping_hint'] )
            ? self::truthy( $r['free_shipping_hint'] )
            : false;

        if ( $freeHint )
        {
            $r['shipping_info'] = 'Free shipping';
        }
        elseif ( isset( $r['shipping_info'] ) && $r['shipping_info'] !== null )
        {
            $raw = trim( (string) $r['shipping_info'] );
            if ( $raw === '' )
            {
                $r['shipping_info'] = null;
            }
            elseif ( preg_match( '/^\$?\s*[0-9]+(\.[0-9]+)?\s*$/', $raw ) )
            {
                $num = (float) preg_replace( '/[^0-9.]/', '', $raw );
                $r['shipping_info'] = $num > 0
                    ? '$' . number_format( $num, 2 ) . ' flat'
                    : 'Free shipping';
            }
            else
            {
                $r['shipping_info'] = substr( $raw, 0, 60 );
            }
        }

        unset( $r['free_shipping_hint'] );

        if ( isset( $r['in_stock'] ) )
        {
            $r['in_stock'] = self::truthy( $r['in_stock'] ) ? 1 : 0;
        }
        if ( isset( $r['stock_qty'] ) && $r['stock_qty'] !== null && $r['stock_qty'] !== '' )
        {
            $r['stock_qty'] = (int) $r['stock_qty'];
        }

        if ( isset( $r['condition'] ) )
        {
            $r['condition'] = strtolower( trim( (string) $r['condition'] ) );
            if ( !in_array( $r['condition'], [ 'new', 'used', 'refurbished' ], true ) )
            {
                $r['condition'] = 'new';
            }
        }

        if ( isset( $r['category'] ) )
        {
            $r['category'] = strtolower( trim( (string) $r['category'] ) );
        }

        /* String-trim the descriptive optional fields. */
        foreach ( [ 'name', 'brand', 'mpn', 'dealer_sku', 'listing_url', 'image_url' ] as $sf )
        {
            if ( isset( $r[ $sf ] ) )
            {
                $r[ $sf ] = trim( (string) $r[ $sf ] );
            }
        }

        /* Normalize ammo enum subfields when present (lowercase + trim). */
        foreach ( [ 'ammo.fire_type', 'ammo.bullet_design', 'ammo.tip_color', 'ammo.case_material' ] as $sf )
        {
            if ( isset( $r[ $sf ] ) && (string) $r[ $sf ] !== '' )
            {
                $r[ $sf ] = strtolower( trim( (string) $r[ $sf ] ) );
            }
        }

        return $r;
    }

    /**
     * v1.0.334 — walk the raw record's keys looking for one that matches
     * any known URL-alias (per CanonicalFields::suggestionDictionary()).
     * Uses the same normalization CanonicalFields uses for its own key
     * matching (lowercase, strip non-alphanumerics), so `Product URL`,
     * `product-url`, `productUrl`, and `product_url` all collapse to
     * the same probe key. Returns the raw scalar value (trimmed) of
     * the first non-empty match, or null if no URL-like field is found.
     *
     * Only called from apply()'s auto-recovery branch for the `url`
     * canonical — never invoked for other canonicals, so it can't
     * accidentally mis-populate title/brand/etc.
     *
     * @param array<string, mixed> $record
     * @return string|null
     */
    protected static function _findUrlByAlias( array $record ): ?string
    {
        /* Build the normalized set of URL alias keys ONCE per call. */
        $urlKeys = [];
        try
        {
            foreach ( CanonicalFields::suggestionDictionary() as $variant => $canonical )
            {
                if ( $canonical !== 'url' ) { continue; }
                $urlKeys[ CanonicalFields::normalizeKey( $variant ) ] = true;
            }
        }
        catch ( \Throwable ) { return null; }

        if ( empty( $urlKeys ) ) { return null; }

        foreach ( $record as $rawKey => $rawVal )
        {
            if ( !is_scalar( $rawVal ) ) { continue; }
            $val = trim( (string) $rawVal );
            if ( $val === '' ) { continue; }
            $normKey = CanonicalFields::normalizeKey( (string) $rawKey );
            if ( isset( $urlKeys[ $normKey ] ) )
            {
                return $val;
            }
        }
        return null;
    }

    protected static function truthy( $v ): bool
    {
        if ( is_bool( $v ) ) return $v;
        if ( is_numeric( $v ) ) return ( (int) $v ) > 0;
        $s = strtolower( trim( (string) $v ) );
        return in_array( $s, [ '1', 'yes', 'y', 'true', 't', 'in stock', 'instock', 'available' ], true );
    }
}
