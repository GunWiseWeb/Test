<?php
/**
 * Front Navigation Extension: Loadouts (gdloadout)
 */

namespace IPS\gdloadout\extensions\core\FrontNavigation;

use IPS\Application\Module;
use IPS\core\FrontNavigation;
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
		return Member::loggedIn()->language()->addToStack( 'frontnavigation_gdloadout_loadouts' );
	}

	public function canAccessContent(): bool
	{
		try { return Member::loggedIn()->canAccessModule( Module::get( 'gdloadout', 'loadouts' ) ); }
		catch ( \Throwable ) { return true; }
	}

	public function title(): string
	{
		return Member::loggedIn()->language()->addToStack( 'frontnavigation_gdloadout_loadouts' );
	}

	public function link(): Url|string|null
	{
		return Url::internal( 'app=gdloadout&module=loadouts&controller=hub', 'front', 'gdloadout_hub' );
	}

	public function active(): bool
	{
		return !FrontNavigation::$clubTabActive
			and Dispatcher::i()->application
			and Dispatcher::i()->application->directory === 'gdloadout';
	}
}
