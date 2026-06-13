<?php
namespace IPS\gddealer\modules\admin\dealers;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class _flaggedUpcs extends \IPS\Dispatcher\Controller
{
    public static function canManage(): bool
    {
        return \IPS\Member::loggedIn()->hasAcpRestriction( 'gddealer', 'dealers', 'gddealer_dealer_manage' );
    }

    protected function manage(): void
    {
        \IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

        $statusFilter = (string) ( \IPS\Request::i()->flag_status ?? 'submitted' );
        $validStatuses = [ 'new', 'submitted', 'resolved', 'dismissed', 'all' ];
        if ( !in_array( $statusFilter, $validStatuses, true ) ) {
            $statusFilter = 'submitted';
        }

        $dealerFilter = (int) ( \IPS\Request::i()->dealer_id ?? 0 );
        $typeFilter   = (string) ( \IPS\Request::i()->flag_type ?? '' );
        $validTypes   = [ 'mpn', 'title', 'brand', '' ];
        if ( !in_array( $typeFilter, $validTypes, true ) ) {
            $typeFilter = '';
        }

        $page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
        $perPage = 50;
        $offset  = ( $page - 1 ) * $perPage;

        $where = [];
        if ( $statusFilter !== 'all' ) {
            $where[] = [ 'status=?', $statusFilter ];
        }
        if ( $dealerFilter > 0 ) {
            $where[] = [ 'dealer_id=?', $dealerFilter ];
        }
        if ( $typeFilter !== '' ) {
            $where[] = [ 'flag_type=?', $typeFilter ];
        }

        $total = 0;
        $flags = [];

        try {
            $total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_data_flags', $where )->first();
        } catch ( \Throwable ) {}

        try {
            foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_data_flags', $where, 'updated_at DESC', [ $offset, $perPage ] ) as $row ) {
                $dealerName = '';
                try {
                    $d = \IPS\Db::i()->select( 'dealer_name', 'gd_dealer_feed_config', [ 'dealer_id=?', (int) $row['dealer_id'] ] )->first();
                    $dealerName = (string) $d;
                } catch ( \Throwable ) {}

                $catalogTitle = '';
                try {
                    $p = \IPS\Db::i()->select( 'title', 'gd_catalog', [ 'upc=?', $row['upc'] ] )->first();
                    $catalogTitle = (string) $p;
                } catch ( \Throwable ) {}

                $detailUrl = (string) \IPS\Http\Url::internal(
                    'app=gddealer&module=dealers&controller=flaggedUpcs&do=detail&flag_id=' . (int) $row['id']
                );

                $flags[] = [
                    'id'            => (int) $row['id'],
                    'dealer_id'     => (int) $row['dealer_id'],
                    'dealer_name'   => $dealerName,
                    'upc'           => (string) $row['upc'],
                    'catalog_title' => $catalogTitle,
                    'flag_type'     => (string) $row['flag_type'],
                    'dealer_value'  => (string) $row['dealer_value'],
                    'catalog_value' => (string) $row['catalog_value'],
                    'status'        => (string) $row['status'],
                    'submitted_at'  => (string) ( $row['submitted_at'] ?? '' ),
                    'updated_at'    => (string) $row['updated_at'],
                    'admin_note'    => (string) ( $row['admin_note'] ?? '' ),
                    'detail_url'    => $detailUrl,
                ];
            }
        } catch ( \Throwable ) {}

        $pagination = '';
        if ( $total > $perPage ) {
            $pagination = (string) \IPS\Theme::i()->getTemplate( 'global', 'core' )->pagination(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=flaggedUpcs' ),
                (int) ceil( $total / $perPage ),
                $page,
                $perPage
            );
        }

        $counts = [ 'new' => 0, 'submitted' => 0, 'resolved' => 0, 'dismissed' => 0 ];
        try {
            foreach ( \IPS\Db::i()->select( 'status, COUNT(*) as cnt', 'gd_dealer_data_flags', [], null, null, 'status' ) as $r ) {
                if ( isset( $counts[ $r['status'] ] ) ) {
                    $counts[ $r['status'] ] = (int) $r['cnt'];
                }
            }
        } catch ( \Throwable ) {}

        $reEvalUrl = (string) \IPS\Http\Url::internal(
            'app=gddealer&module=dealers&controller=flaggedUpcs&do=reEvaluate'
        )->csrf();
        $clearAllUrl = (string) \IPS\Http\Url::internal(
            'app=gddealer&module=dealers&controller=flaggedUpcs&do=clearAll'
        )->csrf();

