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

    public function __construct()
    {
        $this->host  = \IPS\Settings::i()->gdcatalog_opensearch_host  ?: 'http://localhost:9200';
        $this->index = \IPS\Settings::i()->gdcatalog_opensearch_index ?: 'gunrack_products';
    }

    /**
     * Run a search query.
     *
     * @param string $query      Full-text search string
     * @param array  $filters    Associative array: category, brand, caliber, in_stock, min_price, max_price
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
            $must[] = [
                'bool' => [
                    'should' => [
                        [
                            'multi_match' => [
                                'query'     => $query,
                                'fields'    => [ 'title^3', 'mpn^4', 'brand^2', 'model^2', 'caliber^2', 'description', 'category', 'subcategory' ],
                                'type'      => 'best_fields',
                                'fuzziness' => 'AUTO',
                            ],
                        ],
                        [ 'match_phrase_prefix' => [ 'mpn'   => [ 'query' => $query, 'boost' => 5 ] ] ],
                        [ 'term'                => [ 'mpn.keyword'   => [ 'value' => $query, 'boost' => 8 ] ] ],
                    ],
                    'minimum_should_match' => 1,
                ],
            ];
        } else {
            $must[] = [ 'match_all' => (object) [] ];
        }

        // Always filter to active products
        $filter[] = [ 'term' => [ 'record_status' => 'active' ] ];

        // Optional filters
        if ( !empty( $filters['category'] ) ) {
            $filter[] = [ 'term' => [ 'category.keyword' => $filters['category'] ] ];
        }
        if ( !empty( $filters['brand'] ) ) {
            $filter[] = [ 'term' => [ 'brand.keyword' => $filters['brand'] ] ];
        }
        if ( !empty( $filters['caliber'] ) ) {
            $filter[] = [ 'term' => [ 'caliber.keyword' => $filters['caliber'] ] ];
        }
        if ( !empty( $filters['requires_ffl'] ) ) {
            $filter[] = [ 'term' => [ 'requires_ffl' => true ] ];
        }

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
            'query' => [
                'bool' => array_filter( [
                    'must'     => $must,
                    'filter'   => $filter,
                    'must_not' => $mustNot ?: null,
                ] ),
            ],
            'sort' => [ $sortClause ],
            'aggs' => [
                'categories' => [ 'terms' => [ 'field' => 'category.keyword',  'size' => 20 ] ],
                'brands'     => [ 'terms' => [ 'field' => 'brand.keyword',     'size' => 50 ] ],
                'calibers'   => [ 'terms' => [ 'field' => 'caliber.keyword',   'size' => 50 ] ],
            ],
        ];

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

        // Filter out-of-stock if requested
        if ( !empty( $filters['in_stock'] ) ) {
            $results = array_values( array_filter( $results, fn($r) => $r['in_stock'] ) );
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
    public function getDealerListings( string $upc ): array
    {
        $listings = [];
        try {
            foreach ( \IPS\Db::i()->select(
                'l.*, d.dealer_name, d.dealer_slug, d.subscription_tier',
                [ 'gd_dealer_listings', 'l' ],
                [ 'l.upc=? AND l.listing_status=?', $upc, 'active' ],
                'l.dealer_price ASC'
            )->join(
                [ 'gd_dealer_feed_config', 'd' ],
                'l.dealer_id = d.dealer_id'
            ) as $row ) {
                $listings[] = [
                    'dealer_id'    => (int) $row['dealer_id'],
                    'dealer_name'  => (string) $row['dealer_name'],
                    'dealer_slug'  => (string) $row['dealer_slug'],
                    'tier'         => (string) $row['subscription_tier'],
                    'price'        => (float) $row['dealer_price'],
                    'in_stock'     => (bool) $row['in_stock'],
                    'stock_qty'    => (int) ( $row['stock_qty'] ?? 0 ),
                    'condition'    => (string) ( $row['condition'] ?? 'new' ),
                    'listing_url'  => (string) ( $row['listing_url'] ?? '' ),
                    'free_shipping'=> (bool) ( $row['free_shipping'] ?? false ),
                    'shipping_cost'=> $row['shipping_cost'] !== null ? (float) $row['shipping_cost'] : null,
                ];
            }
        } catch ( \Throwable ) {}
        return $listings;
    }
}
