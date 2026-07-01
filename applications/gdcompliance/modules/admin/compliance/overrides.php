<?php
namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _overrides extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const STATES = [
		'AL','AK','AZ','AR','CA','CO','CT','DC','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
		'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
		'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
	];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $k ) => htmlspecialchars( (string) $lang->addToStack( $k ), ENT_QUOTES, 'UTF-8' );

		$total = 0;
		try { $total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_overrides' )->first(); } catch ( \Throwable ) {}

		$addUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=overrides&do=form' );

		$intro = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 10px">' . $h( 'gdcompliance_acp_overrides_title' ) . '</h2>'
			. '<p style="margin:0 0 10px">' . $h( 'gdcompliance_acp_overrides_intro' ) . '</p>'
			. '<p style="margin:0 0 14px"><strong>' . $h( 'gdcompliance_acp_overrides_count' ) . ':</strong> ' . number_format( $total ) . '</p>'
			. '<a href="' . htmlspecialchars( $addUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--primary ipsButton--small">' . $h( 'gdcompliance_acp_overrides_add' ) . '</a>'
			. '</div></div>';

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=overrides' );
		$table   = new \IPS\Helpers\Table\Db( 'gd_compliance_overrides', $baseUrl );
		$table->langPrefix    = 'gdcompliance_acp_overrides_col_';
		$table->include       = [ 'upc', 'state_code', 'action', 'reason', 'created_by', 'created_at' ];
		$table->sortBy        = $table->sortBy ?: 'created_at';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->parsers = [
			'upc'        => function( $v ) { return '<span style="font-family:ui-monospace,monospace;font-size:12px">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>'; },
			'state_code' => function( $v ) { return '<strong>' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</strong>'; },
			'action'     => function( $v ) {
				$pill = ( (string) $v === 'force_restrict' )
					? 'background:#fee2e2;color:#991b1b'
					: 'background:#dcfce7;color:#14532d';
				return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;' . $pill . '">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'reason'     => function( $v ) { return $v ? htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">—</span>'; },
			'created_by' => function( $v ) {
				if ( !$v ) { return '<span style="color:#cbd5e1">—</span>'; }
				try { $m = \IPS\Member::load( (int) $v ); return htmlspecialchars( (string) $m->name, ENT_QUOTES, 'UTF-8' ); }
				catch ( \Throwable ) { return '#' . (int) $v; }
			},
			'created_at' => function( $v ) { return $v ? htmlspecialchars( date( 'Y-m-d H:i', (int) $v ), ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">—</span>'; },
		];

		$table->rowButtons = function( $row ) {
			$base = 'app=gdcompliance&module=compliance&controller=overrides';
			return [
				'edit' => [
					'icon'  => 'pencil',
					'title' => 'edit',
					'link'  => \IPS\Http\Url::internal( $base . '&do=form&id=' . (int) $row['id'] ),
				],
				'delete' => [
					'icon'  => 'times-circle',
					'title' => 'delete',
					'link'  => \IPS\Http\Url::internal( $base . '&do=delete&id=' . (int) $row['id'] )->csrf(),
					'data'  => [ 'delete' => '' ],
				],
			];
		};

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_overrides_title' );
		\IPS\Output::i()->output = $intro . (string) $table;
	}

	protected function form(): void
	{
		$lang = \IPS\Member::loggedIn()->language();

		$id  = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = null;
		if ( $id > 0 )
		{
			try { $row = \IPS\Db::i()->select( '*', 'gd_compliance_overrides', [ 'id=?', $id ] )->first(); }
			catch ( \Throwable ) { $row = null; }
		}

		$stateOpts = [];
		foreach ( self::STATES as $st ) { $stateOpts[ $st ] = $st; }

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_f_upc',        $row['upc'] ?? ( (string) ( \IPS\Request::i()->upc ?? '' ) ), TRUE, [ 'maxLength' => 50 ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdcompliance_f_state_code', $row['state_code'] ?? ( strtoupper( (string) ( \IPS\Request::i()->state ?? 'CA' ) ) ), TRUE, [ 'options' => $stateOpts ] ) );
		$form->add( new \IPS\Helpers\Form\Radio(  'gdcompliance_f_action',     $row['action']     ?? 'force_restrict', TRUE, [ 'options' => [
			'force_restrict' => 'gdcompliance_action_force_restrict',
			'force_clear'    => 'gdcompliance_action_force_clear',
		] ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdcompliance_f_reason',   $row['reason']     ?? '', FALSE, [ 'rows' => 3 ] ) );

		if ( $values = $form->values() )
		{
			$res = \IPS\gdcompliance\Override::save(
				(string) $values['gdcompliance_f_upc'],
				(string) $values['gdcompliance_f_state_code'],
				(string) $values['gdcompliance_f_action'],
				(string) $values['gdcompliance_f_reason'],
				(int) \IPS\Member::loggedIn()->member_id,
				true
			);
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=overrides' ),
				$res['ok'] ? 'saved' : 'gdcompliance_acp_overrides_save_error'
			);
			return;
		}

		\IPS\Output::i()->title  = $lang->addToStack( $row ? 'gdcompliance_acp_overrides_edit' : 'gdcompliance_acp_overrides_add' );
		\IPS\Output::i()->output = (string) $form;
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try
			{
				$row = \IPS\Db::i()->select( 'upc, state_code', 'gd_compliance_overrides', [ 'id=?', $id ] )->first();
				if ( is_array( $row ) )
				{
					\IPS\gdcompliance\Override::remove( (string) $row['upc'], (string) $row['state_code'] );
				}
			}
			catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=overrides' ), 'deleted' );
	}
}

class overrides extends _overrides {}
