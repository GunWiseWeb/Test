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

	const STATES = [
		'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
		'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
		'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
	];

	const TYPES = [ 'law', 'enacted', 'pending' ];

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();

		/* --- Read + sanitize filters (drive Table\Db's WHERE clauses) --- */
		$state = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( $state !== '' && !in_array( $state, self::STATES, true ) ) { $state = ''; }

		$type = strtolower( trim( (string) ( \IPS\Request::i()->type ?? '' ) ) );
		if ( $type !== '' && $type !== 'all' && !in_array( $type, self::TYPES, true ) ) { $type = ''; }

		$q = trim( (string) ( \IPS\Request::i()->q ?? '' ) );
		if ( strlen( $q ) > 200 ) { $q = substr( $q, 0, 200 ); }

		/* WHERE for Table\Db. Native parameterized binds — rule #2. */
		$where = [];
		if ( $state !== '' )               { $where[] = [ 'state_code=?', $state ]; }
		if ( $type  !== '' && $type !== 'all' ) { $where[] = [ 'bill_type=?', $type ]; }
		if ( $q     !== '' )
		{
			$like = '%' . $q . '%';
			$where[] = [ '(bill_title LIKE ? OR bill_number LIKE ?)', $like, $like ];
		}

		/* Bake active filters into the table's base URL so pagination + sort
		   links carry them. Same pattern as gdrebates queue.php. */
		$baseUrl = \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills' );
		if ( $state !== '' ) { $baseUrl = $baseUrl->setQueryString( 'state', $state ); }
		if ( $type  !== '' && $type !== 'all' ) { $baseUrl = $baseUrl->setQueryString( 'type', $type ); }
		if ( $q     !== '' ) { $baseUrl = $baseUrl->setQueryString( 'q', $q ); }

		/* --- Native IPS ACP table --- */
		$table = new \IPS\Helpers\Table\Db( 'gd_bills', $baseUrl, $where );
		$table->langPrefix = 'gdbills_acp_col_';
		$table->include    = [ 'state_code', 'bill_number', 'bill_title', 'bill_type', 'status', 'last_action_date', 'source' ];
		$table->sortBy        = $table->sortBy ?: 'last_action_date';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		/* Column formatters — link the title to its source, render type as a
		   colored pill that matches the front badges, fall back for empty
		   dates / status / source. */
		$table->parsers = [
			'state_code' => function( $v ) {
				return '<strong>' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</strong>';
			},
			'bill_number' => function( $v ) {
				return '<span style="font-family:ui-monospace,monospace;color:#475569">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'bill_title' => function( $v, $row ) {
				$t = htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
				if ( !empty( $row['url'] ) )
				{
					$u = htmlspecialchars( (string) $row['url'], ENT_QUOTES, 'UTF-8' );
					return "<a href='{$u}' target='_blank' rel='nofollow noopener'><strong>{$t}</strong></a>";
				}
				return "<strong>{$t}</strong>";
			},
			'bill_type' => function( $v ) {
				$type = strtolower( (string) $v );
				$pill = match( $type ) {
					'law'     => 'background:#dbeafe;color:#1e3a8a',
					'enacted' => 'background:#dcfce7;color:#14532d',
					'pending' => 'background:#fef3c7;color:#92400e',
					default   => 'background:#f1f5f9;color:#475569',
				};
				return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;' . $pill . '">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'status' => function( $v ) {
				$s = strtolower( (string) $v );
				$color = match( $s ) {
					'vetoed', 'failed' => '#991b1b',
					'enacted'          => '#14532d',
					default            => '#475569',
				};
				return '<span style="color:' . $color . '">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'last_action_date' => function( $v ) {
				return $v ? '<span style="white-space:nowrap;color:#64748b">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>' : '<span style="color:#cbd5e1">—</span>';
			},
			'source' => function( $v ) {
				return '<span style="color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.04em">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
		];

		/* Native row action menu (Edit / Delete). Same shape as
		   gdrebates/modules/admin/rebates/queue.php — confirmed working
		   on this IPS 5.0.18 install. */
		$table->rowButtons = function( $row ) {
			$base = 'app=gdbills&module=bills&controller=bills';
			return [
				'edit' => [
					'icon'  => 'pencil',
					'title' => 'edit',
					'link'  => \IPS\Http\Url::internal( $base . '&do=edit&id=' . (int) $row['id'] ),
				],
				'delete' => [
					'icon'  => 'times-circle',
					'title' => 'delete',
					'link'  => \IPS\Http\Url::internal( $base . '&do=delete&id=' . (int) $row['id'] )->csrf(),
					'data'  => [ 'delete' => '' ],
				],
			];
		};

		/* --- Header above the table: type tabs + state/title search +
		   Add bill button. Mirrors gdrebates queue.php tabs idiom (the
		   sister-app reference uses tabs, NOT Table\Db quickSearch/
		   advancedSearch — sticking to the known-working pattern). --- */
		$resetUrl = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills' );
		$addUrl   = (string) \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills&do=add' );

		$tabs = '';
		$tabEntries = [ 'all' => 'gdbills_filter_all', 'law' => 'gdbills_filter_law', 'enacted' => 'gdbills_filter_enacted', 'pending' => 'gdbills_filter_pending' ];
		foreach ( $tabEntries as $key => $langKey )
		{
			$tabUrl = \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=bills' );
			if ( $key !== 'all' ) { $tabUrl = $tabUrl->setQueryString( 'type', $key ); }
			if ( $state !== '' )  { $tabUrl = $tabUrl->setQueryString( 'state', $state ); }
			if ( $q     !== '' )  { $tabUrl = $tabUrl->setQueryString( 'q', $q ); }

			$cnt = ( $key === 'all' )
				? \IPS\Db::i()->select( 'COUNT(*)', 'gd_bills' )->first()
				: \IPS\Db::i()->select( 'COUNT(*)', 'gd_bills', [ 'bill_type=?', $key ] )->first();
			$activeMatch = ( $key === 'all' ) ? ( $type === '' || $type === 'all' ) : ( $type === $key );
			$active = $activeMatch ? ' ipsButton--primary' : ' ipsButton--soft';
			$lbl    = htmlspecialchars( (string) $lang->addToStack( $langKey ), ENT_QUOTES, 'UTF-8' );
			$tabs  .= "<a class='ipsButton ipsButton--small{$active}' href='" . htmlspecialchars( (string) $tabUrl, ENT_QUOTES ) . "'>{$lbl} ({$cnt})</a> ";
		}

		$stateOpts = '<option value=""' . ( $state === '' ? ' selected' : '' ) . '>' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_filter_all_states' ), ENT_QUOTES, 'UTF-8' ) . '</option>';
		foreach ( self::STATES as $st )
		{
			$sel = ( $st === $state ) ? ' selected' : '';
			$stateOpts .= '<option value="' . $st . '"' . $sel . '>' . $st . '</option>';
		}

		$searchForm = "<form method='get' action='" . htmlspecialchars( $resetUrl, ENT_QUOTES ) . "' class='ipsBox ipsPad' style='display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:0 0 14px'>"
			. "<input type='hidden' name='app' value='gdbills'>"
			. "<input type='hidden' name='module' value='bills'>"
			. "<input type='hidden' name='controller' value='bills'>";
		if ( $type !== '' && $type !== 'all' ) { $searchForm .= "<input type='hidden' name='type' value='" . htmlspecialchars( $type, ENT_QUOTES ) . "'>"; }
		$searchForm .= "<label style='display:flex;flex-direction:column;gap:3px;font-size:12px'><span>"
			. htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_search_state' ), ENT_QUOTES, 'UTF-8' )
			. "</span><select name='state' style='min-width:80px'>{$stateOpts}</select></label>"
			. "<label style='display:flex;flex-direction:column;gap:3px;font-size:12px;flex:1 1 240px'><span>"
			. htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_search_q' ), ENT_QUOTES, 'UTF-8' )
			. "</span><input type='search' name='q' value='" . htmlspecialchars( $q, ENT_QUOTES, 'UTF-8' ) . "' placeholder='bill title or number'></label>"
			. "<button type='submit' class='ipsButton ipsButton--primary ipsButton--small'>"
			. htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_search_go' ), ENT_QUOTES, 'UTF-8' )
			. "</button>"
			. "<a href='" . htmlspecialchars( $resetUrl, ENT_QUOTES ) . "' class='ipsButton ipsButton--soft ipsButton--small'>"
			. htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_search_reset' ), ENT_QUOTES, 'UTF-8' )
			. "</a></form>";

		/* Intro + toolbar in a native ACP panel (ipsBox + ipsBox_body + ipsPad).
		   Tabs left, Add bill button right. Classes are the double-dash BEM
		   modifiers verified against gdcatalog/products.php on 5.0.18. */
		$intro = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<p style="margin:0 0 10px">' . htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_bills_intro' ), ENT_QUOTES, 'UTF-8' ) . '</p>'
			. "<div style='display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px'>"
			. "<div>{$tabs}</div>"
			. "<a href='" . htmlspecialchars( $addUrl, ENT_QUOTES ) . "' class='ipsButton ipsButton--primary ipsButton--small'>"
			. htmlspecialchars( (string) $lang->addToStack( 'gdbills_acp_bills_add' ), ENT_QUOTES, 'UTF-8' )
			. "</a>"
			. "</div>"
			. '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdbills_acp_bills_title' );
		\IPS\Output::i()->output = $intro . $searchForm . (string) $table;
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
