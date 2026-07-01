<?php
/**
 * @brief  GD Compliance — AWB named-model editor (multi-state, v1.6.0+)
 *
 * Table\Db over gd_compliance_awb_models with a state_code filter.
 * Reseed button re-imports the statutory pattern sets (IL/CA/NY)
 * non-destructively — existing rows preserved by (state, pattern_norm).
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _awbmodels extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const STATES = [ 'IL' => 'Illinois (PICA)', 'CA' => 'California', 'NY' => 'New York (SAFE)' ];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$stateFilter = strtoupper( trim( (string) ( \IPS\Request::i()->state_code ?? '' ) ) );
		if ( !isset( self::STATES[ $stateFilter ] ) ) { $stateFilter = ''; }

		$reseedBanner = '';
		if ( isset( \IPS\Request::i()->reseed_inserted ) )
		{
			$ins = (int) \IPS\Request::i()->reseed_inserted;
			$skp = (int) ( \IPS\Request::i()->reseed_skipped ?? 0 );
			$fld = (int) ( \IPS\Request::i()->reseed_failed  ?? 0 );
			$reseedBanner = '<div class="ipsBox" style="margin-bottom:14px;border-left:4px solid #059669"><div class="ipsBox_body ipsPad" style="background:#ecfdf5">'
				. '<strong style="color:#065f46">' . $h( $lang->addToStack( 'gdcompliance_acp_awb_reseed_done' ) ) . '</strong>'
				. ' &mdash; ' . sprintf( '%d inserted, %d skipped, %d failed', $ins, $skp, $fld )
				. '</div></div>';
		}

		$baseUrl  = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbmodels' );
		$tableUrl = $stateFilter !== '' ? $baseUrl->setQueryString( 'state_code', $stateFilter ) : $baseUrl;
		$where    = $stateFilter !== '' ? [ [ 'state_code=?', $stateFilter ] ] : [];

		$table = new \IPS\Helpers\Table\Db( 'gd_compliance_awb_models', $tableUrl, $where );
		$table->langPrefix    = 'gdcompliance_acp_awb_col_';
		$table->include       = [ 'state_code', 'pattern', 'pattern_norm', 'platform_group', 'citation', 'enabled' ];
		$table->sortBy        = $table->sortBy ?: 'state_code';
		$table->sortDirection = $table->sortDirection ?: 'asc';

		$table->parsers = [
			'state_code'     => fn( $v ) => '<strong style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e3a8a">' . $h( (string) $v ) . '</strong>',
			'pattern'        => fn( $v ) => '<strong>' . $h( (string) $v ) . '</strong>',
			'pattern_norm'   => fn( $v ) => '<span style="font-family:ui-monospace,monospace;font-size:12px;color:#64748b">' . $h( (string) $v ) . '</span>',
			'platform_group' => fn( $v ) => '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e3a8a">' . $h( (string) $v ) . '</span>',
			'citation'       => fn( $v ) => $v ? '<span style="color:#475569;font-size:12px">' . $h( (string) $v ) . '</span>' : '<span style="color:#cbd5e1">—</span>',
			'enabled'        => fn( $v ) => ( (int) $v === 1 )
				? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#14532d">ON</span>'
				: '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b">OFF</span>',
		];

		$table->rowButtons = function( $row ) {
			$base = 'app=gdcompliance&module=compliance&controller=awbmodels';
			$btns = [
				'edit' => [ 'icon' => 'pencil', 'title' => 'edit', 'link' => \IPS\Http\Url::internal( $base . '&do=form&id=' . (int) $row['id'] ) ],
			];
			$btns[ (int) $row['enabled'] === 1 ? 'disable' : 'enable' ] = [
				'icon'  => (int) $row['enabled'] === 1 ? 'pause' : 'play',
				'title' => (int) $row['enabled'] === 1 ? 'gdcompliance_disable' : 'gdcompliance_enable',
				'link'  => \IPS\Http\Url::internal( $base . '&do=toggle&id=' . (int) $row['id'] )->csrf(),
			];
			$btns['delete'] = [ 'icon' => 'times-circle', 'title' => 'delete', 'link' => \IPS\Http\Url::internal( $base . '&do=delete&id=' . (int) $row['id'] )->csrf(), 'data' => [ 'delete' => '' ] ];
			return $btns;
		};

		$addUrl    = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbmodels&do=form' );
		$reseedUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbmodels&do=reseed' )->csrf();

		$stateTabs = '<div style="margin:0 0 10px;display:flex;gap:6px;flex-wrap:wrap">';
		foreach ( array_merge( [ '' => 'All states' ], self::STATES ) as $key => $label )
		{
			$active = $stateFilter === $key ? ' ipsButton--primary' : ' ipsButton--soft';
			$href   = $key === '' ? (string) $baseUrl : (string) $baseUrl->setQueryString( 'state_code', $key );
			$stateTabs .= '<a class="ipsButton ipsButton--small' . $active . '" href="' . $h( $href ) . '">' . $h( (string) $label ) . '</a>';
		}
		$stateTabs .= '</div>';

		$intro = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $h( $lang->addToStack( 'gdcompliance_acp_awb_title' ) ) . '</h2>'
			. '<p style="margin:0 0 8px">' . $h( $lang->addToStack( 'gdcompliance_acp_awb_intro' ) ) . '</p>'
			. $stateTabs
			. '<a href="' . $h( $addUrl ) . '" class="ipsButton ipsButton--primary ipsButton--small" style="margin-right:6px">' . $h( $lang->addToStack( 'gdcompliance_acp_awb_add' ) ) . '</a>'
			. '<a href="' . $h( $reseedUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--small">' . $h( $lang->addToStack( 'gdcompliance_acp_awb_reseed' ) ) . '</a>'
			. '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_awb_title' );
		\IPS\Output::i()->output = $reseedBanner . $intro . (string) $table;
	}

	protected function form(): void
	{
		$id  = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = null;
		if ( $id > 0 )
		{
			try { $row = \IPS\Db::i()->select( '*', 'gd_compliance_awb_models', [ 'id=?', $id ] )->first(); }
			catch ( \Throwable ) { $row = null; }
		}

		$stateOpts = [];
		foreach ( self::STATES as $k => $v ) { $stateOpts[ $k ] = $v; }

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Select( 'gdcompliance_awb_f_state_code',     $row['state_code'] ?? 'IL', TRUE, [ 'options' => $stateOpts ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_awb_f_pattern',        $row['pattern']    ?? '', TRUE,  [ 'maxLength' => 120 ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_awb_f_platform_group', $row['platform_group'] ?? '', FALSE, [ 'maxLength' => 40 ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_awb_f_citation',       $row['citation'] ?? '', FALSE, [ 'maxLength' => 255 ] ) );
		$form->add( new \IPS\Helpers\Form\YesNo(  'gdcompliance_awb_f_enabled',        (int) ( $row['enabled'] ?? 1 ) ) );

		if ( $values = $form->values() )
		{
			$pattern = trim( (string) $values['gdcompliance_awb_f_pattern'] );
			$norm    = \IPS\gdcompliance\AwbModels::normalize( $pattern );

			if ( $pattern === '' || strlen( $norm ) < 3 )
			{
				$form->error = 'Pattern must normalize to at least 3 alphanumeric characters.';
			}
			else
			{
				$data = [
					'state_code'     => substr( strtoupper( (string) $values['gdcompliance_awb_f_state_code'] ), 0, 2 ),
					'pattern'        => substr( $pattern, 0, 120 ),
					'pattern_norm'   => substr( $norm, 0, 120 ),
					'platform_group' => substr( (string) $values['gdcompliance_awb_f_platform_group'], 0, 40 ),
					'citation'       => substr( (string) $values['gdcompliance_awb_f_citation'], 0, 255 ),
					'enabled'        => (int) $values['gdcompliance_awb_f_enabled'] ? 1 : 0,
					'updated_at'     => time(),
				];
				try
				{
					if ( $row ) { \IPS\Db::i()->update( 'gd_compliance_awb_models', $data, [ 'id=?', $id ] ); }
					else        { \IPS\Db::i()->insert( 'gd_compliance_awb_models', $data ); }
					try { \IPS\gdcompliance\AwbModels::clearCache(); } catch ( \Throwable ) {}
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'awb model save: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
				}

				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbmodels' ),
					'saved'
				);
				return;
			}
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_awb_add' );
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
				$cur = (int) \IPS\Db::i()->select( 'enabled', 'gd_compliance_awb_models', [ 'id=?', $id ] )->first();
				\IPS\Db::i()->update( 'gd_compliance_awb_models', [ 'enabled' => $cur === 1 ? 0 : 1, 'updated_at' => time() ], [ 'id=?', $id ] );
				try { \IPS\gdcompliance\AwbModels::clearCache(); } catch ( \Throwable ) {}
			}
			catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbmodels' ) );
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try
			{
				\IPS\Db::i()->delete( 'gd_compliance_awb_models', [ 'id=?', $id ] );
				try { \IPS\gdcompliance\AwbModels::clearCache(); } catch ( \Throwable ) {}
			}
			catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbmodels' ), 'deleted' );
	}

	protected function reseed(): void
	{
		\IPS\Session::i()->csrfCheck();

		$counts = [ 'inserted' => 0, 'skipped' => 0, 'failed' => 0 ];
		try { $counts = \IPS\gdcompliance\AwbModels::seedMissingModels(); }
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'awb reseed: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		\IPS\Session::i()->log( 'acplog__gdcompliance_awb_reseeded' );

		$url = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbmodels' )
			->setQueryString( [
				'reseed_inserted' => (int) $counts['inserted'],
				'reseed_skipped'  => (int) $counts['skipped'],
				'reseed_failed'   => (int) $counts['failed'],
			] );

		\IPS\Output::i()->redirect( $url, 'saved' );
	}
}

class awbmodels extends _awbmodels {}
