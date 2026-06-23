<?php
namespace IPS\gdsearch\Search;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * Queries gdcatalog's OpenSearch index and merges live dealer pricing.
 * Connection settings are read from gdcatalog's IPS settings keys.
 */
class Searcher
{
    protected string $host;
    protected string $index;

    protected static ?array $hiddenFacets = null;
    protected static function hiddenFacets(): array
    {
        if ( static::$hiddenFacets === null )
        {
            static::$hiddenFacets = [];
            try { foreach ( \IPS\Db::i()->select( 'facet_key', 'gd_facet_settings', [ 'hidden=?', 1 ] ) as $k ) { static::$hiddenFacets[ $k ] = TRUE; } } catch ( \Throwable ) {}
        }
        return static::$hiddenFacets;
    }

    public function __construct()
    {
        $this->host  = \IPS\Settings::i()->gdcatalog_opensearch_host  ?: 'http://localhost:9200';
        $this->index = \IPS\Settings::i()->gdcatalog_opensearch_index ?: 'gunrack_products';
    }

    /**
     * Run a search query.
     *
     * @param string $query      Full-text search string
     * @param array  $filters    Associative array: category, subcategory, brand, caliber, in_stock, min_price, max_price, etc.
     * @param string $sort       relevance|price_asc|price_desc|brand
     * @param int    $page       1-based page number
     * @param int    $perPage    Results per page
     * @return array             ['total' => int, 'results' => array, 'aggregations' => array]
     */
    public function search( string $query, array $filters = [], string $sort = 'relevance', int $page = 1, int $perPage = 24 ): array
    {
        $from = ( $page - 1 ) * $perPage;

        $must   = [];
        $filter = [];

        // Full-text query: name (title), MPN, brand, model, caliber, etc.
        // MPNs are often exact alphanumeric codes, so we OR an analyzed match,
        // a prefix match, and an exact keyword match for the mpn field.
        if ( $query !== '' ) {
            // A bare code/number (single token containing a digit — e.g. "67007", "150602", "9mm",
            // "AR-15") is an EXACT lookup: no fuzzy matching, so near-miss numbers (67057, 67067...)
            // are NOT returned. Word / multi-word queries keep fuzzy typo-tolerance.
            $isCode = (bool) ( preg_match( '/^[A-Za-z0-9][A-Za-z0-9\-\.\/]*$/', $query ) && preg_match( '/[0-9]/', $query ) );

            $should = [
                /* exact, non-fuzzy — the token must actually be present */
                [
                    'multi_match' => [
                        'query'  => $query,
                        'fields' => [ 'title^4', 'mpn^8', 'brand^3', 'model^3', 'caliber^3', 'description^3', 'category', 'subcategory' ],
                        'type'   => 'best_fields',
                        'boost'  => 12,
                    ],
                ],
                [ 'match_phrase_prefix' => [ 'mpn' => [ 'query' => $query, 'boost' => 5 ] ] ],
                [ 'term'                => [ 'mpn.keyword' => [ 'value' => $query, 'boost' => 8 ] ] ],
            ];

            /* fuzzy typo-tolerance ONLY for natural-language queries, never for codes/numbers */
            if ( !$isCode ) {
                $should[] = [
                    'multi_match' => [
                        'query'         => $query,
                        'fields'        => [ 'title^3', 'mpn^4', 'brand^2', 'model^2', 'caliber^2', 'description', 'category', 'subcategory' ],
                        'type'          => 'best_fields',
                        'fuzziness'     => 'AUTO',
                        'prefix_length' => 2,
                    ],
                ];
            }

            $must[] = [ 'bool' => [ 'should' => $should, 'minimum_should_match' => 1 ] ];
        } else {
            $must[] = [ 'match_all' => (object) [] ];
        }

        // Always filter to active products
        $filter[] = [ 'term' => [ 'record_status' => 'active' ] ];

        // Facet filters — accept scalar OR array (multi-select => terms/OR).
        $facet = function ( string $field, $val ) use ( &$filter ) {
            if ( is_array( $val ) ) {
                $val = array_values( array_filter( array_map( 'strval', $val ), fn( $v ) => $v !== '' ) );
                if ( $val ) { $filter[] = [ 'terms' => [ $field => $val ] ]; }
            } elseif ( $val !== '' && $val !== null ) {
                $filter[] = [ 'term' => [ $field => $val ] ];
            }
        };
        $facet( 'category.keyword',    $filters['category']    ?? '' );
        $facet( 'subcategory.keyword', $filters['subcategory'] ?? '' );
        $facet( 'brand.keyword',       $filters['brand']       ?? '' );
        $facet( 'caliber.keyword',     $filters['caliber']     ?? '' );
        $facet( 'action_type.keyword', $filters['action']      ?? '' );
        $facet( 'case_type.keyword',   $filters['casing']      ?? '' );
        $facet( 'bullet_type.keyword', $filters['bullet_type'] ?? '' );
        $facet( 'capacity',            $filters['capacity']    ?? '' );
        $facet( 'holster_type.keyword',     $filters['holster_type']    ?? '' );
        $facet( 'holster_color.keyword',    $filters['holster_color']   ?? '' );
        $facet( 'holster_material.keyword', $filters['holster_material'] ?? '' );
        $facet( 'holster_hand.keyword',     $filters['holster_hand']    ?? '' );
        $facet( 'apparel_pattern.keyword',  $filters['apparel_pattern'] ?? '' );
        $facet( 'apparel_size.keyword',     $filters['apparel_size']    ?? '' );
        $facet( 'apparel_material.keyword', $filters['apparel_material'] ?? '' );
        $facet( 'blade_shape.keyword',      $filters['blade_shape']     ?? '' );
        $facet( 'blade_length.keyword',     $filters['blade_length']    ?? '' );
        $facet( 'blade_material.keyword',   $filters['blade_material']  ?? '' );
        $facet( 'blade_edge.keyword',       $filters['blade_edge']      ?? '' );
        $facet( 'knife_handle.keyword',     $filters['knife_handle']    ?? '' );
        $facet( 'hunt_call_type.keyword',   $filters['hunt_call_type']  ?? '' );
        $facet( 'hunt_game.keyword',        $filters['hunt_game']       ?? '' );
        $facet( 'optic_magnification.keyword', $filters['optic_magnification'] ?? '' );
        $facet( 'optic_objective.keyword',     $filters['optic_objective']     ?? '' );
        $facet( 'product_type.keyword',        $filters['product_type']        ?? '' );

        if ( !empty( $filters['requires_ffl'] ) ) { $filter[] = [ 'term' => [ 'requires_ffl' => true ] ]; }
        if ( !empty( $filters['is_ammo'] ) )      { $filter[] = [ 'term' => [ 'is_ammo' => true ] ]; }

        if ( !empty( $filters['in_stock'] ) ) {
            $inStockUpcs = [];
            try {
                foreach ( \IPS\Db::i()->select( 'DISTINCT upc', 'gd_dealer_listings',
                    [ 'in_stock=? AND listing_status=?', 1, 'active' ], null, 65000 ) as $u ) {
                    if ( $u !== null && $u !== '' ) { $inStockUpcs[] = (string) $u; }
                }
            } catch ( \Throwable $e ) {
                try { \IPS\Log::log( $e, 'gdsearch_instock' ); } catch ( \Throwable $e2 ) {}
            }

            if ( empty( $inStockUpcs ) ) {
                $filter[] = [ 'terms' => [ 'upc.keyword' => [ '__none__' ] ] ];
            } else {
                $filter[] = [ 'terms' => [ 'upc.keyword' => $inStockUpcs ] ];
            }
        }

        // Numeric range filters
        $range = function ( string $field, $min, $max ) use ( &$filter ) {
            $r = [];
            if ( $min !== '' && $min !== null && (float) $min > 0 ) { $r['gte'] = (float) $min; }
            if ( $max !== '' && $max !== null && (float) $max > 0 ) { $r['lte'] = (float) $max; }
            if ( $r ) { $filter[] = [ 'range' => [ $field => $r ] ]; }
        };
        // Grain / velocity bands: each selected band is a range; multiple => OR.
        $bandFilter = function ( string $field, $bands ) use ( &$filter ) {
            if ( !empty( $bands ) && is_array( $bands ) ) {
                $should = [];
                foreach ( $bands as $b ) {
                    if ( is_array( $b ) && $b ) { $should[] = [ 'range' => [ $field => $b ] ]; }
                }
                if ( $should ) { $filter[] = [ 'bool' => [ 'should' => $should, 'minimum_should_match' => 1 ] ]; }
            }
        };
        $bandFilter( 'grain',           $filters['grain_bands']    ?? [] );
        $bandFilter( 'muzzle_velocity', $filters['velocity_bands'] ?? [] );
        $bandFilter( 'barrel_length',   $filters['barrel_bands']   ?? [] );

        $mustNot = [];
        if ( !empty( $filters['excludeCategoryIds'] ) && is_array( $filters['excludeCategoryIds'] ) ) {
            $mustNot[] = [ 'terms' => [ 'category_id' => array_values( $filters['excludeCategoryIds'] ) ] ];
        }

        // Sort
        $sortClause = match( $sort ) {
            'price_asc'  => [ 'msrp' => [ 'order' => 'asc',  'missing' => '_last'  ] ],
            'price_desc' => [ 'msrp' => [ 'order' => 'desc', 'missing' => '_last'  ] ],
            'brand'      => [ 'brand.keyword' => [ 'order' => 'asc' ] ],
            default      => [ '_score' => [ 'order' => 'desc' ] ],
        };

        $body = [
            'from'  => $from,
            'size'  => $perPage,
            'track_total_hits' => true,
            'query' => [
                'bool' => array_filter( [
                    'must'     => $must,
                    'filter'   => $filter,
                    'must_not' => $mustNot ?: null,
                ] ),
            ],
            'sort' => [ $sortClause ],
            'aggs' => [
                'categories' => [ 'terms' => [ 'field' => 'category.keyword', 'size' => 20 ] ],
                'subcategories' => [ 'terms' => [ 'field' => 'subcategory.keyword', 'size' => 50 ] ],
                'brands'     => [ 'terms' => [ 'field' => 'brand.keyword',    'size' => 50 ] ],
                /* Type-specific facets scoped to the categories where the attribute is valid, so
                   Sports South attribute bleed (e.g. a holster color landing in caliber) never
                   reaches a facet. Firearm top-levels per gd_categories; ammo via is_ammo. */
                'calibers' => [
                    'filter' => [ 'bool' => [ 'should' => [
                        [ 'terms' => [ 'category.keyword' => [ 'Handguns', 'Rifles', 'Shotguns', 'NFA Items', 'Air Guns', 'Muzzleloading' ] ] ],
                        [ 'term'  => [ 'is_ammo' => true ] ],
                    ], 'minimum_should_match' => 1 ] ],
                    'aggs' => [ 'values' => [ 'terms' => [ 'field' => 'caliber.keyword', 'size' => 50 ] ] ],
                ],
                'actions' => [
                    'filter' => [ 'terms' => [ 'category.keyword' => [ 'Handguns', 'Rifles', 'Shotguns', 'NFA Items', 'Air Guns', 'Muzzleloading' ] ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'action_type.keyword', 'size' => 30 ] ] ],
                ],
                'capacities' => [
                    'filter' => [ 'terms' => [ 'category.keyword' => [ 'Handguns', 'Rifles', 'Shotguns', 'NFA Items', 'Air Guns', 'Muzzleloading', 'Magazines' ] ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'capacity', 'size' => 40 ] ] ],
                ],
                'casings' => [
                    'filter' => [ 'term' => [ 'is_ammo' => true ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'case_type.keyword', 'size' => 20 ] ] ],
                ],
                'bullet_types' => [
                    'filter' => [ 'term' => [ 'is_ammo' => true ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'bullet_type.keyword', 'size' => 30 ] ] ],
                ],
                'barrel_present' => [ 'filter' => [ 'bool' => [ 'must' => [
                    [ 'range' => [ 'barrel_length' => [ 'gt' => 0 ] ] ],
                    [ 'terms' => [ 'category.keyword' => [ 'Handguns', 'Rifles', 'Shotguns', 'NFA Items', 'Air Guns', 'Muzzleloading' ] ] ],
                ] ] ] ],
                'holster_types' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Holsters & Carry' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'holster_type.keyword', 'size' => 30 ] ] ],
                ],
                'holster_colors' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Holsters & Carry' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'holster_color.keyword', 'size' => 30 ] ] ],
                ],
                'holster_materials' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Holsters & Carry' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'holster_material.keyword', 'size' => 30 ] ] ],
                ],
                'holster_hands' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Holsters & Carry' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'holster_hand.keyword', 'size' => 10 ] ] ],
                ],
                'apparel_patterns' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Apparel' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'apparel_pattern.keyword', 'size' => 30 ] ] ],
                ],
                'apparel_sizes' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Apparel' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'apparel_size.keyword', 'size' => 30 ] ] ],
                ],
                'apparel_materials' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Apparel' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'apparel_material.keyword', 'size' => 30 ] ] ],
                ],
                'blade_shapes' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Knives' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'blade_shape.keyword', 'size' => 30 ] ] ],
                ],
                'blade_lengths' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Knives' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'blade_length.keyword', 'size' => 30 ] ] ],
                ],
                'blade_materials' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Knives' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'blade_material.keyword', 'size' => 30 ] ] ],
                ],
                'blade_edges' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Knives' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'blade_edge.keyword', 'size' => 20 ] ] ],
                ],
                'knife_handles' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Knives' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'knife_handle.keyword', 'size' => 30 ] ] ],
                ],
                'hunt_call_types' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Hunting Gear' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'hunt_call_type.keyword', 'size' => 30 ] ] ],
                ],
                'hunt_games' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Hunting Gear' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'hunt_game.keyword', 'size' => 30 ] ] ],
                ],
                'optics_mags' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Optics' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'optic_magnification.keyword', 'size' => 40 ] ] ],
                ],
                'optics_objs' => [
                    'filter' => [ 'term' => [ 'category.keyword' => 'Optics' ] ],
                    'aggs'   => [ 'values' => [ 'terms' => [ 'field' => 'optic_objective.keyword', 'size' => 40 ] ] ],
                ],
                'product_types' => [
                    'terms' => [ 'field' => 'product_type.keyword', 'size' => 50 ],
                ],
            ],
        ];

        /* Hidden facets are suppressed only on the MAIN catalog page (no category).
           On a category page we aggregate everything so relevant facets still show. */
        $onCategoryPage = !empty( $filters['category'] ) || !empty( $filters['category_id'] );
        $hiddenF = $onCategoryPage ? [] : static::hiddenFacets();
        if ( $hiddenF )
        {
            foreach ( array_keys( $hiddenF ) as $hk )
            {
                unset( $body['aggs'][ $hk ] );
            }
        }

        try {
            $response = \IPS\Http\Url::external( $this->host . '/' . $this->index . '/_search' )
                ->request( 10 )
                ->setHeaders( [ 'Content-Type' => 'application/json' ] )
                ->post( json_encode( $body ) );

            $data = json_decode( (string) $response, true );
        } catch ( \Throwable ) {
            return [ 'total' => 0, 'results' => [], 'aggregations' => [] ];
        }

        $total   = (int) ( $data['hits']['total']['value'] ?? 0 );
        $hits    = $data['hits']['hits'] ?? [];
        $aggs    = $data['aggregations'] ?? [];

        // Build UPC list for pricing lookup
        $upcs = array_column( array_column( $hits, '_source' ), 'upc' );

        // Load live dealer pricing
        $pricing = $this->loadPricing( $upcs, !empty( $filters['in_stock'] ) );

        // Load ammo/spec extras from the catalog (grain etc. are not in the index)
        $extras = $this->loadCatalogExtras( $upcs );

        $results = [];
        foreach ( $hits as $hit ) {
            $src = $hit['_source'];
            $upc = $src['upc'];
            // Defaults guarantee the keys exist for the template (avoids undefined-key notices)
            $results[] = array_merge(
                [ 'caliber' => '', 'bullet_weight' => '', 'bullet_type' => '', 'casing_material' => '', 'rounds_per_box' => '' ],
                $src,
                $extras[ $upc ] ?? [],
                [
                    'best_price'    => $pricing[ $upc ]['best_price'] ?? null,
                    'dealer_count'  => $pricing[ $upc ]['dealer_count'] ?? 0,
                    'in_stock'      => $pricing[ $upc ]['in_stock'] ?? false,
                    'score'         => $hit['_score'] ?? 0,
                ]
            );
        }

        return [
            'total'        => $total,
            'results'      => $results,
            'aggregations' => $aggs,
        ];
    }

    /**
     * Load catalog spec extras (grain/bullet type/casing/rounds) for a list of UPCs.
     * These live in gd_catalog and are not stored in the search index.
     */
    protected function loadCatalogExtras( array $upcs ): array
    {
        if ( empty( $upcs ) ) return [];
        $out = [];
        try {
            foreach ( \IPS\Db::i()->select(
                'upc, caliber, bullet_weight, bullet_type, casing_material, rounds_per_box',
                'gd_catalog',
                \IPS\Db::i()->in( 'upc', $upcs )
            ) as $row ) {
                $out[ $row['upc'] ] = [
                    'caliber'         => (string) ( $row['caliber'] ?? '' ),
                    'bullet_weight'   => (string) ( $row['bullet_weight'] ?? '' ),
                    'bullet_type'     => (string) ( $row['bullet_type'] ?? '' ),
                    'casing_material' => (string) ( $row['casing_material'] ?? '' ),
                    'rounds_per_box'  => (string) ( $row['rounds_per_box'] ?? '' ),
                ];
            }
        } catch ( \Throwable ) {}
        return $out;
    }

    /**
     * Load best price and dealer count for a list of UPCs from gd_dealer_listings.
     */
    protected function loadPricing( array $upcs, bool $inStockOnly = false ): array
    {
        if ( empty( $upcs ) ) return [];

        $pricing = [];
        try {
            $where = [ \IPS\Db::i()->in( 'upc', $upcs ), [ 'listing_status=?', 'active' ] ];
            if ( $inStockOnly ) {
                $where[] = [ 'in_stock=?', 1 ];
            }

            foreach ( \IPS\Db::i()->select(
                'upc, MIN(dealer_price) as best_price, COUNT(DISTINCT dealer_id) as dealer_count, MAX(in_stock) as in_stock',
                'gd_dealer_listings',
                $where,
                null, null,
                'upc'
            ) as $row ) {
                $pricing[ $row['upc'] ] = [
                    'best_price'   => $row['best_price'] !== null ? (float) $row['best_price'] : null,
                    'dealer_count' => (int) $row['dealer_count'],
                    'in_stock'     => (bool) $row['in_stock'],
                ];
            }
        } catch ( \Throwable ) {}

        return $pricing;
    }

    /**
     * Load all dealer listings for a single UPC (price comparison table).
     */
    public function getDealerListings( string $upc, string $sort = 'standard' ): array
    {
        $listings = [];

        $orderBy = match( $sort ) {
            'price'    => 'l.dealer_price ASC',
            'shipping' => "(l.shipping_info IS NULL OR l.shipping_info = '') ASC, (l.shipping_info = 'Free shipping') DESC, l.dealer_price ASC",
            'rating'   => 'l.dealer_price ASC',
            default    => "(d.subscription_tier = 'max') DESC, l.dealer_price ASC",
        };

        try {
            $rows = \IPS\Db::i()->select(
                'l.*, d.dealer_name, d.dealer_slug, d.subscription_tier',
                [ 'gd_dealer_listings', 'l' ],
                [ 'l.upc=? AND l.listing_status=?', $upc, 'active' ],
                $orderBy
            )->join(
                [ 'gd_dealer_feed_config', 'd' ],
                'l.dealer_id = d.dealer_id'
            );

            $minPrice = null;
            $dealerIds = [];
            foreach ( $rows as $row ) {
                $price = (float) $row['dealer_price'];
                if ( $minPrice === null || $price < $minPrice ) { $minPrice = $price; }
                $did = (int) $row['dealer_id'];
                $dealerIds[ $did ] = true;
                $listings[] = [
                    'dealer_id'     => $did,
                    'dealer_name'   => (string) $row['dealer_name'],
                    'dealer_slug'   => (string) $row['dealer_slug'],
                    'tier'          => (string) $row['subscription_tier'],
                    'price'         => $price,
                    'in_stock'      => (bool) $row['in_stock'],
                    'stock_qty'     => (int) ( $row['stock_qty'] ?? 0 ),
                    'condition'     => (string) ( $row['condition'] ?? 'new' ),
                    'listing_url'   => (string) ( $row['listing_url'] ?? '' ),
                    'shipping_info' => (string) ( $row['shipping_info'] ?? '' ),
                    'avg_rating'    => null,
                    'is_lowest'     => false,
                ];
            }

            if ( $dealerIds ) {
                $ratingByDealer = [];
                try {
                    $in = implode( ',', array_map( 'intval', array_keys( $dealerIds ) ) );
                    foreach ( \IPS\Db::i()->select(
                        'dealer_id, AVG((rating_pricing + rating_shipping + rating_service) / 3) AS avg_rating',
                        'gd_dealer_ratings',
                        'dealer_id IN (' . $in . ')',
                        NULL,
                        NULL,
                        'dealer_id'
                    ) as $rr ) {
                        $ratingByDealer[ (int) $rr['dealer_id'] ] = $rr['avg_rating'] !== null ? round( (float) $rr['avg_rating'], 1 ) : null;
                    }
                } catch ( \Throwable $e ) { try { \IPS\Log::log( $e, 'gdsearch_listings' ); } catch ( \Throwable ) {} }

                foreach ( $listings as &$lrow ) {
                    $lrow['avg_rating'] = $ratingByDealer[ $lrow['dealer_id'] ] ?? null;
                }
                unset( $lrow );
            }

            if ( $minPrice !== null ) {
                foreach ( $listings as &$lrow2 ) {
                    if ( $lrow2['price'] === $minPrice ) { $lrow2['is_lowest'] = true; }
                }
                unset( $lrow2 );
            }

            if ( $sort === 'rating' ) {
                usort( $listings, function( $a, $b ) {
                    $ar = $a['avg_rating']; $br = $b['avg_rating'];
                    $aNull = ( $ar === null ); $bNull = ( $br === null );
                    if ( $aNull !== $bNull ) { return $aNull <=> $bNull; }
                    if ( !$aNull && $ar != $br ) { return $br <=> $ar; }
                    return $a['price'] <=> $b['price'];
                } );
            }
        } catch ( \Throwable $e ) {
            try { \IPS\Log::log( $e, 'gdsearch_listings' ); } catch ( \Throwable ) {}
        }
        return $listings;
    }
}
