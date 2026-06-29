<?php
/**
 * @brief  GD Bills — FrontNavigation extension
 *
 * Optional menu item that links to the public Firearms Bill Tracker page.
 */

namespace IPS\gdbills\extensions\core\FrontNavigation;

use IPS\Http\Url;
use IPS\Member;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class BillsNav extends \IPS\core\FrontNavigation\FrontNavigationAbstract
{
	public static function typeTitle(): string
	{
		return Member::loggedIn()->language()->addToStack( 'frontnavigation_gdbills_bills' );
	}

	public static function configuration( array $existingConfiguration, ?int $id = NULL ): array
	{
		return [];
	}

	public function title(): string
	{
		return Member::loggedIn()->language()->addToStack( 'frontnavigation_gdbills_bills' );
	}

	public function link(): Url|string|null
	{
		return Url::internal( 'app=gdbills&module=bills&controller=bills', 'front', 'gdbills_page' );
	}

	public function active(): bool
	{
		try
		{
			return \IPS\Dispatcher::hasInstance()
				&& \IPS\Dispatcher::i()->application
				&& \IPS\Dispatcher::i()->application->directory === 'gdbills';
		}
		catch ( \Throwable )
		{
			return false;
		}
	}

	public function canView(): bool
	{
		return true;
	}
}
