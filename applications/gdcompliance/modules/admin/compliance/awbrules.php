<?php
/**
 * @brief  GD Compliance — AWB per-state feature-test config editor (v1.6.0+)
 *
 * Table\Db over gd_compliance_awb_rules. Each row is ONE (state, firearm_class)
 * pair. Derrick tunes feature_count_threshold, centerfire_only,
 * max_overall_length_in (CA <30"), effective/expires dates, and the
 * enabled toggle. Reseed button re-imports the statutory config
 * non-destructively.
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _awbrules extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const STATES = [
		'CA'=>'California','CT'=>'Connecticut','DC'=>'District of Columbia',
		'DE'=>'Delaware','IL'=>'Illinois','MA'=>'Massachusetts','MD'=>'Maryland',
		'NJ'=>'New Jersey','NY'=>'New York','RI'=>'Rhode Island','VA'=>'Virginia','WA'=>'Washington',
	];
	const CLASSES = [ 'rifle' => 'Rifle', 'pistol' => 'Pistol', 'shotgun' => 'Shotgun' ];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$reseedBanner = '';
		if ( isset( \IPS\Request::i()->reseed_inserted ) )
		{
			$ins = (int) \IPS\Request::i()->reseed_inserted;
			$skp = (int) ( \IPS\Request::i()->reseed_skipped ?? 0 );
			$fld = (int) ( \IPS\Request::i()->reseed_failed  ?? 0 );
			$reseedBanner = '<div class="ipsBox" style="margin-bottom:14px;border-left:4px solid #059669"><div class="ipsBox_body ipsPad" style="background:#ecfdf5">'
				. '<strong style="color:#065f46">' . $h( $lang->addToStack( 'gdcompliance_acp_awbrules_reseed_done' ) ) . '</strong>'
				. ' &mdash; ' . sprintf( '%d inserted, %d skipped, %d failed', $ins, $skp, $fld )
				. '</div></div>';
		}

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbrules' );

		$table = new \IPS\Helpers\Table\Db( 'gd_compliance_awb_rules', $baseUrl );
		$table->langPrefix    = 'gdcompliance_acp_awbrules_col_';
		$table->include       = [ 'state_code', 'firearm_class', 'feature_count_threshold', 'centerfire_only', 'max_overall_length_in', 'effective_date', 'expires_date', 'enabled', 'notes' ];
		$table->sortBy        = $table->sortBy ?: 'state_code';
		$table->sortDirection = $table->sortDirection ?: 'asc';

		$table->parsers = [
			'state_code'              => fn( $v ) => '<strong style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e3a8a">' . $h( (string) $v ) . '</strong>',
			'firearm_class'           => fn( $v ) => '<span style="color:#475569;font-size:12px">' . $h( (string) $v ) . '</span>',
			'feature_count_threshold' => fn( $v ) => '<strong style="font-family:ui-monospace,monospace">' . (int) $v . '</strong>',
			'centerfire_only'         => fn( $v ) => (int) $v === 1 ? '<span style="color:#14532d">yes</span>' : '<span style="color:#94a3b8">no</span>',
			'max_overall_length_in'   => fn( $v ) => $v ? '&lt; ' . number_format( (float) $v, 2 ) . '&#8221;' : '<span style="color:#cbd5e1">—</span>',
			'effective_date'          => fn( $v ) => $v ? $h( (string) $v ) : '<span style="color:#cbd5e1">—</span>',
			'expires_date'            => fn( $v ) => $v ? $h( (string) $v ) : '<span style="color:#cbd5e1">—</span>',
			'enabled'                 => fn( $v ) => ( (int) $v === 1 )
				? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#14532d">ON</span>'
				: '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b">OFF</span>',
			'notes'                   => fn( $v ) => $v ? '<span style="color:#64748b;font-size:12px">' . $h( (string) $v ) . '</span>' : '<span style="color:#cbd5e1">—</span>',
		];

		$table->rowButtons = function( $row ) {
			$base = 'app=gdcompliance&module=compliance&controller=awbrules';
			$btns = [
				'edit' => [ 'icon' => 'pencil', 'title' => 'edit', 'link' => \IPS\Http\Url::internal( $base . '&do=form&id=' . (int) $row['id'] ) ],
			];
			$btns[ (int) $row['enabled'] === 1 ? 'disable' : 'enable' ] = [
				'icon'  => (int) $row['enabled'] === 1 ? 'pause' : 'play',
				'title' => (int) $row['enabled'] === 1 ? 'gdcompliance_disable' : 'gdcompliance_enable',
				'link'  => \IPS\Http\Url::internal( $base . '&do=toggle&id=' . (int) $row['id'] )->csrf(),
			];
			return $btns;
		};

		$addUrl    = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbrules&do=form' );
		$reseedUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbrules&do=reseed' )->csrf();

		$intro = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $h( $lang->addToStack( 'gdcompliance_acp_awbrules_title' ) ) . '</h2>'
			. '<p style="margin:0 0 10px">' . $h( $lang->addToStack( 'gdcompliance_acp_awbrules_intro' ) ) . '</p>'
			. '<a href="' . $h( $addUrl ) . '" class="ipsButton ipsButton--primary ipsButton--small" style="margin-right:6px">' . $h( $lang->addToStack( 'gdcompliance_acp_awbrules_add' ) ) . '</a>'
			. '<a href="' . $h( $reseedUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--small">' . $h( $lang->addToStack( 'gdcompliance_acp_awbrules_reseed' ) ) . '</a>'
			. '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_awbrules_title' );
		\IPS\Output::i()->output = $reseedBanner . $intro . (string) $table;
	}

	protected function form(): void
	{
		$id  = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = null;
		if ( $id > 0 )
		{
			try { $row = \IPS\Db::i()->select( '*', 'gd_compliance_awb_rules', [ 'id=?', $id ] )->first(); }
			catch ( \Throwable ) { $row = null; }
		}

		$stateOpts = self::STATES;
		$classOpts = self::CLASSES;

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Select( 'gdcompliance_awbrules_f_state_code',              $row['state_code']              ?? 'CA', TRUE, [ 'options' => $stateOpts ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdcompliance_awbrules_f_firearm_class',           $row['firearm_class']           ?? 'rifle', TRUE, [ 'options' => $classOpts ] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gdcompliance_awbrules_f_feature_count_threshold', (int)  ( $row['feature_count_threshold'] ?? 1 ), TRUE, [ 'min' => 1, 'max' => 3 ] ) );
		$form->add( new \IPS\Helpers\Form\YesNo(  'gdcompliance_awbrules_f_centerfire_only',         (int)  ( $row['centerfire_only']         ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_awbrules_f_max_overall_length_in',  (string) ( $row['max_overall_length_in'] ?? '' ), FALSE, [ 'maxLength' => 6, 'placeholder' => '30.00' ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_awbrules_f_citation',               (string) ( $row['citation']              ?? '' ), FALSE, [ 'maxLength' => 255 ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_awbrules_f_effective_date',         (string) ( $row['effective_date']        ?? '' ), FALSE, [ 'maxLength' => 10, 'placeholder' => 'YYYY-MM-DD' ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_awbrules_f_expires_date',           (string) ( $row['expires_date']          ?? '' ), FALSE, [ 'maxLength' => 10, 'placeholder' => 'YYYY-MM-DD' ] ) );
		$form->add( new \IPS\Helpers\Form\YesNo(  'gdcompliance_awbrules_f_enabled',                (int)  ( $row['enabled']                 ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdcompliance_awbrules_f_notes',                (string) ( $row['notes']                 ?? '' ), FALSE, [ 'rows' => 2 ] ) );

		if ( $values = $form->values() )
		{
			$len = trim( (string) $values['gdcompliance_awbrules_f_max_overall_length_in'] );
			$data = [
				'state_code'              => substr( strtoupper( (string) $values['gdcompliance_awbrules_f_state_code'] ), 0, 2 ),
				'firearm_class'           => substr( strtolower( (string) $values['gdcompliance_awbrules_f_firearm_class'] ), 0, 20 ),
				'feature_count_threshold' => max( 1, (int) $values['gdcompliance_awbrules_f_feature_count_threshold'] ),
				'centerfire_only'         => (int) $values['gdcompliance_awbrules_f_centerfire_only'] ? 1 : 0,
				'max_overall_length_in'   => $len === '' ? null : (float) $len,
				'citation'                => substr( (string) $values['gdcompliance_awbrules_f_citation'], 0, 255 ),
				'effective_date'          => self::cleanDate( (string) $values['gdcompliance_awbrules_f_effective_date'] ),
				'expires_date'            => self::cleanDate( (string) $values['gdcompliance_awbrules_f_expires_date'] ),
				'enabled'                 => (int) $values['gdcompliance_awbrules_f_enabled'] ? 1 : 0,
				'notes'                   => substr( (string) $values['gdcompliance_awbrules_f_notes'], 0, 255 ),
				'updated_at'              => time(),
			];
			try
			{
				if ( $row ) { \IPS\Db::i()->update( 'gd_compliance_awb_rules', $data, [ 'id=?', $id ] ); }
				else        { \IPS\Db::i()->insert( 'gd_compliance_awb_rules', $data ); }
				try { \IPS\gdcompliance\AwbModels::clearCache(); } catch ( \Throwable ) {}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'awb rule save: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbrules' ),
				'saved'
			);
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_awbrules_add' );
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
				$cur = (int) \IPS\Db::i()->select( 'enabled', 'gd_compliance_awb_rules', [ 'id=?', $id ] )->first();
				\IPS\Db::i()->update( 'gd_compliance_awb_rules', [ 'enabled' => $cur === 1 ? 0 : 1, 'updated_at' => time() ], [ 'id=?', $id ] );
				try { \IPS\gdcompliance\AwbModels::clearCache(); } catch ( \Throwable ) {}
			}
			catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbrules' ) );
	}

	protected function reseed(): void
	{
		\IPS\Session::i()->csrfCheck();

		$counts = [ 'inserted' => 0, 'skipped' => 0, 'failed' => 0 ];
		try { $counts = \IPS\gdcompliance\AwbModels::seedMissingRules(); }
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'awb rules reseed: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		\IPS\Session::i()->log( 'acplog__gdcompliance_awbrules_reseeded' );

		$url = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbrules' )
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

class awbrules extends _awbrules {}