        \IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_flagged_upcs_title' );
        \IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->flaggedUpcs(
            $flags, $total, $pagination, $statusFilter, $dealerFilter, $typeFilter, $counts, $reEvalUrl, $clearAllUrl
        );
    }

    protected function detail(): void
    {
        \IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

        $flagId = (int) \IPS\Request::i()->flag_id;

        try {
            $flag = \IPS\Db::i()->select( '*', 'gd_dealer_data_flags', [ 'id=?', $flagId ] )->first();
        } catch ( \Throwable ) {
            \IPS\Output::i()->error( 'node_error', '2GDD/1', 404 );
            return;
        }

        $dealerName = '';
        try {
            $d = \IPS\Db::i()->select( 'dealer_name', 'gd_dealer_feed_config', [ 'dealer_id=?', (int) $flag['dealer_id'] ] )->first();
            $dealerName = (string) $d;
        } catch ( \Throwable ) {}

        $product = [];
        try {
            $p = \IPS\Db::i()->select( 'upc, title, brand, mpn, category_id', 'gd_catalog', [ 'upc=?', $flag['upc'] ] )->first();
            $product = $p;
        } catch ( \Throwable ) {}

        $resolveUrl = (string) \IPS\Http\Url::internal(
            'app=gddealer&module=dealers&controller=flaggedUpcs&do=resolve&flag_id=' . $flagId
        )->csrf();
        $dismissUrl = (string) \IPS\Http\Url::internal(
            'app=gddealer&module=dealers&controller=flaggedUpcs&do=dismiss&flag_id=' . $flagId
        )->csrf();
        $noteUrl = (string) \IPS\Http\Url::internal(
            'app=gddealer&module=dealers&controller=flaggedUpcs&do=saveNote&flag_id=' . $flagId
        )->csrf();
        $backUrl = (string) \IPS\Http\Url::internal(
            'app=gddealer&module=dealers&controller=flaggedUpcs'
        );

        \IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_flagged_upc_detail_title' );
        \IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->flaggedUpcDetail(
            $flag, $dealerName, $product, $resolveUrl, $dismissUrl, $noteUrl, $backUrl
        );
    }

    protected function resolve(): void
    {
        \IPS\Session::i()->csrfCheck();
        \IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

        $flagId  = (int) \IPS\Request::i()->flag_id;
        $note    = trim( (string) ( \IPS\Request::i()->admin_note ?? '' ) );
        $update  = trim( (string) ( \IPS\Request::i()->update_catalog ?? '' ) );
        $now     = date( 'Y-m-d H:i:s' );

        try {
            $flag = \IPS\Db::i()->select( '*', 'gd_dealer_data_flags', [ 'id=?', $flagId ] )->first();

            if ( $update === '1' && !empty( $flag['dealer_value'] ) ) {
                $col = $flag['flag_type'];
                $validCols = [ 'mpn', 'title', 'brand' ];
                if ( in_array( $col, $validCols, true ) ) {
                    try {
                        \IPS\Db::i()->update( 'gd_catalog', [ $col => $flag['dealer_value'] ], [ 'upc=?', $flag['upc'] ] );
                    } catch ( \Throwable ) {}
                }
            }

            \IPS\Db::i()->update( 'gd_dealer_data_flags', [
                'status'      => 'resolved',
                'admin_note'  => $note,
                'resolved_at' => $now,
                'updated_at'  => $now,
            ], [ 'id=?', $flagId ] );

        } catch ( \Throwable ) {}

        \IPS\Output::i()->redirect(
            \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=flaggedUpcs' ),
            'gddealer_flag_resolved'
        );
    }

    protected function dismiss(): void
    {
        \IPS\Session::i()->csrfCheck();
        \IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

        $flagId = (int) \IPS\Request::i()->flag_id;
        $note   = trim( (string) ( \IPS\Request::i()->admin_note ?? '' ) );
        $now    = date( 'Y-m-d H:i:s' );

        try {
            \IPS\Db::i()->update( 'gd_dealer_data_flags', [
                'status'      => 'dismissed',
                'admin_note'  => $note,
                'resolved_at' => $now,
                'updated_at'  => $now,
            ], [ 'id=?', $flagId ] );
        } catch ( \Throwable ) {}

        \IPS\Output::i()->redirect(
            \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=flaggedUpcs' ),
            'gddealer_flag_dismissed_acp'
        );
    }

    protected function saveNote(): void
    {
        \IPS\Session::i()->csrfCheck();
        \IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

        $flagId = (int) \IPS\Request::i()->flag_id;
        $note   = trim( (string) ( \IPS\Request::i()->admin_note ?? '' ) );

        try {
            \IPS\Db::i()->update( 'gd_dealer_data_flags', [
                'admin_note' => $note,
                'updated_at' => date( 'Y-m-d H:i:s' ),
            ], [ 'id=?', $flagId ] );
        } catch ( \Throwable ) {}

        \IPS\Output::i()->redirect(
            \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=flaggedUpcs&do=detail&flag_id=' . $flagId ),
            'gddealer_flag_note_saved'
        );
    }

    protected function reEvaluate(): void
    {
        \IPS\Session::i()->csrfCheck();
        \IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

        $resolved = 0;
        $now = date( 'Y-m-d H:i:s' );

        try {
            foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_data_flags', [ 'status IN(?,?)', 'new', 'submitted' ] ) as $row ) {
                try {
                    $field      = (string) $row['flag_type'];
                    $dealerVal  = trim( (string) $row['dealer_value'] );
                    $catalogVal = trim( (string) $row['catalog_value'] );

                    if ( $dealerVal === '' || $catalogVal === '' || !\IPS\gddealer\Feed\DataFlagChecker::isMismatch( $field, $dealerVal, $catalogVal ) ) {
                        \IPS\Db::i()->update( 'gd_dealer_data_flags', [
                            'status'      => 'resolved',
                            'admin_note'  => 'Auto-resolved by re-evaluation',
                            'resolved_at' => $now,
                            'updated_at'  => $now,
                        ], [ 'id=?', (int) $row['id'] ] );
                        $resolved++;
                    }
                } catch ( \Throwable ) {}
            }
        } catch ( \Throwable ) {}

        \IPS\Output::i()->redirect(
            \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=flaggedUpcs' ),
            'gddealer_flags_reevaluated'
        );
    }

    protected function clearAll(): void
    {
        \IPS\Session::i()->csrfCheck();
        \IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

        try {
            \IPS\Db::i()->delete( 'gd_dealer_data_flags' );
        } catch ( \Throwable ) {}

        \IPS\Output::i()->redirect(
            \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=flaggedUpcs' ),
            'gddealer_flags_cleared'
        );
    }
}

class flaggedUpcs extends _flaggedUpcs {}
