<?php
namespace IPS\gdrebates\modules\admin\rebates;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _logos extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'logos_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$table = new \IPS\Helpers\Table\Db( 'gd_rebate_logos', \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=logos' ) );
		$table->langPrefix = 'gdrebates_logo_';
		$table->include = [ 'manufacturer', 'logo_url' ];
		$table->sortBy = $table->sortBy ?: 'manufacturer';
		$table->sortDirection = $table->sortDirection ?: 'asc';

		$table->parsers = [
			'logo_url' => function( $val ) { $u = htmlspecialchars( (string) $val, ENT_QUOTES, 'UTF-8' ); return $u !== '' ? "<img src='{$u}' style='max-height:28px' alt=''>" : '—'; },
		];

		$table->rootButtons = [
			'add' => [ 'icon' => 'plus', 'title' => 'gdrebates_logo_add', 'link' => \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=logos&do=form' ) ],
		];

		$table->rowButtons = function( $row ) {
			return [
				'edit'   => [ 'icon' => 'pencil', 'title' => 'edit',   'link' => \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=logos&do=form&id=' . $row['logo_id'] ) ],
				'delete' => [ 'icon' => 'times-circle', 'title' => 'delete', 'link' => \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=logos&do=delete&id=' . $row['logo_id'] )->csrf(), 'data' => [ 'delete' => '' ] ],
			];
		};

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'menu__gdrebates_rebates_logos' );
		\IPS\Output::i()->output = (string) $table;
	}

	protected function form(): void
	{
		$id  = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = NULL;

		if ( $id )
		{
			try { $row = \IPS\Db::i()->select( '*', 'gd_rebate_logos', [ 'logo_id=?', $id ] )->first(); }
			catch ( \UnderflowException $e ) { \IPS\Output::i()->error( 'node_error', '2GR300/1', 404 ); }
		}

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text( 'gdrebates_logo_manufacturer', $row['manufacturer'] ?? '', TRUE ) );
		$form->add( new \IPS\Helpers\Form\Url( 'gdrebates_logo_url', $row['logo_url'] ?? '', TRUE ) );

		if ( $values = $form->values() )
		{
			$save = [
				'manufacturer' => (string) $values['gdrebates_logo_manufacturer'],
				'logo_url'     => (string) $values['gdrebates_logo_url'],
				'updated'      => time(),
			];

			if ( $id )
			{
				\IPS\Db::i()->update( 'gd_rebate_logos', $save, [ 'logo_id=?', $id ] );
			}
			else
			{
				$save['created'] = time();
				\IPS\Db::i()->insert( 'gd_rebate_logos', $save );
			}

			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=logos' ), 'saved' );
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( $id ? 'gdrebates_logo_edit' : 'gdrebates_logo_add' );
		\IPS\Output::i()->output = (string) $form;
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();

		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id )
		{
			\IPS\Db::i()->delete( 'gd_rebate_logos', [ 'logo_id=?', $id ] );
		}

		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=logos' ), 'deleted' );
	}
}
class logos extends _logos {}
