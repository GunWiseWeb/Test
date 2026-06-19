<?php
namespace IPS\gdrebates\modules\admin\rebates;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _sources extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'sources_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$table = new \IPS\Helpers\Table\Db( 'gd_rebate_sources', \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=sources' ) );
		$table->langPrefix = 'gdrebates_src_';
		$table->include = [ 'manufacturer', 'url', 'source_type', 'is_active', 'last_parsed_at', 'last_status' ];
		$table->sortBy = $table->sortBy ?: 'manufacturer';
		$table->sortDirection = $table->sortDirection ?: 'asc';

		$table->parsers = [
			'is_active'      => function( $val ) { return $val ? \IPS\Member::loggedIn()->language()->addToStack( 'yes' ) : \IPS\Member::loggedIn()->language()->addToStack( 'no' ); },
			'last_parsed_at' => function( $val ) { return $val ? (string) \IPS\DateTime::ts( $val ) : '—'; },
			'url'            => function( $val ) { $u = htmlspecialchars( (string) $val, ENT_QUOTES, 'UTF-8' ); $s = htmlspecialchars( mb_strimwidth( (string) $val, 0, 60, '…' ), ENT_QUOTES, 'UTF-8' ); return "<a href='{$u}' target='_blank' rel='noopener'>{$s}</a>"; },
		];

		$table->rootButtons = [
			'add' => [ 'icon' => 'plus', 'title' => 'gdrebates_src_add', 'link' => \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=sources&do=form' ) ],
		];

		$table->rowButtons = function( $row ) {
			return [
				'parse'  => [ 'icon' => 'refresh', 'title' => 'gdrebates_src_parse', 'link' => \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=sources&do=parse&id=' . $row['source_id'] )->csrf() ],
				'edit'   => [ 'icon' => 'pencil', 'title' => 'edit',   'link' => \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=sources&do=form&id=' . $row['source_id'] ) ],
				'delete' => [ 'icon' => 'times-circle', 'title' => 'delete', 'link' => \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=sources&do=delete&id=' . $row['source_id'] )->csrf(), 'data' => [ 'delete' => '' ] ],
			];
		};

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'menu__gdrebates_rebates_sources' );
		\IPS\Output::i()->output = (string) $table;
	}

	protected function form(): void
	{
		$id  = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = NULL;

		if ( $id )
		{
			try { $row = \IPS\Db::i()->select( '*', 'gd_rebate_sources', [ 'source_id=?', $id ] )->first(); }
			catch ( \UnderflowException $e ) { \IPS\Output::i()->error( 'node_error', '2GR100/1', 404 ); }
		}

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text( 'gdrebates_src_manufacturer', $row['manufacturer'] ?? '', TRUE ) );
		$form->add( new \IPS\Helpers\Form\Url( 'gdrebates_src_url', $row['url'] ?? '', TRUE ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdrebates_src_source_type', $row['source_type'] ?? 'single', TRUE, [ 'options' => [ 'single' => 'gdrebates_src_type_single', 'index' => 'gdrebates_src_type_index' ] ] ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gdrebates_src_is_active', $row['is_active'] ?? 1, FALSE ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdrebates_src_model_override', $row['model_override'] ?? '', FALSE, [
			'options' => [
				''                          => 'Use global default',
				'claude-haiku-4-5-20251001' => 'Haiku 4.5 (fast / cheap)',
				'claude-sonnet-4-6'         => 'Sonnet 4.6 (accurate)',
			],
		] ) );

		if ( $values = $form->values() )
		{
			$save = [
				'manufacturer'   => (string) $values['gdrebates_src_manufacturer'],
				'url'            => (string) $values['gdrebates_src_url'],
				'source_type'    => (string) $values['gdrebates_src_source_type'],
				'is_active'      => $values['gdrebates_src_is_active'] ? 1 : 0,
				'model_override' => (string) $values['gdrebates_src_model_override'],
				'updated'        => time(),
			];

			if ( $id )
			{
				\IPS\Db::i()->update( 'gd_rebate_sources', $save, [ 'source_id=?', $id ] );
			}
			else
			{
				$save['created'] = time();
				\IPS\Db::i()->insert( 'gd_rebate_sources', $save );
			}

			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=sources' ), 'saved' );
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( $id ? 'gdrebates_src_edit' : 'gdrebates_src_add' );
		\IPS\Output::i()->output = (string) $form;
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();

		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id )
		{
			\IPS\Db::i()->delete( 'gd_rebate_sources', [ 'source_id=?', $id ] );
		}

		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=sources' ), 'deleted' );
	}

	protected function parse(): void
	{
		\IPS\Session::i()->csrfCheck();

		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( !$id )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=sources' ) );
			return;
		}

		try
		{
			$source = \IPS\Db::i()->select( '*', 'gd_rebate_sources', [ 'source_id=?', $id ] )->first();
		}
		catch ( \UnderflowException $e )
		{
			\IPS\Output::i()->error( 'node_error', '2GR100/2', 404 );
			return;
		}

		$parser = new \IPS\gdrebates\sources\Parser( $source );
		$result = $parser->run();

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=sources' ),
			'gdrebates_src_parse_done'
		);
	}
}
class sources extends _sources {}
