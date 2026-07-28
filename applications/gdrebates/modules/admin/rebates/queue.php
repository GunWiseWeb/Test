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
		$now = time();

		/* v1.0.13 — 'expired' is a virtual filter matching rows whose
		   end_date has passed regardless of stored status column value,
		   because no task ever writes status='expired'. The 'all' tab
		   also stays available for full unfiltered browsing. */
		if ( $status === 'expired' )
		{
			$where = [ [ 'end_date IS NOT NULL AND end_date < ?', $now ] ];
		}
		elseif ( $status === 'all' )
		{
			$where = [];
		}
		else
		{
			$where = [ [ 'status=?', $status ] ];
		}

		$baseUrl = \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=queue' )->setQueryString( 'status', $status );
		$table = new \IPS\Helpers\Table\Db( 'gd_rebates', $baseUrl, $where );
		$table->langPrefix = 'gdrebates_rb_';
		/* v1.0.13 — include featured + sort_order in the listing so
		   Derrick can see (and click-header-sort by) the manual
		   ordering state. Default sort is sort_order ASC so featured
		   items paired with a low sort_order float to the top. */
		$table->include = [ 'featured', 'manufacturer', 'title', 'rebate_type', 'amount_text', 'end_date', 'sort_order', 'status' ];
		$table->sortBy = $table->sortBy ?: 'sort_order';
		$table->sortDirection = $table->sortDirection ?: 'asc';
		$table->parsers = [
			'featured'    => function( $v ) {
				return ( (int) $v ) ? "<span class='ipsBadge ipsBadge--warning' title='Featured' style='font-size:14px;'>&#9733;</span>" : '';
			},
			'end_date'    => function( $v ) { return $v ? (string) \IPS\DateTime::ts( $v )->localeDate() : '—'; },
			'rebate_type' => function( $v ) { return \IPS\Member::loggedIn()->language()->addToStack( 'gdrebates_type_' . $v ); },
			'status'      => function( $v ) { return \IPS\Member::loggedIn()->language()->addToStack( 'gdrebates_status_' . $v ); },
			'title'       => function( $v, $row ) {
				$t = htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
				if ( !empty( $row['source_url'] ) ) { $u = htmlspecialchars( (string) $row['source_url'], ENT_QUOTES, 'UTF-8' ); return "<a href='{$u}' target='_blank' rel='noopener'>{$t}</a>"; }
				return $t;
			},
		];
		$table->rowButtons = function( $row ) use ( $status ) {
			$base = 'app=gdrebates&module=rebates&controller=queue';
			$qs   = '&status=' . urlencode( $status );
			$btns = [];
			/* v1.0.13 — up/down arrows swap sort_order with the
			   immediately-adjacent row. Featured toggle is a star that
			   pins the row to the top of the frontend list regardless
			   of dates or sort_order. */
			$btns['moveUp']   = [ 'icon' => 'arrow-up',   'title' => 'gdrebates_move_up',   'link' => \IPS\Http\Url::internal( $base . $qs . '&do=moveUp&id=' . $row['rebate_id'] )->csrf() ];
			$btns['moveDown'] = [ 'icon' => 'arrow-down', 'title' => 'gdrebates_move_down', 'link' => \IPS\Http\Url::internal( $base . $qs . '&do=moveDown&id=' . $row['rebate_id'] )->csrf() ];
			$btns['featured'] = [
				'icon'  => (int) $row['featured'] ? 'star' : 'star-o',
				'title' => (int) $row['featured'] ? 'gdrebates_unfeature' : 'gdrebates_feature',
				'link'  => \IPS\Http\Url::internal( $base . $qs . '&do=togglefeatured&id=' . $row['rebate_id'] )->csrf(),
			];
			$btns['edit'] = [ 'icon' => 'pencil', 'title' => 'edit', 'link' => \IPS\Http\Url::internal( $base . $qs . '&do=form&id=' . $row['rebate_id'] ) ];
			if ( $row['status'] !== 'approved' ) { $btns['approve'] = [ 'icon' => 'check', 'title' => 'gdrebates_approve', 'link' => \IPS\Http\Url::internal( $base . $qs . '&do=approve&id=' . $row['rebate_id'] )->csrf() ]; }
			if ( $row['status'] !== 'rejected' ) { $btns['reject'] = [ 'icon' => 'times', 'title' => 'gdrebates_reject', 'link' => \IPS\Http\Url::internal( $base . $qs . '&do=reject&id=' . $row['rebate_id'] )->csrf() ]; }
			$btns['delete'] = [ 'icon' => 'times-circle', 'title' => 'delete', 'link' => \IPS\Http\Url::internal( $base . $qs . '&do=delete&id=' . $row['rebate_id'] )->csrf(), 'data' => [ 'delete' => '' ] ];
			return $btns;
		};

		$tabs = '';
		foreach ( [ 'pending', 'approved', 'rejected', 'expired', 'all' ] as $s )
		{
			$url = \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=queue' )->setQueryString( 'status', $s );
			if ( $s === 'all' )
			{
				$cnt = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_rebates' )->first();
			}
			elseif ( $s === 'expired' )
			{
				$cnt = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_rebates', [ 'end_date IS NOT NULL AND end_date < ?', $now ] )->first();
			}
			else
			{
				$cnt = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_rebates', [ 'status=?', $s ] )->first();
			}
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
		$form->add( new \IPS\Helpers\Form\YesNo(    'gdrebates_rb_featured',        (int) ( $row['featured'] ?? 0 ), FALSE ) );
		$form->add( new \IPS\Helpers\Form\Number(   'gdrebates_rb_sort_order',      (int) ( $row['sort_order'] ?? 0 ), FALSE, [ 'min' => 0 ] ) );
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
				'featured'        => (int) (bool) $values['gdrebates_rb_featured'],
				'sort_order'      => max( 0, (int) $values['gdrebates_rb_sort_order'] ),
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
		$this->backToQueue( 'gdrebates_approved' );
	}

	protected function reject(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id )
		{
			\IPS\Db::i()->update( 'gd_rebates', [ 'status' => 'rejected', 'updated' => time() ], [ 'rebate_id=?', $id ] );
		}
		$this->backToQueue( 'gdrebates_rejected' );
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id )
		{
			\IPS\Db::i()->delete( 'gd_rebates', [ 'rebate_id=?', $id ] );
		}
		$this->backToQueue( 'deleted' );
	}

	/* v1.0.13 — featured toggle, up/down move, and an AJAX reorder
	   endpoint. Swap semantics: move* finds the immediately-adjacent
	   row by sort_order and swaps sort_order values, leaving all other
	   rows untouched. reorder() accepts an ordered id list (comma-
	   delimited string or array) and rewrites sort_order = position
	   for exactly those rows — intended for future drag-and-drop JS. */

	protected function togglefeatured(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id )
		{
			try
			{
				$cur = (int) \IPS\Db::i()->select( 'featured', 'gd_rebates', [ 'rebate_id=?', $id ] )->first();
				\IPS\Db::i()->update( 'gd_rebates', [ 'featured' => $cur ? 0 : 1, 'updated' => time() ], [ 'rebate_id=?', $id ] );
			}
			catch ( \UnderflowException ) {}
		}
		$this->backToQueue( NULL );
	}

	protected function moveUp(): void
	{
		\IPS\Session::i()->csrfCheck();
		$this->swapWithNeighbor( (int) ( \IPS\Request::i()->id ?? 0 ), TRUE );
		$this->backToQueue( NULL );
	}

	protected function moveDown(): void
	{
		\IPS\Session::i()->csrfCheck();
		$this->swapWithNeighbor( (int) ( \IPS\Request::i()->id ?? 0 ), FALSE );
		$this->backToQueue( NULL );
	}

	protected function reorder(): void
	{
		\IPS\Session::i()->csrfCheck();
		$raw = \IPS\Request::i()->ids ?? '';
		if ( is_string( $raw ) )
		{
			$raw = ( $raw === '' ) ? [] : explode( ',', $raw );
		}
		if ( !is_array( $raw ) )
		{
			\IPS\Output::i()->json( [ 'ok' => FALSE, 'message' => 'ids must be array or comma string' ], 400 );
			return;
		}
		$pos = 1;
		$count = 0;
		foreach ( $raw as $rid )
		{
			$rid = (int) $rid;
			if ( $rid <= 0 ) { continue; }
			\IPS\Db::i()->update( 'gd_rebates', [ 'sort_order' => $pos, 'updated' => time() ], [ 'rebate_id=?', $rid ] );
			$pos++;
			$count++;
		}
		\IPS\Output::i()->json( [ 'ok' => TRUE, 'count' => $count ] );
	}

	protected function swapWithNeighbor( int $id, bool $up ): void
	{
		if ( $id <= 0 ) { return; }
		try
		{
			$myOrder = (int) \IPS\Db::i()->select( 'sort_order', 'gd_rebates', [ 'rebate_id=?', $id ] )->first();
		}
		catch ( \UnderflowException ) { return; }

		if ( $up )
		{
			try
			{
				$neighbor = \IPS\Db::i()->select( 'rebate_id, sort_order', 'gd_rebates', [ 'sort_order < ?', $myOrder ], 'sort_order DESC', 1 )->first();
			}
			catch ( \UnderflowException ) { return; }
		}
		else
		{
			try
			{
				$neighbor = \IPS\Db::i()->select( 'rebate_id, sort_order', 'gd_rebates', [ 'sort_order > ?', $myOrder ], 'sort_order ASC', 1 )->first();
			}
			catch ( \UnderflowException ) { return; }
		}

		$nOrder = (int) $neighbor['sort_order'];
		$nId    = (int) $neighbor['rebate_id'];
		if ( $nId === $id || $nOrder === $myOrder ) { return; }

		\IPS\Db::i()->update( 'gd_rebates', [ 'sort_order' => $nOrder,  'updated' => time() ], [ 'rebate_id=?', $id  ] );
		\IPS\Db::i()->update( 'gd_rebates', [ 'sort_order' => $myOrder, 'updated' => time() ], [ 'rebate_id=?', $nId ] );
	}

	protected function backToQueue( ?string $msg ): void
	{
		$status = (string) ( \IPS\Request::i()->status ?? 'pending' );
		$url = \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=queue' )->setQueryString( 'status', $status );
		if ( $msg === NULL ) { \IPS\Output::i()->redirect( $url ); }
		else                 { \IPS\Output::i()->redirect( $url, $msg ); }
	}
}
class queue extends _queue {}
