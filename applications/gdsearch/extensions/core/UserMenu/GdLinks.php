<?php
/**
 * @brief  gdsearch UserMenu extension: adds "My Wishlist" / "My Price Alerts" to the account dropdown.
 */
namespace IPS\gdsearch\extensions\core\UserMenu;

use IPS\Helpers\Menu\Link;
use IPS\Http\Url;
use IPS\Output\UI\MenuExtension;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _GdLinks extends MenuExtension
{
	/** Adds links to the avatar account dropdown. */
	public function accountMenu( string $position = 'content' ): array
	{
		$return = [];
		if ( $position === 'content' )
		{
			$return[] = new Link(
				Url::internal( 'app=gdsearch&module=search&controller=results&do=myWishlist', 'front' ),
				'gdsearch_my_wishlist_title',
				icon: 'fa-solid fa-heart',
				identifier: 'gdWishlist'
			);
			$return[] = new Link(
				Url::internal( 'app=gdsearch&module=search&controller=results&do=myAlerts', 'front' ),
				'gdsearch_my_alerts_title',
				icon: 'fa-solid fa-bell',
				identifier: 'gdAlerts'
			);
		}
		return $return;
	}

	/** Mirror into the mobile menu so the links are reachable on phones too. */
	public function mobileMenu( string $position = 'content' ): array
	{
		return $this->accountMenu( $position );
	}
}

class GdLinks extends _GdLinks {}
