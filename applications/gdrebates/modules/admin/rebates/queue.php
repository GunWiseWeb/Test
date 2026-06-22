<?php
namespace IPS\gdrebates\modules\admin\rebates;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _queue extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'queue_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$status = (string) ( \IPS\Request::i()->status ?? 'pending' );
		if ( !in_array( $status, [ 'pending', 'approved', 'rejected', 'expired', 'all' ], true ) ) { $status = 'pending'; }
		$where = ( $status === 'all' ) ? [] : [ [ 'status=?', $status ] ];

		$baseUrl = \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=queue' )->setQueryString( 'status', $status );
		$table = new \IPS\Helpers\Table\Db( 'gd_rebates', $baseUrl, $where );
		$table->langPrefix = 'gdrebates_rb_';
		$table->include = [ 'manufacturer', 'title', 'rebate_type', 'amount_text', 'end_date', 'status' ];
		$table->sortBy = $table->sortBy ?: 'created';
		$table->sortDirection = $table->sortDirection ?: 'desc';
		$table->parsers = [
			'end_date'    => function( $v ) { return $v ? (string) \IPS\DateTime::ts( $v )->localeDate() : '—'; },
			'rebate_type' => function( $v ) { return \IPS\Member::loggedIn()->language()->addToStack( 'gdrebates_type_' . $v ); },
			'status'      => function( $v ) { return \IPS\Member::loggedIn()->language()->addToStack( 'gdrebates_status_' . $v ); },
			'title'       => function( $v, $row ) {
				$t = htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
				if ( !empty( $row['source_url'] ) ) { $u = htmlspecialchars( (string) $row['source_url'], ENT_QUOTES, 'UTF-8' ); return "<a href='{$u}' target='_blank' rel='noopener'>{$t}</a>"; }
				return $t;
			},
		];
		$table->rowButtons = function( $row ) {
			$base = 'app=gdrebates&module=rebates&controller=queue';
			$btns = [];
			$btns['edit'] = [ 'icon' => 'pencil', 'title' => 'edit', 'link' => \IPS\Http\Url::internal( $base . '&do=form&id=' . $row['rebate_id'] ) ];
			if ( $row['status'] !== 'approved' ) { $btns['approve'] = [ 'icon' => 'check', 'title' => 'gdrebates_approve', 'link' => \IPS\Http\Url::internal( $base . '&do=approve&id=' . $row['rebate_id'] )->csrf() ]; }
			if ( $row['status'] !== 'rejected' ) { $btns['reject'] = [ 'icon' => 'times', 'title' => 'gdrebates_reject', 'link' => \IPS\Http\Url::internal( $base . '&do=reject&id=' . $row['rebate_id'] )->csrf() ]; }
			$btns['delete'] = [ 'icon' => 'times-circle', 'title' => 'delete', 'link' => \IPS\Http\Url::internal( $base . '&do=delete&id=' . $row['rebate_id'] )->csrf(), 'data' => [ 'delete' => '' ] ];
			return $btns;
		};

		$tabs = '';
		foreach ( [ 'pending', 'approved', 'rejected', 'all' ] as $s )
		{
			$url    = \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=queue' )->setQueryString( 'status', $s );
			$cnt    = ( $s === 'all' ) ? \IPS\Db::i()->select( 'COUNT(*)', 'gd_rebates' )->first() : \IPS\Db::i()->select( 'COUNT(*)', 'gd_rebates', [ 'status=?', $s ] )->first();
			$active = ( $s === $status ) ? ' ipsButton--primary' : ' ipsButton--soft';
			$lbl    = \IPS\Member::loggedIn()->language()->addToStack( 'gdrebates_status_' . $s );
			$tabs  .= "<a class='ipsButton ipsButton--small{$active}' href='{$url}'>{$lbl} ({$cnt})</a> ";
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'menu__gdrebates_rebates_queue' );
		\IPS\Output::i()->output = "<p class='ipsPad'>{$tabs}</p>" . (string) $table;
	}

	protected function form(): void
	{
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		try { $row = \IPS\Db::i()->select( '*', 'gd_rebates', [ 'rebate_id=?', $id ] )->first(); }
		catch ( \UnderflowException $e ) { \IPS\Output::i()->error( 'node_error', '2GR200/1', 404 ); return; }

		$types = [ 'cash' => 'gdrebates_type_cash', 'prepaid_card' => 'gdrebates_type_prepaid_card', 'store_credit' => 'gdrebates_type_store_credit', 'free_item' => 'gdrebates_type_free_item', 'free_shipping' => 'gdrebates_type_free_shipping', 'bundle' => 'gdrebates_type_bundle', 'other' => 'gdrebates_type_other' ];
		$statuses = [ 'pending' => 'gdrebates_status_pending', 'approved' => 'gdrebates_status_approved', 'rejected' => 'gdrebates_status_rejected', 'expired' => 'gdrebates_status_expired' ];

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text(     'gdrebates_rb_manufacturer',    $row['manufacturer'], TRUE ) );
		$form->add( new \IPS\Helpers\Form\Text(     'gdrebates_rb_title',           $row['title'], TRUE ) );
		$form->add( new \IPS\Helpers\Form\Select(   'gdrebates_rb_rebate_type',     $row['rebate_type'], TRUE, [ 'options' => $types ] ) );
		$form->add( new \IPS\Helpers\Form\Number(   'gdrebates_rb_amount',          ( $row['amount'] !== null ? (float) $row['amount'] : NULL ), FALSE, [ 'decimals' => 2, 'min' => 0 ] ) );
		$form->add( new \IPS\Helpers\Form\Text(     'gdrebates_rb_amount_text',     $row['amount_text'], FALSE ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdrebates_rb_eligible_models', $row['eligible_models'], FALSE ) );
		$form->add( new \IPS\Helpers\Form\Date(     'gdrebates_rb_start_date',      ( $row['start_date'] ? \IPS\DateTime::ts( $row['start_date'] ) : NULL ), FALSE ) );
		$form->add( new \IPS\Helpers\Form\Date(     'gdrebates_rb_end_date',        ( $row['end_date'] ? \IPS\DateTime::ts( $row['end_date'] ) : NULL ), FALSE ) );
		$form->add( new \IPS\Helpers\Form\Date(     'gdrebates_rb_submit_by',       ( $row['submit_by'] ? \IPS\DateTime::ts( $row['submit_by'] ) : NULL ), FALSE ) );
		$form->add( new \IPS\Helpers\Form\Url(      'gdrebates_rb_redemption_url',  $row['redemption_url'], FALSE ) );
		$form->add( new \IPS\Helpers\Form\Select(   'gdrebates_rb_status',          $row['status'], TRUE, [ 'options' => $statuses ] ) );

		if ( $values = $form->values() )
		{
			$newStatus = (string) $values['gdrebates_rb_status'];
			$save = [
				'manufacturer'    => (string) $values['gdrebates_rb_manufacturer'],
				'title'           => (string) $values['gdrebates_rb_title'],
				'rebate_type'     => (string) $values['gdrebates_rb_rebate_type'],
				'amount'          => ( $values['gdrebates_rb_amount'] !== NULL && $values['gdrebates_rb_amount'] !== '' ) ? (float) $values['gdrebates_rb_amount'] : NULL,
				'amount_text'     => (string) $values['gdrebates_rb_amount_text'],
				'eligible_models' => (string) $values['gdrebates_rb_eligible_models'],
				'start_date'      => $values['gdrebates_rb_start_date'] ? $values['gdrebates_rb_start_date']->getTimestamp() : NULL,
				'end_date'        => $values['gdrebates_rb_end_date'] ? $values['gdrebates_rb_end_date']->getTimestamp() : NULL,
				'submit_by'       => $values['gdrebates_rb_submit_by'] ? $values['gdrebates_rb_submit_by']->getTimestamp() : NULL,
				'redemption_url'  => (string) $values['gdrebates_rb_redemption_url'],
				'status'          => $newStatus,
				'updated'         => time(),
			];
			if ( $newStatus === 'approved' && $row['status'] !== 'approved' )
			{
				$save['approved_by'] = (int) \IPS\Member::loggedIn()->member_id;
				$save['approved_at'] = time();
			}
			\IPS\Db::i()->update( 'gd_rebates', $save, [ 'rebate_id=?', $id ] );
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=queue' ), 'saved' );
			return;
		}

		$links = [];
		if ( !empty( $row['source_url'] ) ) { $links[] = "<a href='" . htmlspecialchars( (string) $row['source_url'], ENT_QUOTES, 'UTF-8' ) . "' target='_blank' rel='noopener'>Source page</a>"; }
		if ( !empty( $row['pdf_url'] ) )    { $links[] = "<a href='" . htmlspecialchars( (string) $row['pdf_url'], ENT_QUOTES, 'UTF-8' ) . "' target='_blank' rel='noopener'>Flyer PDF</a>"; }
		$linksHtml = $links ? "<p class='ipsPad'>" . implode( ' &middot; ', $links ) . "</p>" : '';

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdrebates_rb_edit' );
		\IPS\Output::i()->output = $linksHtml . (string) $form;
	}

	protected function approve(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id )
		{
			\IPS\Db::i()->update( 'gd_rebates', [ 'status' => 'approved', 'approved_by' => (int) \IPS\Member::loggedIn()->member_id, 'approved_at' => time(), 'updated' => time() ], [ 'rebate_id=?', $id ] );
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=queue' ), 'gdrebates_approved' );
	}

	protected function reject(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id )
		{
			\IPS\Db::i()->update( 'gd_rebates', [ 'status' => 'rejected', 'updated' => time() ], [ 'rebate_id=?', $id ] );
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=queue' ), 'gdrebates_rejected' );
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id )
		{
			\IPS\Db::i()->delete( 'gd_rebates', [ 'rebate_id=?', $id ] );
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=queue' ), 'deleted' );
	}
}
class queue extends _queue {}
