<?php
namespace IPS\gdsearch\modules\front\search;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

class _results extends \IPS\Dispatcher\Controller
{
    protected function manage(): void
    {
        $query      = trim( (string) ( \IPS\Request::i()->q ?? '' ) );

        if ( $query !== '' && preg_match( '/^[0-9]{8,14}$/', $query ) ) {
            try {
                \IPS\Db::i()->select( 'upc', 'gd_catalog', [ 'upc=? AND record_status=?', $query, 'active' ] )->first();
                \IPS\Output::i()->redirect(
                    \IPS\Http\Url::internal(
                        'app=gdsearch&module=search&controller=results&do=product&upc=' . $query,
                        'front', 'gdsearch_product'
                    )
                );
            } catch ( \UnderflowException ) {
            }
        }

        if ( $query !== '' ) {
            try {
                $mpnCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'mpn=? AND record_status=?', $query, 'active' ] )->first();
                if ( $mpnCount === 1 ) {
                    $mpnUpc = (string) \IPS\Db::i()->select( 'upc', 'gd_catalog', [ 'mpn=? AND record_status=?', $query, 'active' ] )->first();
                    if ( $mpnUpc !== '' ) {
                        \IPS\Output::i()->redirect(
                            \IPS\Http\Url::internal(
                                'app=gdsearch&module=search&controller=results&do=product&upc=' . $mpnUpc,
                                'front', 'gdsearch_product'
                            )
                        );
                    }
                }
            } catch ( \Throwable ) {
            }
        }
        $page       = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
        $sort       = (string) ( \IPS\Request::i()->sort ?? 'relevance' );
        $perPage    = max( 12, min( 48, (int) ( \IPS\Settings::i()->gdsearch_results_per_page ?: 24 ) ) );

        $validSorts = [ 'relevance', 'price_asc', 'price_desc', 'brand' ];
        if ( !in_array( $sort, $validSorts, true ) ) { $sort = 'relevance'; }

        $categoryId   = max( 0, (int) ( \IPS\Request::i()->category ?? 0 ) );
        $categoryName = '';
        if ( $categoryId > 0 ) {
            try {
                $categoryName = (string) \IPS\Db::i()->select( 'name', 'gd_categories', [ 'id=?', $categoryId ] )->first();
            } catch ( \Throwable ) { $categoryId = 0; }
        }

        $arr = function ( $v ) { if ( is_array( $v ) ) { return array_values( array_filter( array_map( fn($x)=>trim((string)$x), $v ), fn($x)=>$x!=='' ) ); } $v = trim( (string) $v ); return $v !== '' ? [ $v ] : []; };

        $toBands = function ( $vals ) {
            $out = [];
            foreach ( (array) $vals as $v ) {
                $p = explode( '-', (string) $v );
                $r = [];
                if ( isset( $p[0] ) && $p[0] !== '' ) { $r['gte'] = (float) $p[0]; }
                if ( isset( $p[1] ) && $p[1] !== '' ) { $r['lte'] = (float) $p[1]; }
                if ( $r ) { $out[] = $r; }
            }
            return $out;
        };

        $filters = [
            'category'       => $categoryName,
            'category_id'    => $categoryId,
            'brand'          => $arr( \IPS\Request::i()->brand ?? [] ),
            'caliber'        => $arr( \IPS\Request::i()->caliber ?? [] ),
            'action'         => $arr( \IPS\Request::i()->action ?? [] ),
            'casing'         => $arr( \IPS\Request::i()->casing ?? [] ),
            'bullet_type'    => $arr( \IPS\Request::i()->bullet_type ?? [] ),
            'capacity'       => $arr( \IPS\Request::i()->capacity ?? [] ),
            'holster_type'   => $arr( \IPS\Request::i()->holster_type ?? [] ),
            'holster_color'  => $arr( \IPS\Request::i()->holster_color ?? [] ),
            'holster_material' => $arr( \IPS\Request::i()->holster_material ?? [] ),
            'holster_hand'   => $arr( \IPS\Request::i()->holster_hand ?? [] ),
            'apparel_pattern' => $arr( \IPS\Request::i()->apparel_pattern ?? [] ),
            'apparel_size'   => $arr( \IPS\Request::i()->apparel_size ?? [] ),
            'apparel_material' => $arr( \IPS\Request::i()->apparel_material ?? [] ),
            'blade_shape'    => $arr( \IPS\Request::i()->blade_shape ?? [] ),
            'blade_length'   => $arr( \IPS\Request::i()->blade_length ?? [] ),
            'blade_material' => $arr( \IPS\Request::i()->blade_material ?? [] ),
            'blade_edge'     => $arr( \IPS\Request::i()->blade_edge ?? [] ),
            'knife_handle'   => $arr( \IPS\Request::i()->knife_handle ?? [] ),
            'hunt_call_type' => $arr( \IPS\Request::i()->hunt_call_type ?? [] ),
            'hunt_game'      => $arr( \IPS\Request::i()->hunt_game ?? [] ),
            'optic_magnification' => $arr( \IPS\Request::i()->optic_magnification ?? [] ),
            'optic_objective'     => $arr( \IPS\Request::i()->optic_objective     ?? [] ),
            'product_type'        => $arr( \IPS\Request::i()->product_type        ?? [] ),
            'material'            => $arr( \IPS\Request::i()->material            ?? [] ),
            'color'               => $arr( \IPS\Request::i()->color               ?? [] ),
            'finish'              => $arr( \IPS\Request::i()->finish              ?? [] ),
            'size'                => $arr( \IPS\Request::i()->size                ?? [] ),
            'mount_type'          => $arr( \IPS\Request::i()->mount_type          ?? [] ),
            'fit'                 => $arr( \IPS\Request::i()->fit                 ?? [] ),
            'battery_size'        => $arr( \IPS\Request::i()->battery_size        ?? [] ),
            'nrr'                 => $arr( \IPS\Request::i()->nrr                 ?? [] ),
            'lock_type'           => $arr( \IPS\Request::i()->lock_type           ?? [] ),
            'species'             => $arr( \IPS\Request::i()->species             ?? [] ),
            'is_ammo'        => !empty( \IPS\Request::i()->is_ammo ),
            'grain'          => $arr( \IPS\Request::i()->grain ?? [] ),
            'velocity'       => $arr( \IPS\Request::i()->velocity ?? [] ),
            'barrel'         => $arr( \IPS\Request::i()->barrel ?? [] ),
            'grain_bands'    => $toBands( \IPS\Request::i()->grain ?? [] ),
            'velocity_bands' => $toBands( \IPS\Request::i()->velocity ?? [] ),
            'barrel_bands'   => $toBands( \IPS\Request::i()->barrel ?? [] ),
            'in_stock'       => !empty( \IPS\Request::i()->in_stock ),
            'requires_ffl'   => !empty( \IPS\Request::i()->requires_ffl ),
            'min_price'      => (float) ( \IPS\Request::i()->min_price ?? 0 ),
            'max_price'      => (float) ( \IPS\Request::i()->max_price ?? 0 ),
        ];

        $hiddenCatIds = [];
        try {
            if ( \IPS\Db::i()->checkForColumn( 'gd_categories', 'hidden' ) ) {
                foreach ( \IPS\Db::i()->select( 'id', 'gd_categories', [ 'hidden=1' ] ) as $hid ) { $hiddenCatIds[] = (int) $hid; }
            }
        } catch ( \Throwable ) {}

        if ( $categoryId <= 0 && $hiddenCatIds ) {
            $filters['excludeCategoryIds'] = $hiddenCatIds;
        }

        $results    = [];
        $total      = 0;
        $aggs       = [];
        $error      = '';

        try {
            $searcher = new \IPS\gdsearch\Search\Searcher();
            $data     = $searcher->search( $query, $filters, $sort, $page, $perPage );
            $results  = $data['results'];
            $total    = $data['total'];
            $aggs     = $data['aggregations'];

            /* Scoped facet aggs return buckets one level deeper under 'values' — lift them up to the
               flat shape the blank-strip loop and template expect. */
            foreach ( [ 'calibers', 'actions', 'capacities', 'casings', 'bullet_types',
                'holster_types', 'holster_colors', 'holster_materials', 'holster_hands',
                'apparel_patterns', 'apparel_sizes', 'apparel_materials',
                'blade_shapes', 'blade_lengths', 'blade_materials', 'blade_edges', 'knife_handles',
                'hunt_call_types', 'hunt_games',
                'optics_mags', 'optics_objs', 'product_types', 'materials', 'colors',
                'finishes', 'sizes', 'mount_types', 'fits', 'battery_sizes', 'nrrs', 'lock_types', 'species_list' ] as $scopedAgg )
            {
                if ( isset( $aggs[ $scopedAgg ]['values']['buckets'] ) )
                {
                    $aggs[ $scopedAgg ]['buckets'] = $aggs[ $scopedAgg ]['values']['buckets'];
                }
            }
        } catch ( \Throwable $e ) {
            $error = $e->getMessage();
        }

        foreach ( [ 'categories', 'brands', 'calibers', 'actions', 'capacities', 'casings', 'bullet_types',
            'holster_types', 'holster_colors', 'holster_materials', 'holster_hands',
            'apparel_patterns', 'apparel_sizes', 'apparel_materials',
            'blade_shapes', 'blade_lengths', 'blade_materials', 'blade_edges', 'knife_handles',
            'hunt_call_types', 'hunt_games',
            'optics_mags', 'optics_objs', 'product_types', 'materials', 'colors',
            'finishes', 'sizes', 'mount_types', 'fits', 'battery_sizes', 'nrrs', 'lock_types', 'species_list' ] as $aggKey )
        {
            if ( isset( $aggs[ $aggKey ]['buckets'] ) && is_array( $aggs[ $aggKey ]['buckets'] ) )
            {
                $aggs[ $aggKey ]['buckets'] = array_values( array_filter(
                    $aggs[ $aggKey ]['buckets'],
                    static function ( $b ) { return trim( (string) ( $b['key'] ?? '' ) ) !== ''; }
                ) );
            }
        }

        $pagination = '';
        if ( $total > $perPage ) {
            $paginationQs = 'app=gdsearch&module=search&controller=results';
            if ( $query !== '' )                  { $paginationQs .= '&q=' . urlencode( $query ); }
            if ( $filters['category_id'] > 0 )   { $paginationQs .= '&category=' . $filters['category_id']; }
            foreach ( (array) $filters['brand'] as $v )       { $paginationQs .= '&brand[]='       . urlencode( $v ); }
            foreach ( (array) $filters['caliber'] as $v )     { $paginationQs .= '&caliber[]='     . urlencode( $v ); }
            foreach ( (array) $filters['action'] as $v )      { $paginationQs .= '&action[]='      . urlencode( $v ); }
            foreach ( (array) $filters['casing'] as $v )      { $paginationQs .= '&casing[]='      . urlencode( $v ); }
            foreach ( (array) $filters['bullet_type'] as $v ) { $paginationQs .= '&bullet_type[]=' . urlencode( $v ); }
            foreach ( (array) $filters['capacity'] as $v )    { $paginationQs .= '&capacity[]='    . urlencode( $v ); }
            foreach ( (array) $filters['holster_type'] as $v )     { $paginationQs .= '&holster_type[]='     . urlencode( $v ); }
            foreach ( (array) $filters['holster_color'] as $v )    { $paginationQs .= '&holster_color[]='    . urlencode( $v ); }
            foreach ( (array) $filters['holster_material'] as $v ) { $paginationQs .= '&holster_material[]=' . urlencode( $v ); }
            foreach ( (array) $filters['holster_hand'] as $v )     { $paginationQs .= '&holster_hand[]='     . urlencode( $v ); }
            foreach ( (array) $filters['apparel_pattern'] as $v )  { $paginationQs .= '&apparel_pattern[]='  . urlencode( $v ); }
            foreach ( (array) $filters['apparel_size'] as $v )     { $paginationQs .= '&apparel_size[]='     . urlencode( $v ); }
            foreach ( (array) $filters['apparel_material'] as $v ) { $paginationQs .= '&apparel_material[]=' . urlencode( $v ); }
            foreach ( (array) $filters['blade_shape'] as $v )      { $paginationQs .= '&blade_shape[]='      . urlencode( $v ); }
            foreach ( (array) $filters['blade_length'] as $v )     { $paginationQs .= '&blade_length[]='     . urlencode( $v ); }
            foreach ( (array) $filters['blade_material'] as $v )   { $paginationQs .= '&blade_material[]='   . urlencode( $v ); }
            foreach ( (array) $filters['blade_edge'] as $v )       { $paginationQs .= '&blade_edge[]='       . urlencode( $v ); }
            foreach ( (array) $filters['knife_handle'] as $v )     { $paginationQs .= '&knife_handle[]='     . urlencode( $v ); }
            foreach ( (array) $filters['hunt_call_type'] as $v )   { $paginationQs .= '&hunt_call_type[]='   . urlencode( $v ); }
            foreach ( (array) $filters['hunt_game'] as $v )        { $paginationQs .= '&hunt_game[]='        . urlencode( $v ); }
            foreach ( (array) $filters['optic_magnification'] as $v ) { $paginationQs .= '&optic_magnification[]=' . urlencode( $v ); }
            foreach ( (array) $filters['optic_objective'] as $v )     { $paginationQs .= '&optic_objective[]='     . urlencode( $v ); }
            foreach ( (array) $filters['product_type'] as $v )       { $paginationQs .= '&product_type[]='       . urlencode( $v ); }
            foreach ( (array) $filters['material'] as $v )           { $paginationQs .= '&material[]='           . urlencode( $v ); }
            foreach ( (array) $filters['color'] as $v )              { $paginationQs .= '&color[]='              . urlencode( $v ); }
            foreach ( (array) $filters['finish'] as $v )             { $paginationQs .= '&finish[]='             . urlencode( $v ); }
            foreach ( (array) $filters['size'] as $v )               { $paginationQs .= '&size[]='               . urlencode( $v ); }
            foreach ( (array) $filters['mount_type'] as $v )         { $paginationQs .= '&mount_type[]='         . urlencode( $v ); }
            foreach ( (array) $filters['fit'] as $v )                { $paginationQs .= '&fit[]='                . urlencode( $v ); }
            foreach ( (array) $filters['battery_size'] as $v )       { $paginationQs .= '&battery_size[]='       . urlencode( $v ); }
            foreach ( (array) $filters['nrr'] as $v )                { $paginationQs .= '&nrr[]='                . urlencode( $v ); }
            foreach ( (array) $filters['lock_type'] as $v )          { $paginationQs .= '&lock_type[]='          . urlencode( $v ); }
            foreach ( (array) $filters['species'] as $v )            { $paginationQs .= '&species[]='            . urlencode( $v ); }
            if ( !empty( $filters['is_ammo'] ) )       { $paginationQs .= '&is_ammo=1'; }
            foreach ( (array) $filters['grain'] as $v )    { $paginationQs .= '&grain[]='    . urlencode( $v ); }
            foreach ( (array) $filters['velocity'] as $v ) { $paginationQs .= '&velocity[]=' . urlencode( $v ); }
            foreach ( (array) $filters['barrel'] as $v )   { $paginationQs .= '&barrel[]='   . urlencode( $v ); }
            if ( !empty( $filters['in_stock'] ) ) { $paginationQs .= '&in_stock=1'; }
            if ( !empty( $filters['requires_ffl'] ) ) { $paginationQs .= '&requires_ffl=1'; }
            if ( $filters['min_price'] > 0 )      { $paginationQs .= '&min_price=' . urlencode( (string) $filters['min_price'] ); }
            if ( $filters['max_price'] > 0 )      { $paginationQs .= '&max_price=' . urlencode( (string) $filters['max_price'] ); }
            if ( $sort !== 'relevance' )          { $paginationQs .= '&sort=' . urlencode( $sort ); }

            $baseUrl = \IPS\Http\Url::internal( $paginationQs, 'front', 'gdsearch_results' );
            $pagination = (string) \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
                $baseUrl,
                (int) ceil( $total / $perPage ),
                $page,
                $perPage
            );
        }

        $categories = [];
        try {
            $catWhere = [ [ 'parent_id=?', 0 ] ];
            try { if ( \IPS\Db::i()->checkForColumn( 'gd_categories', 'hidden' ) ) { $catWhere[] = [ 'hidden=?', 0 ]; } } catch ( \Throwable ) {}
            foreach ( \IPS\Db::i()->select( 'id, name', 'gd_categories', $catWhere, 'name ASC' ) as $cat ) {
                $categories[] = [ 'id' => (int) $cat['id'], 'name' => (string) $cat['name'] ];
            }
        } catch ( \Throwable ) {}

        try { \IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'compare.js', 'gdsearch', 'interface' ) ); } catch ( \Throwable ) {}

        \IPS\Output::i()->title = $query
            ? $query . ' — ' . \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_results_title' )
            : \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_results_title' );

        $grainBands = [
            '0-59'    => 'Under 60 gr',
            '60-99'   => '60–99 gr',
            '100-124' => '100–124 gr',
            '125-149' => '125–149 gr',
            '150-199' => '150–199 gr',
            '200-'    => '200+ gr',
        ];
        $velocityBands = [
            '0-999'     => 'Under 1,000 fps',
            '1000-1499' => '1,000–1,499 fps',
            '1500-1999' => '1,500–1,999 fps',
            '2000-2499' => '2,000–2,499 fps',
            '2500-2999' => '2,500–2,999 fps',
            '3000-'     => '3,000+ fps',
        ];
        $barrelBands = [
            '0-3.99'    => 'Under 4"',
            '4-7.99'    => '4"–7.9"',
            '8-11.99'   => '8"–11.9"',
            '12-15.99'  => '12"–15.9"',
            '16-19.99'  => '16"–19.9"',
            '20-25.99'  => '20"–25.9"',
            '26-'       => '26"+',
        ];

        $hiddenFacets = [];
        if ( $categoryId <= 0 )
        {
            try { foreach ( \IPS\Db::i()->select( 'facet_key', 'gd_facet_settings', [ 'hidden=?', 1 ] ) as $k ) { $hiddenFacets[ (string) $k ] = TRUE; } } catch ( \Throwable ) {}
        }

        \IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'search', 'gdsearch', 'front' )->results(
            $query, $results, $total, $pagination, $filters, $sort, $aggs, $categories, $error, $grainBands, $velocityBands, $barrelBands, $hiddenFacets
        );
    }

    protected function product(): void
    {
        $upc = trim( (string) ( \IPS\Request::i()->upc ?? '' ) );

        if ( !preg_match( '/^[0-9]{8,14}$/', $upc ) ) {
            \IPS\Output::i()->error( 'node_error', '2GDS/1', 404 );
            return;
        }

        $product = [];
        try {
            $product = \IPS\Db::i()->select( '*', 'gd_catalog', [ 'upc=? AND record_status=?', $upc, 'active' ] )->first();
        } catch ( \Throwable ) {
            \IPS\Output::i()->error( 'node_error', '2GDS/2', 404 );
            return;
        }

        $offersSort = (string) ( \IPS\Request::i()->offers_sort ?? 'standard' );
        if ( !\in_array( $offersSort, [ 'standard', 'price', 'rating', 'shipping', 'total' ], true ) ) {
            $offersSort = 'standard';
        }

        $listings = [];
        try {
            $searcher = new \IPS\gdsearch\Search\Searcher();
            $listings = $searcher->getDealerListings( $upc, $offersSort );
        } catch ( \Throwable ) {}

        $categoryName = '';
        try {
            $cat = \IPS\Db::i()->select( 'name', 'gd_categories', [ 'id=?', (int) $product['category_id'] ] )->first();
            $categoryName = (string) $cat;
        } catch ( \Throwable ) {}

        /* Restriction rows keyed by state — baked in from server hotfix.
           Uses gdcompliance's clean 8-column gd_compliance_flags schema
           (no more flag_type/flag_value/status; one row per state).
           v1.6.17: excludes firearm_type='advisory' from the red
           "cannot ship" state list — advisories are buyer-permit
           requirements, not sale prohibitions. */
        $restrictedStates = [];
        try {
            foreach ( \IPS\Db::i()->select( 'state_code', 'gd_compliance_flags',
                [ 'upc=? AND firearm_type<>?', $upc, 'advisory' ] ) as $st )
            {
                $st = strtoupper( trim( (string) ( is_array( $st ) ? ( $st['state_code'] ?? '' ) : $st ) ) );
                if ( $st !== '' ) { $restrictedStates[ $st ] = TRUE; }
            }
        } catch ( \Throwable ) {}
        $restrictedStates = array_keys( $restrictedStates );
        sort( $restrictedStates );
        $restrictedStatesStr = implode( ', ', $restrictedStates );

        /* Enriched rows for the clickable-chip popup (state_name, reason,
           type, citation). Guarded: if gdcompliance is disabled or the
           helper throws, we get an empty array and the banner won't
           render — the storefront degrades gracefully.

           v1.6.17 — split the returned rows into two arrays. Advisories
           (type='advisory' — CO SB25-003, MN §624.712, etc.) get their
           own YELLOW block; they are NEVER shown in the red "cannot
           ship to:" banner. */
        $allRestrictionRows = [];
        try {
            if ( class_exists( '\\IPS\\gdcompliance\\Flag' ) )
            {
                $allRestrictionRows = \IPS\gdcompliance\Flag::forUpc( $upc );
            }
        } catch ( \Throwable ) { $allRestrictionRows = []; }

        $restrictionRows = [];
        $advisoryRows    = [];
        foreach ( $allRestrictionRows as $rr )
        {
            if ( ( $rr['type'] ?? '' ) === 'advisory' )
            {
                $advisoryRows[] = $rr;
            }
            else
            {
                $restrictionRows[] = $rr;
            }
        }

        $priceChartSvg   = '';
        $priceChartJson  = '[]';
        $priceAllTimeLow = null;

        $rawSeries = [];
        try { $rawSeries = \IPS\gddealer\Listing\PriceHistory::seriesFor( $upc, null, 365 ); } catch ( \Throwable ) {}
        try { $priceAllTimeLow = \IPS\gddealer\Listing\PriceHistory::allTimeLow( $upc ); } catch ( \Throwable ) {}

        $dayMin = [];
        foreach ( $rawSeries as $r )
        {
            $p = (float) $r['price'];
            if ( $p <= 0 ) { continue; }
            $d = substr( (string) $r['recorded_at'], 0, 10 );
            if ( !isset( $dayMin[ $d ] ) || $p < $dayMin[ $d ] ) { $dayMin[ $d ] = $p; }
        }
        ksort( $dayMin );

        $series = [];
        if ( $dayMin )
        {
            try {
                $start = new \DateTime( array_key_first( $dayMin ) );
                $end   = new \DateTime( date( 'Y-m-d' ) );
                $last  = null;
                for ( $d = clone $start; $d <= $end; $d->modify( '+1 day' ) )
                {
                    $k = $d->format( 'Y-m-d' );
                    if ( isset( $dayMin[ $k ] ) ) { $last = $dayMin[ $k ]; }
                    if ( $last !== null ) { $series[] = [ 'date' => $k, 'price' => $last ]; }
                }
            } catch ( \Throwable ) { $series = []; }
        }

        $priceChartSvg  = \IPS\gdsearch\Price\Chart::svg( $series );
        $priceChartJson = \IPS\gdsearch\Price\Chart::pointsJson( $series );

        try { \IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'pricechart.js', 'gdsearch', 'interface' ) ); } catch ( \Throwable ) {}
        try { \IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'pricealert.js', 'gdsearch', 'interface' ) ); } catch ( \Throwable ) {}
        try { \IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'wishlist.js', 'gdsearch', 'interface' ) ); } catch ( \Throwable ) {}
        try { \IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'compare.js', 'gdsearch', 'interface' ) ); } catch ( \Throwable ) {}
        try { \IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'restrictpopup.js', 'gdsearch', 'interface' ) ); } catch ( \Throwable ) {}

        $backUrl = (string) \IPS\Http\Url::internal(
            'app=gdsearch&module=search&controller=results',
            'front', 'gdsearch_results'
        );
        /* Prefer the search/listing the user came from (preserves q + filters). Only honor a
         * same-site referrer that isn't itself a product page. */
        try {
            $ref  = (string) ( $_SERVER['HTTP_REFERER'] ?? '' );
            $base = (string) \IPS\Settings::i()->base_url;
            if ( $ref !== '' && $base !== '' && str_starts_with( $ref, $base ) && stripos( $ref, 'do=product' ) === false ) {
                $backUrl = $ref;
            }
        } catch ( \Throwable ) {}

        $member = \IPS\Member::loggedIn();
        $alertLoggedIn = (bool) $member->member_id;
        $alertThreshold = null;
        $alertCurrent   = ( $product['total_min_price'] ?? null ) !== null ? (float) $product['total_min_price'] : null;

        if ( $alertLoggedIn )
        {
            try {
                $existing = \IPS\Db::i()->select( 'threshold', 'gd_price_alerts', [ 'member_id=? AND upc=?', (int) $member->member_id, $upc ] )->first();
                $alertThreshold = (float) $existing;
            } catch ( \Throwable ) {}
        }

        $alertSetUrl    = (string) \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results&do=setAlert', 'front' );
        $alertCancelUrl = (string) \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results&do=cancelAlert', 'front' );
        $alertCsrfKey   = \IPS\Session::i()->csrfKey;
        $alertLoginUrl  = (string) \IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front' );

        $wishLoggedIn = (bool) $member->member_id;
        $wishlisted   = false;
        if ( $wishLoggedIn )
        {
            try { $wishlisted = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'gd_wishlist', [ 'member_id=? AND upc=?', $member->member_id, $product['upc'] ] )->first(); } catch ( \Throwable ) {}
        }
        $wishAddUrl    = (string) \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results&do=addWishlist', 'front' );
        $wishRemoveUrl = (string) \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results&do=removeWishlist', 'front' );
        $wishLoginUrl  = (string) \IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front' );
        $wishCsrfKey   = \IPS\Session::i()->csrfKey;

        $reportLoggedIn = (bool) $member->member_id;
        $reportUrl      = (string) \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results&do=reportProduct', 'front' );
        $reportLoginUrl = (string) \IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front' );
        $reportCsrfKey  = \IPS\Session::i()->csrfKey;
        try { \IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'report.js', 'gdsearch', 'interface' ) ); } catch ( \Throwable ) {}

        $listingReportUrl      = (string) \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results&do=reportListing', 'front' );
        $listingReportLoggedIn = (bool) $member->member_id;
        $listingReportCsrfKey  = (string) \IPS\Session::i()->csrfKey;
        $listingReportLoginUrl = (string) \IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front' );
        try { \IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'reportlisting.js', 'gdsearch', 'interface' ) ); } catch ( \Throwable ) {}

        $sortOptions = [
            'standard' => 'Standard',
            'price'    => 'Cheapest price',
            'total'    => 'Cheapest total (item + shipping)',
            'rating'   => 'Best rated',
            'shipping' => 'Cheapest shipping',
        ];
        $sortBaseUrl = (string) \IPS\Http\Url::internal(
            'app=gdsearch&module=search&controller=results&do=product&upc=' . urlencode( $upc ),
            'front'
        );

        \IPS\Output::i()->title = (string) ( $product['title'] ?? $upc );
        \IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'search', 'gdsearch', 'front' )->product(
            $product, $listings, $categoryName, $backUrl, $restrictedStatesStr, $priceChartSvg, $priceChartJson, $priceAllTimeLow,
            $alertLoggedIn, $alertThreshold, $alertSetUrl, $alertCancelUrl, $alertCsrfKey, $alertCurrent, $alertLoginUrl,
            $wishLoggedIn, $wishlisted, $wishAddUrl, $wishRemoveUrl, $wishLoginUrl, $wishCsrfKey,
            $reportLoggedIn, $reportUrl, $reportLoginUrl, $reportCsrfKey,
            $listingReportUrl, $listingReportLoggedIn, $listingReportCsrfKey, $listingReportLoginUrl,
            $offersSort, $sortOptions, $sortBaseUrl, $restrictionRows, $advisoryRows
        );
    }

    protected function reportProduct(): void
    {
        $member = \IPS\Member::loggedIn();
        if ( !$member->member_id ) { \IPS\Output::i()->json( [ 'ok'=>false, 'error'=>'login' ] ); }
        \IPS\Session::i()->csrfCheck();
        $upc     = (string) \IPS\Request::i()->upc;
        $reason  = ( \IPS\Request::i()->reason === 'content' ) ? 'content' : 'data';
        $details = mb_substr( trim( (string) \IPS\Request::i()->details ), 0, 1000 );
        if ( $upc === '' ) { \IPS\Output::i()->json( [ 'ok'=>false ] ); }
        try { $dupe = \IPS\Db::i()->select( 'COUNT(*)', 'gd_product_reports', [ 'upc=? AND member_id=? AND status=?', $upc, $member->member_id, 'open' ] )->first(); }
        catch ( \Throwable ) { $dupe = 0; }
        if ( !$dupe )
        {
            try {
                \IPS\Db::i()->insert( 'gd_product_reports', [
                    'upc'=>$upc, 'member_id'=>(int)$member->member_id, 'reason'=>$reason, 'details'=>$details,
                    'status'=>'open', 'admin_note'=>'', 'created'=>time(), 'handled_by'=>0, 'handled_at'=>0,
                ] );
            } catch ( \Throwable ) {}
        }
        \IPS\Output::i()->json( [ 'ok'=>true ] );
    }

    protected function setAlert(): void
    {
        \IPS\Session::i()->csrfCheck();

        $member = \IPS\Member::loggedIn();
        if ( !$member->member_id )
        {
            \IPS\Output::i()->json( [ 'error' => 'not_logged_in' ], 403 );
            return;
        }

        $upc       = trim( (string) ( \IPS\Request::i()->upc ?? '' ) );
        $threshold = (float) ( \IPS\Request::i()->threshold ?? 0 );

        if ( !preg_match( '/^[0-9]{8,14}$/', $upc ) || $threshold <= 0 )
        {
            \IPS\Output::i()->json( [ 'error' => 'invalid_input' ], 400 );
            return;
        }

        try
        {
            \IPS\Db::i()->replace( 'gd_price_alerts', [
                'member_id' => (int) $member->member_id,
                'upc'       => $upc,
                'threshold' => $threshold,
                'created'   => time(),
            ] );
        }
        catch ( \Throwable $e )
        {
            \IPS\Output::i()->json( [ 'error' => 'db_error' ], 500 );
            return;
        }

        \IPS\Output::i()->json( [ 'ok' => true, 'threshold' => number_format( $threshold, 2 ) ] );
    }

    protected function cancelAlert(): void
    {
        \IPS\Session::i()->csrfCheck();

        $member = \IPS\Member::loggedIn();
        if ( !$member->member_id )
        {
            \IPS\Output::i()->json( [ 'error' => 'not_logged_in' ], 403 );
            return;
        }

        $upc = trim( (string) ( \IPS\Request::i()->upc ?? '' ) );

        if ( !preg_match( '/^[0-9]{8,14}$/', $upc ) )
        {
            \IPS\Output::i()->json( [ 'error' => 'invalid_input' ], 400 );
            return;
        }

        try
        {
            \IPS\Db::i()->delete( 'gd_price_alerts', [ 'member_id=? AND upc=?', (int) $member->member_id, $upc ] );
        }
        catch ( \Throwable ) {}

        \IPS\Output::i()->json( [ 'ok' => true ] );
    }

    protected function myAlerts(): void
    {
        $member = \IPS\Member::loggedIn();
        if ( !$member->member_id )
        {
            \IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front' ) );
            return;
        }

        $alerts = [];
        try
        {
            foreach ( \IPS\Db::i()->select( '*', 'gd_price_alerts', [ 'member_id=?', (int) $member->member_id ], 'created DESC' ) as $row )
            {
                $title = '';
                $currentPrice = null;
                $imageUrl = '';
                try {
                    $prod = \IPS\Db::i()->select( 'title, total_min_price, image_url', 'gd_catalog', [ 'upc=?', $row['upc'] ] )->first();
                    $title = (string) $prod['title'];
                    $currentPrice = (float) $prod['total_min_price'];
                    $imageUrl = (string) ( $prod['image_url'] ?? '' );
                } catch ( \Throwable ) {}

                $productUrl = (string) \IPS\Http\Url::internal(
                    'app=gdsearch&module=search&controller=results&do=product&upc=' . $row['upc'],
                    'front', 'gdsearch_product'
                );

                $cancelUrl = (string) \IPS\Http\Url::internal(
                    'app=gdsearch&module=search&controller=results&do=cancelAlert',
                    'front'
                );

                $alerts[] = [
                    'id'           => (int) $row['id'],
                    'upc'          => (string) $row['upc'],
                    'title'        => $title,
                    'threshold'    => (float) $row['threshold'],
                    'currentPrice' => $currentPrice,
                    'imageUrl'     => $imageUrl,
                    'productUrl'   => $productUrl,
                    'cancelUrl'    => $cancelUrl,
                    'created'      => (int) $row['created'],
                ];
            }
        }
        catch ( \Throwable ) {}

        $csrfKey = \IPS\Session::i()->csrfKey;

        try { \IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'pricealert.js', 'gdsearch', 'interface' ) ); } catch ( \Throwable ) {}

        \IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_my_alerts_title' );
        \IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'search', 'gdsearch', 'front' )->myAlerts(
            $alerts, $csrfKey
        );
    }
    protected function compare(): void
    {
        $raw  = array_filter( array_map( 'trim', explode( ',', (string) \IPS\Request::i()->upcs ) ) );
        $raw  = array_slice( array_unique( $raw ), 0, 4 );

        $products = [];
        $minPrice = null;
        foreach ( $raw as $upc )
        {
            try { $cat = \IPS\Db::i()->select( '*', 'gd_catalog', [ 'upc=?', $upc ] )->first(); }
            catch ( \Throwable ) { continue; }

            $price = isset( $cat['total_min_price'] ) && $cat['total_min_price'] !== null ? (float) $cat['total_min_price'] : null;
            if ( $price !== null && ( $minPrice === null || $price < $minPrice ) ) { $minPrice = $price; }

            $products[] = [
                'upc'    => $upc,
                'title'  => $cat['title'] ?? $upc,
                'image'  => $cat['image_url'] ?? '',
                'url'    => (string) \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results&do=product&upc=' . urlencode( $upc ), 'front' ),
                'price'  => $price,
                'isBest' => false,
                'specs'  => [
                    'brand'          => $cat['brand'] ?? '',
                    'caliber'        => $cat['caliber'] ?? '',
                    'action_type'    => $cat['action_type'] ?? '',
                    'capacity'       => $cat['capacity'] ?? '',
                    'barrel_length'  => $cat['barrel_length'] ?? '',
                    'overall_length' => $cat['overall_length'] ?? '',
                    'weight'         => $cat['weight_lbs'] ?? '',
                    'msrp'           => isset( $cat['msrp'] ) && $cat['msrp'] ? '$' . number_format( (float) $cat['msrp'], 2 ) : '',
                    'mpn'            => $cat['mpn'] ?? '',
                ],
            ];
        }

        if ( $minPrice !== null )
        {
            foreach ( $products as &$p )
            {
                if ( $p['price'] !== null && (float) $p['price'] === (float) $minPrice ) { $p['isBest'] = true; break; }
            }
            unset( $p );
        }

        $rowDefs = [
            [ 'label' => 'Lowest Price',    'key' => 'price' ],
            [ 'label' => 'Brand',           'key' => 'brand' ],
            [ 'label' => 'MPN',             'key' => 'mpn' ],
            [ 'label' => 'Caliber',         'key' => 'caliber' ],
            [ 'label' => 'Action',          'key' => 'action_type' ],
            [ 'label' => 'Capacity',        'key' => 'capacity' ],
            [ 'label' => 'Barrel Length',   'key' => 'barrel_length' ],
            [ 'label' => 'Overall Length',  'key' => 'overall_length' ],
            [ 'label' => 'Weight',          'key' => 'weight' ],
            [ 'label' => 'MSRP',            'key' => 'msrp' ],
        ];

        $rows = array_values( array_filter( $rowDefs, function ( $r ) use ( $products ) {
            if ( $r['key'] === 'price' ) { return true; }
            foreach ( $products as $p ) { if ( !empty( $p['specs'][ $r['key'] ] ) ) { return true; } }
            return false;
        } ) );

        try { \IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'compare.js', 'gdsearch', 'interface' ) ); } catch ( \Throwable ) {}
        \IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_compare_title' );
        \IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'search', 'gdsearch', 'front' )->compare( $products, $rows );
    }

    protected function addWishlist(): void
    {
        $member = \IPS\Member::loggedIn();
        if ( !$member->member_id )
        {
            \IPS\Output::i()->json( [ 'ok' => false, 'error' => 'login' ] );
            return;
        }

        \IPS\Session::i()->csrfCheck();

        $upc = (string) \IPS\Request::i()->upc;

        try
        {
            \IPS\Db::i()->insert( 'gd_wishlist', [
                'member_id' => (int) $member->member_id,
                'upc'       => $upc,
                'created'   => time(),
            ] );
        }
        catch ( \IPS\Db\Exception $e ) {}

        \IPS\Output::i()->json( [ 'ok' => true ] );
    }

    protected function removeWishlist(): void
    {
        $member = \IPS\Member::loggedIn();
        if ( !$member->member_id )
        {
            \IPS\Output::i()->json( [ 'ok' => false, 'error' => 'login' ] );
            return;
        }

        \IPS\Session::i()->csrfCheck();

        \IPS\Db::i()->delete( 'gd_wishlist', [ 'member_id=? AND upc=?', (int) $member->member_id, (string) \IPS\Request::i()->upc ] );

        \IPS\Output::i()->json( [ 'ok' => true ] );
    }

    protected function myWishlist(): void
    {
        $member = \IPS\Member::loggedIn();
        if ( !$member->member_id )
        {
            \IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=core&module=system&controller=login', 'front' ) );
            return;
        }

        $rows = [];
        try
        {
            foreach ( \IPS\Db::i()->select( 'upc, created', 'gd_wishlist', [ 'member_id=?', (int) $member->member_id ], 'created DESC' ) as $w )
            {
                $cat = null;
                try { $cat = \IPS\Db::i()->select( 'title, total_min_price, image_url', 'gd_catalog', [ 'upc=?', $w['upc'] ] )->first(); } catch ( \Throwable ) {}
                $productUrl = (string) \IPS\Http\Url::internal(
                    'app=gdsearch&module=search&controller=results&do=product&upc=' . $w['upc'],
                    'front', 'gdsearch_product'
                );
                $rows[] = [
                    'upc'        => $w['upc'],
                    'title'      => $cat['title'] ?? $w['upc'],
                    'price'      => $cat['total_min_price'] ?? null,
                    'image'      => (string) ( $cat['image_url'] ?? '' ),
                    'productUrl' => $productUrl,
                ];
            }
        }
        catch ( \Throwable ) {}

        $removeUrl = (string) \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results&do=removeWishlist', 'front' );
        $csrfKey   = \IPS\Session::i()->csrfKey;

        try { \IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js( 'wishlist.js', 'gdsearch', 'interface' ) ); } catch ( \Throwable ) {}

        \IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_my_wishlist_title' );
        \IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'search', 'gdsearch', 'front' )->myWishlist(
            $rows, $removeUrl, $csrfKey
        );
    }

    protected function reportListing(): void
    {
        $member = \IPS\Member::loggedIn();
        if ( !$member->member_id )
        {
            \IPS\Output::i()->json( [ 'ok' => false, 'error' => 'login' ] );
            return;
        }

        \IPS\Session::i()->csrfCheck();

        $upc      = trim( (string) ( \IPS\Request::i()->upc ?? '' ) );
        $dealerId = (int) ( \IPS\Request::i()->dealer_id ?? 0 );
        $reason   = (string) ( \IPS\Request::i()->reason ?? '' );
        $note     = mb_substr( trim( (string) ( \IPS\Request::i()->note ?? '' ) ), 0, 1000 );

        $valid = [ 'wrong_price', 'dead_link', 'false_in_stock', 'wrong_product', 'other' ];

        if ( $upc === '' || $dealerId <= 0 || !in_array( $reason, $valid, true ) )
        {
            \IPS\Output::i()->json( [ 'ok' => false ] );
            return;
        }

        try
        {
            \IPS\gddealer\Reports\ListingReport::fileListingReport(
                $dealerId, $upc, (int) $member->member_id, $reason, $note
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( $e, 'gdsearch_report_listing' ); } catch ( \Throwable ) {}
        }

        \IPS\Output::i()->json( [ 'ok' => true ] );
    }
}
class results extends _results {}
