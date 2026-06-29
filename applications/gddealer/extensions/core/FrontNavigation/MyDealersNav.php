<?php
/**
 * @brief  GD Dealer Manager — FrontNavigation extension for "Dealers I Follow"
 *
 * Adds an optional menu item that links to the logged-in member's
 * "My Dealers" profile tab. Visible to any logged-in member.
 */

namespace IPS\gddealer\extensions\core\FrontNavigation;

use IPS\Http\Url;
use IPS\Member;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class MyDealersNav extends \IPS\core\FrontNavigation\FrontNavigationAbstract
{
	public static function typeTitle(): string
	{
		return Member::loggedIn()->language()->addToStack( 'gddealer_my_dealers_link' );
	}

	public static function configuration( array $existingConfiguration, ?int $id = NULL ): array
	{
		return [];
	}

	public function title(): string
	{
		return Member::loggedIn()->language()->addToStack( 'gddealer_my_dealers_link' );
	}

	public function link(): Url|string|null
	{
		$memberId = (int) Member::loggedIn()->member_id;
		if ( $memberId <= 0 )
		{
			return null;
		}
		return Url::internal(
			'app=core&module=members&controller=profile&id=' . $memberId . '&tab=node_gddealer_MyDealers',
			'front',
			'profile'
		);
	}

	public function active(): bool
	{
		return false;
	}

	public function canView(): bool
	{
		return (bool) Member::loggedIn()->member_id;
	}
}
