<?php
namespace IPS\gdbills\modules\admin\bills;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _bills extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'bills_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = 25;
		$offset  = ( $page - 1 ) * $perPage;

		$state  = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		$type   = (string) ( \IPS\Request::i()->type ?? '' );
		$status = (string) ( \IPS\Request::i()->status ?? '' );

		$args = [ 'state' => $state, 'type' => $type, 'status' => $status, 'limit' => $perPage, 'offset' => $offset ];
		$rows  = \IPS\gdbills\Bill::getAll( $args );
		$total = \IPS\gdbills\Bill::getTotalCount( $args );

		$intro = '<p>' . htmlspecialchars( (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_bills_intro' ), ENT_QUOTES ) . '</p>';
		$addUrl = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills&do=add' );
		$addBtn = '<p><a href="' . htmlspecialchars( $addUrl, ENT_QUOTES ) . '" class="ipsButton ipsButton_primary">'
			. htmlspecialchars( (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_bills_add' ), ENT_QUOTES ) . '</a></p>';

		$html  = $intro . $addBtn;
		$html .= '<p>' . htmlspecialchars( (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_bills_count' ), ENT_QUOTES )
			. ': <strong>' . number_format( $total ) . '</strong></p>';

		$html .= '<table class="ipsTable" style="width:100%;border-collapse:collapse">';
		$html .= '<thead><tr><th>State</th><th>Bill #</th><th>Title</th><th>Type</th><th>Status</th><th>Last action</th><th>Source</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r )
		{
			$editUrl = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills&do=edit&id=' . (int) $r['id'] );
			$delUrl  = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills&do=delete&id=' . (int) $r['id'] )->csrf();
			$html .= '<tr>';
			$html .= '<td>' . htmlspecialchars( (string) $r['state_code'], ENT_QUOTES ) . '</td>';
			$html .= '<td>' . htmlspecialchars( (string) $r['bill_number'], ENT_QUOTES ) . '</td>';
			$html .= '<td>' . htmlspecialchars( (string) $r['bill_title'], ENT_QUOTES ) . '</td>';
			$html .= '<td>' . htmlspecialchars( (string) $r['bill_type'], ENT_QUOTES ) . '</td>';
			$html .= '<td>' . htmlspecialchars( (string) $r['status'], ENT_QUOTES ) . '</td>';
			$html .= '<td>' . htmlspecialchars( (string) ( $r['last_action_date'] ?? '' ), ENT_QUOTES ) . '</td>';
			$html .= '<td>' . htmlspecialchars( (string) $r['source'], ENT_QUOTES ) . '</td>';
			$html .= '<td><a href="' . htmlspecialchars( $editUrl, ENT_QUOTES ) . '">Edit</a> | <a href="' . htmlspecialchars( $delUrl, ENT_QUOTES ) . '" data-confirm>Delete</a></td>';
			$html .= '</tr>';
		}
		if ( empty( $rows ) ) { $html .= '<tr><td colspan="8" style="padding:24px;text-align:center;color:#888">No bills tracked yet.</td></tr>'; }
		$html .= '</tbody></table>';

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_bills_title' );
		\IPS\Output::i()->output = $html;
	}

	protected function add(): void
	{
		$this->billForm( null );
	}

	protected function edit(): void
	{
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = $id > 0 ? \IPS\gdbills\Bill::loadById( $id ) : null;
		if ( !$row )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills' ) );
			return;
		}
		$this->billForm( $row );
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 ) { \IPS\gdbills\Bill::delete( $id ); }
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills' ), 'deleted' );
	}

	protected function billForm( ?array $row ): void
	{
		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_bill_number', $row['bill_number'] ?? '', TRUE, [ 'maxLength' => 50 ] ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_bill_title',  $row['bill_title']  ?? '', TRUE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_state_code',  $row['state_code']  ?? '', TRUE, [ 'maxLength' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdbills_f_bill_type', $row['bill_type']   ?? 'pending', TRUE,
			[ 'options' => [ 'pending' => 'Pending', 'enacted' => 'Enacted', 'law' => 'Law' ] ] ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_status',         $row['status']         ?? 'introduced', FALSE, [ 'maxLength' => 50 ] ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_progress_stage', $row['progress_stage'] ?? '', FALSE, [ 'maxLength' => 50 ] ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_sponsor_name',   $row['sponsor_name']   ?? '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_sponsor_party',  $row['sponsor_party']  ?? '', FALSE, [ 'maxLength' => 50 ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdbills_f_description', $row['description']   ?? '', FALSE, [ 'rows' => 4 ] ) );
		$form->add( new \IPS\Helpers\Form\Url(  'gdbills_f_url',            $row['url']            ?? '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_date_introduced',    $row['date_introduced']    ?? '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_last_action_date',   $row['last_action_date']   ?? '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdbills_f_last_action',    $row['last_action']        ?? '', FALSE, [ 'rows' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_passed_senate_date', $row['passed_senate_date'] ?? '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_passed_house_date',  $row['passed_house_date']  ?? '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_f_signed_date',        $row['signed_date']        ?? '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gdbills_f_legiscan_id',      (int) ( $row['legiscan_id'] ?? 0 ), FALSE, [ 'min' => 0 ] ) );

		if ( $values = $form->values() )
		{
			$data = [
				'bill_number'        => (string) $values['gdbills_f_bill_number'],
				'bill_title'         => (string) $values['gdbills_f_bill_title'],
				'state_code'         => (string) $values['gdbills_f_state_code'],
				'bill_type'          => (string) $values['gdbills_f_bill_type'],
				'status'             => (string) $values['gdbills_f_status'],
				'progress_stage'     => (string) $values['gdbills_f_progress_stage'],
				'sponsor_name'       => (string) $values['gdbills_f_sponsor_name'],
				'sponsor_party'      => (string) $values['gdbills_f_sponsor_party'],
				'description'        => (string) $values['gdbills_f_description'],
				'url'                => (string) $values['gdbills_f_url'],
				'date_introduced'    => (string) $values['gdbills_f_date_introduced'],
				'last_action_date'   => (string) $values['gdbills_f_last_action_date'],
				'last_action'        => (string) $values['gdbills_f_last_action'],
				'passed_senate_date' => (string) $values['gdbills_f_passed_senate_date'],
				'passed_house_date'  => (string) $values['gdbills_f_passed_house_date'],
				'signed_date'        => (string) $values['gdbills_f_signed_date'],
				'legiscan_id'        => (int)    $values['gdbills_f_legiscan_id'],
				'source'             => $row['source'] ?? 'manual',
			];
			\IPS\gdbills\Bill::upsert( $data );
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills' ), 'saved' );
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( $row ? 'gdbills_acp_bills_edit' : 'gdbills_acp_bills_add' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class bills extends _bills {}
