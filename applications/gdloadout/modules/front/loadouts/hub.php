<?php

namespace IPS\gdloadout\modules\front\loadouts;

use IPS\Db;
use IPS\Http\Url;
use IPS\Member;
use IPS\Output;
use IPS\Request;
use IPS\Theme;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _hub extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		parent::execute();
	}

	protected function manage(): void
	{
		$loadouts = [];
		$member = Member::loggedIn();

		try
		{
			$rows = Db::i()->select( '*', 'gd_loadouts', [ 'visibility=?', 'public' ], 'upvotes DESC', [ 0, 24 ] );
			foreach ( $rows as $row )
			{
				$loadouts[] = $row;
			}
		}
		catch ( \Throwable ) {}

		$canCreate = $member->member_id ? \IPS\gdloadout\Loadout\Limits::canCreateLoadout( $member ) : false;
		$builderUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder', 'front', 'gdloadout_builder' );

		Output::i()->title = Member::loggedIn()->language()->addToStack( 'gdloadout_hub_title' );
		Output::i()->output = Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->hub( $loadouts, $canCreate, $builderUrl );
	}

	protected function view(): void
	{
		$username = Request::i()->username ?? '';
		$slug     = Request::i()->slug ?? '';

		$loadout = NULL;

		try
		{
			$loadout = Db::i()->select( '*', 'gd_loadouts', [
				'slug=? AND member_id IN ( SELECT member_id FROM core_members WHERE name=? )',
				$slug, $username
			] )->first();
		}
		catch ( \Throwable ) {}

		if ( !$loadout )
		{
			Output::i()->error( 'gdloadout_err_not_found', '2GL01/1', 404 );
			return;
		}

		$member = Member::loggedIn();

		if ( $loadout['visibility'] === 'private' && (int) $loadout['member_id'] !== (int) $member->member_id )
		{
			Output::i()->error( 'gdloadout_err_no_permission', '2GL01/2', 403 );
			return;
		}

		$items = [];
		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadout_items', [ 'loadout_id=?', (int) $loadout['id'] ], 'sort_order ASC' ) as $item )
			{
				$items[] = $item;
			}
		}
		catch ( \Throwable ) {}

		Db::i()->update( 'gd_loadouts', 'view_count=view_count+1', [ 'id=?', (int) $loadout['id'] ] );

		Output::i()->title = htmlspecialchars( $loadout['name'] ) . ' — Loadout';
		Output::i()->output = Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->hub( [ $loadout ], false, '' );
	}
}

class hub extends _hub {}
