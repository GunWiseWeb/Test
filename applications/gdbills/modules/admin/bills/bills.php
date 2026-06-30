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

	const PER_PAGE = 25;

	const STATES = [
		'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
		'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
		'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
	];

	const TYPES = [ 'law', 'enacted', 'pending' ];

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();

		/* --- Read + sanitize filters --- */
		$state = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( $state !== '' && !in_array( $state, self::STATES, true ) ) { $state = ''; }

		$type = strtolower( trim( (string) ( \IPS\Request::i()->type ?? '' ) ) );
		if ( $type !== '' && !in_array( $type, self::TYPES, true ) ) { $type = ''; }

		$status = trim( (string) ( \IPS\Request::i()->status ?? '' ) );
		if ( strlen( $status ) > 50 ) { $status = substr( $status, 0, 50 ); }

		$q = trim( (string) ( \IPS\Request::i()->q ?? '' ) );
		if ( strlen( $q ) > 200 ) { $q = substr( $q, 0, 200 ); }

		/* --- Pagination (filter-aware totals) --- */
		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = self::PER_PAGE;

		$filterArgs = [];
		if ( $state  !== '' ) { $filterArgs['state']  = $state; }
		if ( $type   !== '' ) { $filterArgs['type']   = $type; }
		if ( $status !== '' ) { $filterArgs['status'] = $status; }
		if ( $q      !== '' ) { $filterArgs['q']      = $q; }

		$total      = \IPS\gdbills\Bill::getTotalCount( $filterArgs );
		$totalPages = max( 1, (int) ceil( $total / $perPage ) );
		if ( $page > $totalPages ) { $page = $totalPages; }
		$offset = ( $page - 1 ) * $perPage;

		$rows = \IPS\gdbills\Bill::getAll( $filterArgs + [ 'limit' => $perPage, 'offset' => $offset ] );

		/* --- URLs (preserve filters in pagination/reset) --- */
		$baseUrl  = \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills' );
		$filterUrl = $baseUrl;
		foreach ( $filterArgs as $k => $v ) { $filterUrl = $filterUrl->setQueryString( $k, $v ); }

		$addUrl   = (string) $baseUrl->setQueryString( 'do', 'add' );
		$resetUrl = (string) $baseUrl;

		/* --- Filter bar (GET form, server-rendered selects) --- */
		$stateOpts = '<option value=""' . ( $state === '' ? ' selected' : '' ) . '>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_filter_all_states' ), ENT_QUOTES ) . '</option>';
		foreach ( self::STATES as $st )
		{
			$sel = ( $st === $state ) ? ' selected' : '';
			$stateOpts .= '<option value="' . $st . '"' . $sel . '>' . $st . '</option>';
		}
		$typeOpts = '<option value=""' . ( $type === '' ? ' selected' : '' ) . '>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_filter_all_types' ), ENT_QUOTES ) . '</option>';
		foreach ( self::TYPES as $t )
		{
			$sel = ( $t === $type ) ? ' selected' : '';
			$label = (string) $lang->addToStack( 'gdbills_filter_' . $t );
			$typeOpts .= '<option value="' . $t . '"' . $sel . '>' . htmlspecialchars( $label, ENT_QUOTES ) . '</option>';
		}

		$searchForm = '<form method="get" action="' . htmlspecialchars( (string) $baseUrl, ENT_QUOTES ) . '" class="ipsBox ipsPad" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:0 0 14px">'
			. '<input type="hidden" name="app" value="gdbills">'
			. '<input type="hidden" name="module" value="bills">'
			. '<input type="hidden" name="controller" value="bills">'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_search_state' ), ENT_QUOTES ) . '</span><select name="state" style="min-width:80px">' . $stateOpts . '</select></label>'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_search_type' ), ENT_QUOTES ) . '</span><select name="type" style="min-width:130px">' . $typeOpts . '</select></label>'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;flex:1 1 240px"><span>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_search_q' ), ENT_QUOTES ) . '</span><input type="search" name="q" value="' . htmlspecialchars( $q, ENT_QUOTES ) . '" placeholder="bill title or number"></label>'
			. '<button type="submit" class="ipsButton ipsButton_primary">' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_search_go' ), ENT_QUOTES ) . '</button>'
			. '<a href="' . htmlspecialchars( $resetUrl, ENT_QUOTES ) . '" class="ipsButton ipsButton_link">' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_search_reset' ), ENT_QUOTES ) . '</a>'
			. '</form>';

		/* --- Pagination via IPS core helper --- */
		$pagination = '';
		try
		{
			$pagination = (string) \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
				$filterUrl, $totalPages, $page, $perPage
			);
		}
		catch ( \Throwable ) { $pagination = ''; }

		/* --- Table --- */
		$pillStyle = [
			'law'     => 'background:#dbeafe;color:#1e3a8a',
			'enacted' => 'background:#dcfce7;color:#14532d',
			'pending' => 'background:#fef3c7;color:#92400e',
		];
		$thStyle = ' style="text-align:left;padding:8px 10px;border-bottom:2px solid #e6e9ee;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#475569"';
		$tdStyle = ' style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:top"';

		$table = '<table class="ipsTable ipsTable_responsive" style="width:100%;border-collapse:collapse;background:#fff">'
			. '<thead><tr>'
			. '<th' . $thStyle . '>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_f_state_code' ), ENT_QUOTES ) . '</th>'
			. '<th' . $thStyle . '>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_f_bill_number' ), ENT_QUOTES ) . '</th>'
			. '<th' . $thStyle . '>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_f_bill_title' ), ENT_QUOTES ) . '</th>'
			. '<th' . $thStyle . '>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_f_bill_type' ), ENT_QUOTES ) . '</th>'
			. '<th' . $thStyle . '>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_f_status' ), ENT_QUOTES ) . '</th>'
			. '<th' . $thStyle . '>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_last_action' ), ENT_QUOTES ) . '</th>'
			. '<th' . $thStyle . '>Source</th>'
			. '<th' . $thStyle . ' style="text-align:right;padding:8px 10px;border-bottom:2px solid #e6e9ee;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#475569"></th>'
			. '</tr></thead><tbody>';

		foreach ( $rows as $i => $r )
		{
			$editUrl = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills&do=edit&id=' . (int) $r['id'] );
			$delUrl  = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills&do=delete&id=' . (int) $r['id'] )->csrf();
			$pill    = $pillStyle[ (string) $r['bill_type'] ] ?? 'background:#f1f5f9;color:#475569';
			$titleHtml = htmlspecialchars( (string) $r['bill_title'], ENT_QUOTES );
			if ( !empty( $r['url'] ) )
			{
				$titleHtml = '<a href="' . htmlspecialchars( (string) $r['url'], ENT_QUOTES ) . '" target="_blank" rel="nofollow noopener" style="color:#1e3a8a;text-decoration:none">' . $titleHtml . '</a>';
			}
			$rowBg = ( $i % 2 === 1 ) ? ' background:#fafbfc;' : '';
			$table .= '<tr style="' . $rowBg . '">';
			$table .= '<td' . $tdStyle . '><strong>' . htmlspecialchars( (string) $r['state_code'], ENT_QUOTES ) . '</strong></td>';
			$table .= '<td' . $tdStyle . ' style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:top;font-family:ui-monospace,monospace;color:#475569">' . htmlspecialchars( (string) $r['bill_number'], ENT_QUOTES ) . '</td>';
			$table .= '<td' . $tdStyle . ' style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:top;max-width:480px;line-height:1.4"><strong>' . $titleHtml . '</strong></td>';
			$table .= '<td' . $tdStyle . '><span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;' . $pill . '">' . htmlspecialchars( (string) $r['bill_type'], ENT_QUOTES ) . '</span></td>';
			$table .= '<td' . $tdStyle . '>' . htmlspecialchars( (string) $r['status'], ENT_QUOTES ) . '</td>';
			$table .= '<td' . $tdStyle . ' style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:top;white-space:nowrap;color:#64748b">' . htmlspecialchars( (string) ( $r['last_action_date'] ?? '' ), ENT_QUOTES ) . '</td>';
			$table .= '<td' . $tdStyle . ' style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:top;color:#94a3b8">' . htmlspecialchars( (string) $r['source'], ENT_QUOTES ) . '</td>';
			$table .= '<td' . $tdStyle . ' style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:top;text-align:right;white-space:nowrap"><a href="' . htmlspecialchars( $editUrl, ENT_QUOTES ) . '" class="ipsButton ipsButton_link ipsButton_verySmall">Edit</a> <a href="' . htmlspecialchars( $delUrl, ENT_QUOTES ) . '" class="ipsButton ipsButton_link ipsButton_verySmall" data-confirm>Delete</a></td>';
			$table .= '</tr>';
		}
		if ( empty( $rows ) )
		{
			$table .= '<tr><td colspan="8" style="padding:32px;text-align:center;color:#94a3b8;font-style:italic">'
				. htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_bills_none' ), ENT_QUOTES ) . '</td></tr>';
		}
		$table .= '</tbody></table>';

		/* --- Assemble --- */
		$intro = '<p>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_bills_intro' ), ENT_QUOTES ) . '</p>';
		$header = '<div style="display:flex;justify-content:space-between;align-items:center;margin:0 0 14px;flex-wrap:wrap;gap:10px">'
			. '<a href="' . htmlspecialchars( $addUrl, ENT_QUOTES ) . '" class="ipsButton ipsButton_primary">'
			. htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_bills_add' ), ENT_QUOTES ) . '</a>'
			. '<span style="color:#475569;font-size:13px">' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_bills_count' ), ENT_QUOTES )
			. ': <strong>' . number_format( $total ) . '</strong></span>'
			. '</div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdbills_acp_bills_title' );
		\IPS\Output::i()->output = $intro . $header . $searchForm . $pagination . $table . $pagination;
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
