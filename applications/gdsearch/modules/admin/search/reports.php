<?php
namespace IPS\gdsearch\modules\admin\search;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

class _reports extends \IPS\Dispatcher\Controller
{
	public static function canManage(): bool
	{
		return \IPS\Member::loggedIn()->hasAcpRestriction( 'gdsearch', 'search', 'reports_manage' );
	}

	protected function manage(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'reports_manage' );

		$status = \IPS\Request::i()->status ?: 'open';
		if ( !in_array( $status, [ 'open', 'resolved', 'dismissed' ], true ) ) { $status = 'open'; }

		$counts = [ 'open' => 0, 'resolved' => 0, 'dismissed' => 0 ];
		try {
			foreach ( \IPS\Db::i()->select( 'status, COUNT(*) AS c', 'gd_product_reports', NULL, NULL, NULL, 'status' ) as $r ) {
				if ( isset( $counts[ $r['status'] ] ) ) { $counts[ $r['status'] ] = (int) $r['c']; }
			}
		} catch ( \Throwable ) {}

		$rows = [];
		try {
			foreach ( \IPS\Db::i()->select( '*', 'gd_product_reports', [ 'status=?', $status ], 'created DESC', [ 0, 200 ] ) as $row ) {
				$title = $row['upc'];
				try { $title = (string) \IPS\Db::i()->select( 'title', 'gd_catalog', [ 'upc=?', $row['upc'] ] )->first(); } catch ( \Throwable ) {}
				$reporter = 'Guest';
				try { $m = \IPS\Member::load( (int) $row['member_id'] ); if ( $m->member_id ) { $reporter = $m->name; } } catch ( \Throwable ) {}
				$row['product_title'] = $title;
				$row['reporter_name'] = $reporter;
				$row['product_url']   = (string) \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=results&do=product&upc=' . urlencode( $row['upc'] ), 'front' );
				$rows[] = $row;
			}
		} catch ( \Throwable ) {}

		$base = \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=reports' );

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_reports_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'reports', 'gdsearch', 'admin' )->queue( $rows, $status, $counts, (string) $base, \IPS\Session::i()->csrfKey );
	}

	protected function resolve(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'reports_manage' );
		\IPS\Session::i()->csrfCheck();
		$this->_setStatus( (int) \IPS\Request::i()->id, 'resolved' );
	}

	protected function dismiss(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'reports_manage' );
		\IPS\Session::i()->csrfCheck();
		$this->_setStatus( (int) \IPS\Request::i()->id, 'dismissed' );
	}

	protected function reopen(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'reports_manage' );
		\IPS\Session::i()->csrfCheck();
		$this->_setStatus( (int) \IPS\Request::i()->id, 'open' );
	}

	protected function note(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'reports_manage' );
		\IPS\Session::i()->csrfCheck();
		$id = (int) \IPS\Request::i()->id;
		try {
			\IPS\Db::i()->update( 'gd_product_reports', [ 'admin_note' => (string) \IPS\Request::i()->admin_note ], [ 'id=?', $id ] );
		} catch ( \Throwable ) {}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=reports' ), 'saved' );
	}

	protected function _setStatus( int $id, string $status ): void
	{
		try {
			\IPS\Db::i()->update( 'gd_product_reports', [
				'status'     => $status,
				'handled_by' => (int) \IPS\Member::loggedIn()->member_id,
				'handled_at' => time(),
			], [ 'id=?', $id ] );
		} catch ( \Throwable ) {}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=reports&status=' . $status ), 'saved' );
	}
}
class reports extends _reports {}
