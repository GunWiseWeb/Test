<?php

namespace IPS\gdloadout\modules\admin\manage;

use IPS\Db;
use IPS\Http\Url;
use IPS\Member;
use IPS\Output;
use IPS\Request;
use IPS\Session;
use IPS\Theme;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _featured extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'featured_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$loadouts = [];
		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadouts', [ 'visibility=?', 'public' ], 'featured DESC, featured_position ASC, upvotes DESC' ) as $row )
			{
				$row['owner_name'] = '';
				try { $row['owner_name'] = Member::load( (int) $row['member_id'] )->name; } catch ( \Throwable ) {}
				$action = $row['featured'] ? 'unfeature' : 'feature';
				$row['toggle_url'] = (string) Url::internal( 'app=gdloadout&module=manage&controller=featured&do=toggle&id=' . (int) $row['id'] . '&action=' . $action );
				$loadouts[] = $row;
			}
		}
		catch ( \Throwable ) {}

		$csrfKey = Session::i()->csrfKey;

		Output::i()->title  = Member::loggedIn()->language()->addToStack( 'gdloadout_featured_title' );
		Output::i()->output = Theme::i()->getTemplate( 'manage', 'gdloadout', 'admin' )->featured( $loadouts, $csrfKey );
	}

	protected function toggle(): void
	{
		Session::i()->csrfCheck();

		$id     = (int) ( Request::i()->id ?? 0 );
		$action = Request::i()->action ?? '';

		if ( $action === 'feature' )
		{
			$maxPos = 0;
			try { $maxPos = (int) Db::i()->select( 'MAX(featured_position)', 'gd_loadouts', [ 'featured=1' ] )->first(); } catch ( \Throwable ) {}
			Db::i()->update( 'gd_loadouts', [ 'featured' => 1, 'featured_position' => $maxPos + 1 ], [ 'id=?', $id ] );
		}
		else
		{
			Db::i()->update( 'gd_loadouts', [ 'featured' => 0, 'featured_position' => 0 ], [ 'id=?', $id ] );
		}

		Output::i()->redirect( Url::internal( 'app=gdloadout&module=manage&controller=featured' ) );
	}
}

class featured extends _featured {}
