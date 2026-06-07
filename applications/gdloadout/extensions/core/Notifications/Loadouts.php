<?php

namespace IPS\gdloadout\extensions\core\Notifications;

use IPS\Member;
use IPS\Notification\Inline;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Loadouts extends \IPS\Extensions\NotificationsAbstract
{
	public static function configurationOptions( ?Member $member = NULL ): array
	{
		return [
			'gdloadout_notifications' => [
				'type'              => 'standard',
				'notificationTypes' => [ 'loadout_updated', 'loadout_upvoted', 'loadout_followed' ],
				'title'             => 'notifications__gdloadout_Loadouts',
				'showTitle'         => TRUE,
				'description'       => 'notifications__gdloadout_Loadouts_desc',
				'default'           => [ 'inline' ],
				'disabled'          => [],
			],
		];
	}

	public function parse_loadout_updated( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra      = $notification->extra ?: [];
		$loadoutName = (string) ( $extra['loadout_name'] ?? 'a loadout' );
		$authorName  = (string) ( $extra['author_name'] ?? 'Someone' );
		$username    = (string) ( $extra['username'] ?? '' );
		$slug        = (string) ( $extra['slug'] ?? '' );

		$url = ( $username !== '' && $slug !== '' )
			? \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $username ) . '&slug=' . urlencode( $slug ), 'front', 'gdloadout_view' )
			: \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=hub', 'front', 'gdloadout_hub' );

		return [
			'title'   => $authorName . ' updated the loadout "' . $loadoutName . '"',
			'url'     => $url,
			'content' => '',
			'author'  => NULL,
		];
	}

	public function parse_loadout_upvoted( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra      = $notification->extra ?: [];
		$loadoutName = (string) ( $extra['loadout_name'] ?? 'your loadout' );
		$voterName   = (string) ( $extra['voter_name'] ?? 'Someone' );
		$username    = (string) ( $extra['username'] ?? '' );
		$slug        = (string) ( $extra['slug'] ?? '' );

		$url = ( $username !== '' && $slug !== '' )
			? \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $username ) . '&slug=' . urlencode( $slug ), 'front', 'gdloadout_view' )
			: \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=hub', 'front', 'gdloadout_hub' );

		return [
			'title'   => $voterName . ' upvoted your loadout "' . $loadoutName . '"',
			'url'     => $url,
			'content' => '',
			'author'  => NULL,
		];
	}

	public function parse_loadout_followed( Inline $notification, bool $htmlEscape = TRUE ): array
	{
		$extra      = $notification->extra ?: [];
		$loadoutName = (string) ( $extra['loadout_name'] ?? 'your loadout' );
		$followerName = (string) ( $extra['follower_name'] ?? 'Someone' );
		$username     = (string) ( $extra['username'] ?? '' );
		$slug         = (string) ( $extra['slug'] ?? '' );

		$url = ( $username !== '' && $slug !== '' )
			? \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $username ) . '&slug=' . urlencode( $slug ), 'front', 'gdloadout_view' )
			: \IPS\Http\Url::internal( 'app=gdloadout&module=loadouts&controller=hub', 'front', 'gdloadout_hub' );

		return [
			'title'   => $followerName . ' is now following your loadout "' . $loadoutName . '"',
			'url'     => $url,
			'content' => '',
			'author'  => NULL,
		];
	}
}

class Loadouts extends _Loadouts {}
