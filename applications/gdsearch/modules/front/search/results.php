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

        $filters = [
            'category'     => $categoryName,
            'category_id'  => $categoryId,
            'brand'        => trim( (string) ( \IPS\Request::i()->brand ?? '' ) ),
            'caliber'      => trim( (string) ( \IPS\Request::i()->caliber ?? '' ) ),
            'in_stock'     => !empty( \IPS\Request::i()->in_stock ),
            'requires_ffl' => !empty( \IPS\Request::i()->requires_ffl ),
            'min_price'    => (float) ( \IPS\Request::i()->min_price ?? 0 ),
            'max_price'    => (float) ( \IPS\Request::i()->max_price ?? 0 ),
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
        } catch ( \Throwable $e ) {
            $error = $e->getMessage();
        }

        $pagination = '';
        if ( $total > $perPage ) {
            $paginationQs = 'app=gdsearch&module=search&controller=results';
            if ( $query !== '' )                  { $paginationQs .= '&q=' . urlencode( $query ); }
            if ( $filters['category_id'] > 0 )   { $paginationQs .= '&category=' . $filters['category_id']; }
            if ( $filters['brand'] !== '' )       { $paginationQs .= '&brand=' . urlencode( $filters['brand'] ); }
            if ( $filters['caliber'] !== '' )     { $paginationQs .= '&caliber=' . urlencode( $filters['caliber'] ); }
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

        \IPS\Output::i()->title = $query
            ? $query . ' — ' . \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_results_title' )
            : \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_results_title' );

        \IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'search', 'gdsearch', 'front' )->results(
            $query, $results, $total, $pagination, $filters, $sort, $aggs, $categories, $error
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

        $listings = [];
        try {
            $searcher = new \IPS\gdsearch\Search\Searcher();
            $listings = $searcher->getDealerListings( $upc );
        } catch ( \Throwable ) {}

        $categoryName = '';
        try {
            $cat = \IPS\Db::i()->select( 'name', 'gd_categories', [ 'id=?', (int) $product['category_id'] ] )->first();
            $categoryName = (string) $cat;
        } catch ( \Throwable ) {}

        $restrictedStates = [];
        try {
            foreach ( \IPS\Db::i()->select( 'flag_value', 'gd_compliance_flags',
                [ "upc=? AND flag_type='state_restriction' AND status='active'", $upc ] ) as $val )
            {
                foreach ( explode( ',', (string) $val ) as $st ) {
                    $st = strtoupper( trim( $st ) );
                    if ( $st !== '' ) { $restrictedStates[ $st ] = TRUE; }
                }
            }
        } catch ( \Throwable ) {}
        $restrictedStates = array_keys( $restrictedStates );
        sort( $restrictedStates );
        $restrictedStatesStr = implode( ', ', $restrictedStates );

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

        try { \IPS\Output::i()->js( 'pricechart.js', 'gdsearch', 'interface' ); } catch ( \Throwable ) {}
        try { \IPS\Output::i()->js( 'pricealert.js', 'gdsearch', 'interface' ); } catch ( \Throwable ) {}

        $backUrl = (string) \IPS\Http\Url::internal(
            'app=gdsearch&module=search&controller=results',
            'front', 'gdsearch_results'
        );

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

        \IPS\Output::i()->title = (string) ( $product['title'] ?? $upc );
        \IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'search', 'gdsearch', 'front' )->product(
            $product, $listings, $categoryName, $backUrl, $restrictedStatesStr, $priceChartSvg, $priceChartJson, $priceAllTimeLow,
            $alertLoggedIn, $alertThreshold, $alertSetUrl, $alertCancelUrl, $alertCsrfKey, $alertCurrent, $alertLoginUrl
        );
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

        try { \IPS\Output::i()->js( 'pricealert.js', 'gdsearch', 'interface' ); } catch ( \Throwable ) {}

        \IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_my_alerts_title' );
        \IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'search', 'gdsearch', 'front' )->myAlerts(
            $alerts, $csrfKey
        );
    }
}
class results extends _results {}
