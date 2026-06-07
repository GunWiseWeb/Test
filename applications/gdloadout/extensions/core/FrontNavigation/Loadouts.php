<?php
namespace IPS\gdloadout\extensions\core\FrontNavigation;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _Loadouts extends \IPS\core\FrontNavigation\FrontNavigationAbstract
{
	public static function typeTitle(): string
	{
		return \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_nav_loadouts' );
	}
	public function title(): string
	{
		return \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_nav_loadouts' );
	}
	public function url(): \IPS\Http\Url
	{
		return \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=hub', 'front', 'gdloadout_hub' );
	}
	public function active(): bool
	{
		return ( \IPS\Dispatcher::i()->application and \IPS\Dispatcher::i()->application->directory === 'gdloadout' );
	}
	public function canView( $member = NULL ): bool { return TRUE; }
	public function children(): array { return []; }
}
class Loadouts extends _Loadouts {}
