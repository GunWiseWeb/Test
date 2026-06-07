<?php

namespace IPS\gdloadout\extensions\core\FrontNavigation;

use IPS\core\FrontNavigation\FrontNavigationAbstract;
use IPS\Dispatcher;
use IPS\Http\Url;
use IPS\Member;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class Loadouts extends FrontNavigationAbstract
{
	public string $defaultIcon = '\f5fd';

	public static function typeTitle(): string
	{
		return Member::loggedIn()->language()->addToStack( 'frontnavigation_loadouts' );
	}

	public static function configuration( array $existingConfiguration, ?int $id = NULL ): array
	{
		return [];
	}

	public function title(): string
	{
		return Member::loggedIn()->language()->addToStack( 'frontnavigation_loadouts' );
	}

	public function link(): Url|string|null
	{
		return Url::internal( 'app=gdloadout&module=loadouts&controller=hub', 'front', 'gdloadout_hub' );
	}

	public function active(): bool
	{
		try
		{
			return \IPS\Dispatcher::hasInstance()
				&& \IPS\Dispatcher::i()->application
				&& \IPS\Dispatcher::i()->application->directory === 'gdloadout';
		}
		catch ( \Exception )
		{
			return false;
		}
	}

	public function canAccessContent(): bool
	{
		return TRUE;
	}
}
