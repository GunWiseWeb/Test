<?php

namespace IPS\gdloadout\extensions\core\UserMenu;

use IPS\Helpers\Menu\Link;
use IPS\Http\Url;
use IPS\Output\UI\MenuExtension;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _LoadoutLinks extends MenuExtension
{
	public function accountMenu( string $position = 'content' ): array
	{
		$return = [];
		if ( $position === 'content' )
		{
			$return[] = new Link(
				Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=mine', 'front', 'gdloadout_mine' ),
				'gdloadout_my_loadouts_title',
				icon: 'fa-solid fa-layer-group',
				identifier: 'gdMyLoadouts'
			);
		}
		return $return;
	}

	public function mobileMenu( string $position = 'content' ): array
	{
		return $this->accountMenu( $position );
	}
}

class LoadoutLinks extends _LoadoutLinks {}
