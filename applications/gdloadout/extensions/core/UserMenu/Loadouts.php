<?php

namespace IPS\gdloadout\extensions\core\UserMenu;

use IPS\Helpers\Menu\Link;
use IPS\Http\Url;
use IPS\Member;
use IPS\Output\UI\MenuExtension;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Loadouts extends MenuExtension
{
	public function accountMenu( string $position = 'content' ): array
	{
		$r = [];
		if ( $position === 'content' && Member::loggedIn()->member_id )
		{
			$r[] = new Link(
				Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=mine', 'front', 'gdloadout_mine' ),
				'gdloadout_my_loadouts',
				icon: 'fa-solid fa-layer-group',
				identifier: 'gdLoadouts'
			);
		}
		return $r;
	}

	public function mobileMenu( string $position = 'content' ): array
	{
		$r = [];
		if ( $position === 'content' && Member::loggedIn()->member_id )
		{
			$r[] = new Link(
				Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=mine', 'front', 'gdloadout_mine' ),
				'gdloadout_my_loadouts',
				'',
				icon: 'fa-solid fa-layer-group',
				identifier: 'gdLoadouts'
			);
		}
		return $r;
	}
}

class Loadouts extends _Loadouts {}
