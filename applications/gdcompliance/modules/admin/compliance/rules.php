<?php
namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _rules extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const STATES = [
		'AL','AK','AZ','AR','CA','CO','CT','DC','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
		'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
		'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
	];
	const TYPES      = [ 'handgun' => 'Handgun', 'rifle' => 'Rifle', 'shotgun' => 'Shotgun', 'all' => 'All firearms' ];
	const RULE_KINDS = [ 'sale_transfer' => 'Sale / transfer', 'possession' => 'Possession' ];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();

		$reseedBanner = '';
		if ( isset( \IPS\Request::i()->reseed_inserted ) )
		{
			$ins = (int) \IPS\Request::i()->reseed_inserted;
			$skp = (int) ( \IPS\Request::i()->reseed_skipped ?? 0 );
			$fld = (int) ( \IPS\Request::i()->reseed_failed  ?? 0 );
			$reseedBanner = '<div class="ipsBox" style="margin-bottom:14px;border-left:4px solid #059669"><div class="ipsBox_body ipsPad" style="background:#ecfdf5">'
				. '<strong style="color:#065f46">' . htmlspecialchars( (string) $lang->addToStack( 'gdcompliance_acp_rules_reseed_done' ), ENT_QUOTES, 'UTF-8' ) . '</strong>'
				. ' &mdash; ' . sprintf( '%d inserted, %d skipped (already present), %d failed', $ins, $skp, $fld )
				. '</div></div>';
		}

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rules' );

		$table = new \IPS\Helpers\Table\Db( 'gd_compliance_rules', $baseUrl );
		$table->langPrefix    = 'gdcompliance_acp_col_';
		$table->include       = [ 'state_code', 'firearm_type', 'max_capacity', 'rule_type', 'effective_date', 'expires_date', 'enabled', 'source_note' ];
		$table->sortBy        = $table->sortBy ?: 'state_code';
		$table->sortDirection = $table->sortDirection ?: 'asc';

		$table->parsers = [
			'state_code'     => function( $v ) { return '<strong>' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</strong>'; },
			'firearm_type'   => function( $v ) {
				$type = strtolower( (string) $v );
				$pill = match( $type ) {
					'handgun' => 'background:#fef3c7;color:#92400e',
					'rifle'   => 'background:#dcfce7;color:#14532d',
					'shotgun' => 'background:#fee2e2;color:#991b1b',
					default   => 'background:#dbeafe;color:#1e3a8a',
				};
				return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;' . $pill . '">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'max_capacity'   => function( $v ) { return '<span style="font-family:ui-monospace,monospace;font-weight:700">' . (int) $v . '</span>'; },
			'rule_type'      => function( $v ) { return '<span style="color:#475569;font-size:12px">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>'; },
			'effective_date' => function( $v ) { return $v ? htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">—</span>'; },
			'expires_date'   => function( $v ) { return $v ? htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">—</span>'; },
			'enabled'        => function( $v ) {
				return ( (int) $v === 1 )
					? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#14532d">ON</span>'
					: '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b">OFF</span>';
			},
			'source_note'    => function( $v ) { return $v ? '<span style="color:#64748b;font-size:12px">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>' : '<span style="color:#cbd5e1">—</span>'; },
		];

		$table->rowButtons = function( $row ) {
			$base = 'app=gdcompliance&module=compliance&controller=rules';
			$btns = [
				'edit' => [
					'icon'  => 'pencil',
					'title' => 'edit',
					'link'  => \IPS\Http\Url::internal( $base . '&do=form&id=' . (int) $row['id'] ),
				],
			];
			$btns[ (int) $row['enabled'] === 1 ? 'disable' : 'enable' ] = [
				'icon'  => (int) $row['enabled'] === 1 ? 'pause' : 'play',
				'title' => (int) $row['enabled'] === 1 ? 'gdcompliance_disable' : 'gdcompliance_enable',
				'link'  => \IPS\Http\Url::internal( $base . '&do=toggle&id=' . (int) $row['id'] )->csrf(),
			];
			$btns['delete'] = [
				'icon'  => 'times-circle',
				'title' => 'delete',
				'link'  => \IPS\Http\Url::internal( $base . '&do=delete&id=' . (int) $row['id'] )->csrf(),
				'data'  => [ 'delete' => '' ],
			];
			return $btns;
		};

		$addUrl    = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rules&do=form' );
		$reseedUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rules&do=reseed' )->csrf();
		$intro     = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<p style="margin:0 0 10px">' . htmlspecialchars( (string) $lang->addToStack( 'gdcompliance_acp_rules_intro' ), ENT_QUOTES, 'UTF-8' ) . '</p>'
			. "<a href='" . htmlspecialchars( $addUrl, ENT_QUOTES ) . "' class='ipsButton ipsButton--primary ipsButton--small' style='margin-right:6px'>"
			. htmlspecialchars( (string) $lang->addToStack( 'gdcompliance_acp_rules_add' ), ENT_QUOTES, 'UTF-8' )
			. "</a>"
			. "<a href='" . htmlspecialchars( $reseedUrl, ENT_QUOTES ) . "' class='ipsButton ipsButton--secondary ipsButton--small'>"
			. htmlspecialchars( (string) $lang->addToStack( 'gdcompliance_acp_rules_reseed' ), ENT_QUOTES, 'UTF-8' )
			. "</a>"
			. "</div></div>";

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_rules_title' );
		\IPS\Output::i()->output = $intro . (string) $table;
	}

	protected function form(): void
	{
		$id  = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = null;
		if ( $id > 0 )
		{
			try { $row = \IPS\Db::i()->select( '*', 'gd_compliance_rules', [ 'id=?', $id ] )->first(); }
			catch ( \Throwable ) { $row = null; }
		}

		$form = new \IPS\Helpers\Form;

		$stateOpts = [];
		foreach ( self::STATES as $st ) { $stateOpts[ $st ] = $st; }
		$form->add( new \IPS\Helpers\Form\Select( 'gdcompliance_f_state_code',   $row['state_code']   ?? 'CA', TRUE, [ 'options' => $stateOpts ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdcompliance_f_firearm_type', $row['firearm_type'] ?? 'all', TRUE, [ 'options' => self::TYPES ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gdcompliance_f_max_capacity', (int) ( $row['max_capacity'] ?? 10 ), TRUE, [ 'min' => 0, 'max' => 999 ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdcompliance_f_rule_type',    $row['rule_type']    ?? 'sale_transfer', TRUE, [ 'options' => self::RULE_KINDS ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_f_effective_date', $row['effective_date'] ?? '', FALSE, [ 'maxLength' => 10, 'placeholder' => 'YYYY-MM-DD' ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_f_expires_date',   $row['expires_date']   ?? '', FALSE, [ 'maxLength' => 10, 'placeholder' => 'YYYY-MM-DD' ] ) );
		$form->add( new \IPS\Helpers\Form\YesNo(  'gdcompliance_f_enabled',      (int) ( $row['enabled'] ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_f_source_note',  $row['source_note'] ?? '', FALSE, [ 'maxLength' => 255 ] ) );

		if ( $values = $form->values() )
		{
			$data = [
				'state_code'     => strtoupper( (string) $values['gdcompliance_f_state_code'] ),
				'firearm_type'   => (string) $values['gdcompliance_f_firearm_type'],
				'max_capacity'   => (int)    $values['gdcompliance_f_max_capacity'],
				'rule_type'      => (string) $values['gdcompliance_f_rule_type'],
				'effective_date' => self::cleanDate( (string) $values['gdcompliance_f_effective_date'] ),
				'expires_date'   => self::cleanDate( (string) $values['gdcompliance_f_expires_date'] ),
				'enabled'        => (int)    $values['gdcompliance_f_enabled'] ? 1 : 0,
				'source_note'    => substr( (string) $values['gdcompliance_f_source_note'], 0, 255 ),
				'updated_at'     => time(),
			];

			try
			{
				if ( $row )
				{
					\IPS\Db::i()->update( 'gd_compliance_rules', $data, [ 'id=?', $id ] );
				}
				else
				{
					\IPS\Db::i()->insert( 'gd_compliance_rules', $data );
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'rule save: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rules' ),
				'saved'
			);
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_rules_add' );
		\IPS\Output::i()->output = (string) $form;
	}

	protected function toggle(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try
			{
				$cur = (int) \IPS\Db::i()->select( 'enabled', 'gd_compliance_rules', [ 'id=?', $id ] )->first();
				\IPS\Db::i()->update( 'gd_compliance_rules', [ 'enabled' => $cur === 1 ? 0 : 1, 'updated_at' => time() ], [ 'id=?', $id ] );
			}
			catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rules' ) );
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try { \IPS\Db::i()->delete( 'gd_compliance_rules', [ 'id=?', $id ] ); } catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rules' ), 'deleted' );
	}

	/**
	 * Reseed any missing base rules — idempotent, never deletes existing.
	 * Rebuilds the canonical mid-2026 set on top of whatever's there;
	 * rows for (state, type) pairs that already exist are left untouched.
	 */
	protected function reseed(): void
	{
		\IPS\Session::i()->csrfCheck();

		$counts = [ 'inserted' => 0, 'skipped' => 0, 'failed' => 0 ];
		try
		{
			$counts = \IPS\gdcompliance\Seeder::seedMissingRules();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'rules reseed: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		\IPS\Session::i()->log( 'acplog__gdcompliance_rules_reseeded' );

		$msg = sprintf( 'gdcompliance_acp_rules_reseed_result_%d_%d_%d', $counts['inserted'], $counts['skipped'], $counts['failed'] );
		$url = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rules' )
			->setQueryString( [
				'reseed_inserted' => (int) $counts['inserted'],
				'reseed_skipped'  => (int) $counts['skipped'],
				'reseed_failed'   => (int) $counts['failed'],
			] );

		\IPS\Output::i()->redirect( $url, 'saved' );
	}

	protected static function cleanDate( string $v ): ?string
	{
		$v = trim( $v );
		if ( $v === '' ) { return null; }
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
	}
}

class rules extends _rules {}
