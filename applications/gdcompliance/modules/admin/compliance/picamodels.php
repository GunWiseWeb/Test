<?php
/**
 * @brief  GD Compliance — PICA Models editor (ACP)
 *
 * Table\Db over gd_compliance_pica_models. Derrick can add/edit/toggle
 * named patterns + citation as Illinois State Police updates the (a)(1)(J)
 * enumeration. Reseed button re-imports any missing statutory patterns
 * from PicaModels::statutorySeed() — non-destructive (existing rows
 * preserved by pattern_norm).
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _picamodels extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

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
				. '<strong style="color:#065f46">' . $h( $lang->addToStack( 'gdcompliance_acp_pica_reseed_done' ) ) . '</strong>'
				. ' &mdash; ' . sprintf( '%d inserted, %d skipped, %d failed', $ins, $skp, $fld )
				. '</div></div>';
		}

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=picamodels' );

		$table = new \IPS\Helpers\Table\Db( 'gd_compliance_pica_models', $baseUrl );
		$table->langPrefix    = 'gdcompliance_acp_pica_col_';
		$table->include       = [ 'pattern', 'pattern_norm', 'platform_group', 'citation', 'enabled' ];
		$table->sortBy        = $table->sortBy ?: 'platform_group';
		$table->sortDirection = $table->sortDirection ?: 'asc';

		$table->parsers = [
			'pattern'        => fn( $v ) => '<strong>' . $h( (string) $v ) . '</strong>',
			'pattern_norm'   => fn( $v ) => '<span style="font-family:ui-monospace,monospace;font-size:12px;color:#64748b">' . $h( (string) $v ) . '</span>',
			'platform_group' => fn( $v ) => '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e3a8a">' . $h( (string) $v ) . '</span>',
			'citation'       => fn( $v ) => $v ? '<span style="color:#475569;font-size:12px">' . $h( (string) $v ) . '</span>' : '<span style="color:#cbd5e1">—</span>',
			'enabled'        => fn( $v ) => ( (int) $v === 1 )
				? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#14532d">ON</span>'
				: '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b">OFF</span>',
		];

		$table->rowButtons = function( $row ) {
			$base = 'app=gdcompliance&module=compliance&controller=picamodels';
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

		$addUrl    = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=picamodels&do=form' );
		$reseedUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=picamodels&do=reseed' )->csrf();

		$intro  = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 10px">' . $h( $lang->addToStack( 'gdcompliance_acp_pica_title' ) ) . '</h2>'
			. '<p style="margin:0 0 10px">' . $h( $lang->addToStack( 'gdcompliance_acp_pica_intro' ) ) . '</p>'
			. '<a href="' . $h( $addUrl ) . '" class="ipsButton ipsButton--primary ipsButton--small" style="margin-right:6px">'
			. $h( $lang->addToStack( 'gdcompliance_acp_pica_add' ) ) . '</a>'
			. '<a href="' . $h( $reseedUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--small">'
			. $h( $lang->addToStack( 'gdcompliance_acp_pica_reseed' ) ) . '</a>'
			. '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_pica_title' );
		\IPS\Output::i()->output = $reseedBanner . $intro . (string) $table;
	}

	protected function form(): void
	{
		$id  = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = null;
		if ( $id > 0 )
		{
			try { $row = \IPS\Db::i()->select( '*', 'gd_compliance_pica_models', [ 'id=?', $id ] )->first(); }
			catch ( \Throwable ) { $row = null; }
		}

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_pica_f_pattern',        $row['pattern']        ?? '', TRUE,  [ 'maxLength' => 120 ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_pica_f_platform_group', $row['platform_group'] ?? '', FALSE, [ 'maxLength' => 40  ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_pica_f_citation',       $row['citation']       ?? '720 ILCS 5/24-1.9(a)(1)(J)', FALSE, [ 'maxLength' => 255 ] ) );
		$form->add( new \IPS\Helpers\Form\YesNo(  'gdcompliance_pica_f_enabled',        (int) ( $row['enabled'] ?? 1 ) ) );

		if ( $values = $form->values() )
		{
			$pattern = trim( (string) $values['gdcompliance_pica_f_pattern'] );
			$norm    = \IPS\gdcompliance\PicaModels::normalize( $pattern );

			if ( $pattern === '' || strlen( $norm ) < 3 )
			{
				$form->error = 'Pattern must normalize to at least 3 alphanumeric characters.';
			}
			else
			{
				$data = [
					'pattern'        => substr( $pattern, 0, 120 ),
					'pattern_norm'   => substr( $norm, 0, 120 ),
					'platform_group' => substr( (string) $values['gdcompliance_pica_f_platform_group'], 0, 40 ),
					'citation'       => substr( (string) $values['gdcompliance_pica_f_citation'], 0, 255 ),
					'enabled'        => (int) $values['gdcompliance_pica_f_enabled'] ? 1 : 0,
					'updated_at'     => time(),
				];
				try
				{
					if ( $row )
					{
						\IPS\Db::i()->update( 'gd_compliance_pica_models', $data, [ 'id=?', $id ] );
					}
					else
					{
						\IPS\Db::i()->insert( 'gd_compliance_pica_models', $data );
					}
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'pica save: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
				}

				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=picamodels' ),
					'saved'
				);
				return;
			}
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_pica_add' );
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
				$cur = (int) \IPS\Db::i()->select( 'enabled', 'gd_compliance_pica_models', [ 'id=?', $id ] )->first();
				\IPS\Db::i()->update( 'gd_compliance_pica_models', [ 'enabled' => $cur === 1 ? 0 : 1, 'updated_at' => time() ], [ 'id=?', $id ] );
			}
			catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=picamodels' ) );
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try { \IPS\Db::i()->delete( 'gd_compliance_pica_models', [ 'id=?', $id ] ); } catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=picamodels' ), 'deleted' );
	}

	protected function reseed(): void
	{
		\IPS\Session::i()->csrfCheck();

		$counts = [ 'inserted' => 0, 'skipped' => 0, 'failed' => 0 ];
		try { $counts = \IPS\gdcompliance\PicaModels::seedMissingModels(); }
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'pica reseed: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		\IPS\Session::i()->log( 'acplog__gdcompliance_pica_reseeded' );

		$url = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=picamodels' )
			->setQueryString( [
				'reseed_inserted' => (int) $counts['inserted'],
				'reseed_skipped'  => (int) $counts['skipped'],
				'reseed_failed'   => (int) $counts['failed'],
			] );

		\IPS\Output::i()->redirect( $url, 'saved' );
	}
}

class picamodels extends _picamodels {}
