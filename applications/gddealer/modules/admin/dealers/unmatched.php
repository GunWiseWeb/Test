<?php
/**
 * @brief       GD Dealer Manager — ACP Unmatched UPCs Controller
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 *
 * Cross-dealer unmatched UPCs. Sortable by occurrence count. "Add to Catalog"
 * creates a minimal gd_catalog stub and clears the unmatched row.
 */

namespace IPS\gddealer\modules\admin\dealers;

use IPS\gddealer\Unmatched\UnmatchedUpc;
use IPS\gddealer\Dealer\Dealer;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _unmatched extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'dealer_manage' );
		parent::execute();
	}

	protected function manage()
	{
		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = 50;
		$offset  = ( $page - 1 ) * $perPage;

		$rawRows = UnmatchedUpc::loadAll( $offset, $perPage );

		/* Build a dealer-id -> name lookup so we can display dealer names */
		$dealerNames = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'dealer_id, dealer_name', 'gd_dealer_feed_config' ) as $r )
			{
				$dealerNames[ (int) $r['dealer_id'] ] = (string) $r['dealer_name'];
			}
		}
		catch ( \Exception ) {}

		$rows = [];
		foreach ( $rawRows as $r )
		{
			$excludeUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=unmatched&do=exclude&id=' . (int) $r['id']
			)->csrf();
			$addUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=unmatched&do=addToCatalog&id=' . (int) $r['id']
			)->csrf();

			$reviewUrl = (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=unmatched&do=review&upc_id=' . (int) $r['id']
			);

			$rows[] = [
				'id'               => (int) $r['id'],
				'upc'              => (string) $r['upc'],
				'dealer_name'      => $dealerNames[ (int) $r['dealer_id'] ] ?? ( 'Dealer #' . (int) $r['dealer_id'] ),
				'first_seen'       => (string) $r['first_seen'],
				'last_seen'        => (string) $r['last_seen'],
				'occurrence_count' => (int) $r['occurrence_count'],
				'exclude_url'      => $excludeUrl,
				'add_url'          => $addUrl,
				'review_url'       => $reviewUrl,
			];
		}

		$total = 0;
		try { $total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_unmatched_upcs', [ 'admin_excluded=?', 0 ] )->first(); } catch ( \Exception ) {}

		$pagination = \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' ),
			(int) ceil( max( 1, $total ) / $perPage ),
			$page,
			$perPage
		);

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_unmatched_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->unmatchedList(
			$rows, $total, $pagination
		);
	}

	protected function exclude()
	{
		\IPS\Session::i()->csrfCheck();
		UnmatchedUpc::exclude( (int) \IPS\Request::i()->id );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' ),
			'UPC excluded from queue'
		);
	}

	protected function review(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

		$id = (int) \IPS\Request::i()->upc_id;

		try {
			$row = \IPS\Db::i()->select( '*', 'gd_unmatched_upcs', [ 'id=?', $id ] )->first();
		} catch ( \Throwable ) {
			\IPS\Output::i()->error( 'node_error', '2GDD/2', 404 );
			return;
		}

		$snapshot = [];
		if ( !empty( $row['snapshot_json'] ) ) {
			try { $snapshot = json_decode( (string) $row['snapshot_json'], true ) ?: []; } catch ( \Throwable ) {}
		}

		$dealerName = '';
		try {
			$d = \IPS\Db::i()->select( 'dealer_name', 'gd_dealer_feed_config', [ 'dealer_id=?', (int) $row['dealer_id'] ] )->first();
			$dealerName = (string) $d;
		} catch ( \Throwable ) {}

		$categories = [];
		try {
			foreach ( \IPS\Db::i()->select( 'id, name, parent_id', 'gd_categories', [], 'name ASC' ) as $cat ) {
				$categories[ (int) $cat['id'] ] = (string) $cat['name'];
			}
		} catch ( \Throwable ) {}

		$submitUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=unmatched&do=addToCatalog&upc_id=' . $id
		)->csrf();
		$backUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=unmatched'
		);

		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'gddealer_unmatched_review_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'admin' )->unmatchedUpcReview(
			$row, $snapshot, $dealerName, $categories, $submitUrl, $backUrl
		);
	}

	protected function addToCatalog(): void
	{
		\IPS\Session::i()->csrfCheck();
		\IPS\Dispatcher::i()->checkAcpPermission( 'gddealer_dealer_manage' );

		$id  = (int) ( \IPS\Request::i()->upc_id ?? \IPS\Request::i()->id ?? 0 );
		$now = date( 'Y-m-d H:i:s' );

		try {
			$row = \IPS\Db::i()->select( '*', 'gd_unmatched_upcs', [ 'id=?', $id ] )->first();
		} catch ( \Throwable ) {
			\IPS\Output::i()->error( 'node_error', '2GDD/3', 404 );
			return;
		}

		$upc = (string) $row['upc'];

		$exists = false;
		try {
			\IPS\Db::i()->select( 'upc', 'gd_catalog', [ 'upc=?', $upc ] )->first();
			$exists = true;
		} catch ( \Throwable ) {}

		if ( $exists ) {
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' ),
				'gddealer_unmatched_already_exists'
			);
			return;
		}

		$data = [
			'upc'            => $upc,
			'title'          => trim( (string) ( \IPS\Request::i()->title ?? '' ) ),
			'brand'          => trim( (string) ( \IPS\Request::i()->brand ?? '' ) ),
			'model'          => trim( (string) ( \IPS\Request::i()->model ?? '' ) ),
			'mpn'            => trim( (string) ( \IPS\Request::i()->mpn ?? '' ) ),
			'category_id'    => (int) ( \IPS\Request::i()->category_id ?? 0 ),
			'caliber'        => trim( (string) ( \IPS\Request::i()->caliber ?? '' ) ) ?: null,
			'action_type'    => trim( (string) ( \IPS\Request::i()->action_type ?? '' ) ) ?: null,
			'capacity'       => trim( (string) ( \IPS\Request::i()->capacity ?? '' ) ) ?: null,
			'barrel_length'  => trim( (string) ( \IPS\Request::i()->barrel_length ?? '' ) ) ?: null,
			'overall_length' => trim( (string) ( \IPS\Request::i()->overall_length ?? '' ) ) ?: null,
			'weight_lbs'     => trim( (string) ( \IPS\Request::i()->weight_lbs ?? '' ) ) ?: null,
			'msrp'           => (float) ( \IPS\Request::i()->msrp ?? 0 ) ?: null,
			'description'    => trim( (string) ( \IPS\Request::i()->description ?? '' ) ) ?: null,
			'image_url'      => trim( (string) ( \IPS\Request::i()->image_url ?? '' ) ) ?: null,
			'requires_ffl'   => (int) ( \IPS\Request::i()->requires_ffl ?? 0 ),
			'nfa_item'       => (int) ( \IPS\Request::i()->nfa_item ?? 0 ),
			'is_ammo'        => (int) ( \IPS\Request::i()->is_ammo ?? 0 ),
			'record_status'  => 'active',
			'primary_source' => 'admin',
			'created_at'     => $now,
			'updated_at'     => $now,
		];

		$data = array_filter( $data, fn($v) => $v !== null && $v !== '' );
		$data['upc'] = $upc;

		try {
			\IPS\Db::i()->insert( 'gd_catalog', $data );
		} catch ( \Throwable $e ) {
			\IPS\Output::i()->error( $e->getMessage(), '2GDD/4', 500 );
			return;
		}

		try {
			\IPS\Db::i()->update( 'gd_unmatched_upcs', [
				'status'    => 'added_to_catalog',
				'last_seen' => $now,
			], [ 'id=?', $id ] );
		} catch ( \Throwable ) {}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=unmatched' ),
			'gddealer_unmatched_added_to_catalog'
		);
	}
}

class unmatched extends _unmatched {}
