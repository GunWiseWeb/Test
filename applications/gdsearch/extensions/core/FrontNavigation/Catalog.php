<?php
/**
 * Front Navigation Extension: Catalog (gdsearch)
 */

namespace IPS\gdsearch\extensions\core\FrontNavigation;

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

class Catalog extends FrontNavigationAbstract
{
	/**
	 * @var string Default icon
	 */
	public string $defaultIcon = '\f02d';

	/**
	 * Type title shown in the AdminCP Menu Manager "Add" list
	 */
	public static function typeTitle(): string
	{
		return Member::loggedIn()->language()->addToStack( 'frontnavigation_gdsearch_catalog' );
	}

	/**
	 * Can the current user access the content this links to?
	 */
	public function canAccessContent(): bool
	{
		try { return Member::loggedIn()->canAccessModule( Module::get( 'gdsearch', 'search' ) ); }
		catch ( \Throwable ) { return true; }
	}

	/**
	 * Menu title
	 */
	public function title(): string
	{
		return Member::loggedIn()->language()->addToStack( 'frontnavigation_gdsearch_catalog' );
	}

	/**
	 * Link
	 */
	public function link(): Url|string|null
	{
		return Url::internal( 'app=gdsearch&module=search&controller=results', 'front', 'gdsearch_results' );
	}

	/**
	 * Highlight whenever the visitor is anywhere in the gdsearch app
	 * (catalog listing, search results, or a product page).
	 */
	public function active(): bool
	{
		return !FrontNavigation::$clubTabActive
			and Dispatcher::i()->application
			and Dispatcher::i()->application->directory === 'gdsearch';
	}
}
