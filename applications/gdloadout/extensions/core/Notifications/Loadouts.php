<?php

namespace IPS\gdloadout\extensions\core\Notifications;

use IPS\Http\Url;
use IPS\Member;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Loadouts
{
	public function configurationOptions( \IPS\Member $member = NULL ): array
	{
		return [
			'gdloadout_notifications' => [
				'type'    => 'standard',
				'default' => [ 'inline' ],
				'options' => [
					'loadout_updated',
					'loadout_upvoted',
					'loadout_followed',
				],
			],
		];
	}

	public function parse_loadout_updated( \IPS\Notification\Inline $notification, $extra ): array
	{
		$loadoutName = $extra['loadout_name'] ?? '';
		$username    = $extra['username'] ?? '';
		$slug        = $extra['slug'] ?? '';
		$url = (string) Url::internal(
			'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $username ) . '&slug=' . urlencode( $slug ),
			'front',
			'gdloadout_view'
		);
		return [
			'title'   => \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_notify_loadout_updated' ),
			'content' => htmlspecialchars( $loadoutName ) . ' was updated',
			'url'     => $url,
		];
	}

	public function parse_loadout_upvoted( \IPS\Notification\Inline $notification, $extra ): array
	{
		$loadoutName = $extra['loadout_name'] ?? '';
		$voterName   = $extra['voter_name'] ?? '';
		$username    = $extra['username'] ?? '';
		$slug        = $extra['slug'] ?? '';
		$url = (string) Url::internal(
			'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $username ) . '&slug=' . urlencode( $slug ),
			'front',
			'gdloadout_view'
		);
		return [
			'title'   => \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_notify_loadout_upvoted' ),
			'content' => htmlspecialchars( $voterName ) . ' upvoted your loadout "' . htmlspecialchars( $loadoutName ) . '"',
			'url'     => $url,
		];
	}

	public function parse_loadout_followed( \IPS\Notification\Inline $notification, $extra ): array
	{
		$loadoutName  = $extra['loadout_name'] ?? '';
		$followerName = $extra['follower_name'] ?? '';
		$username     = $extra['username'] ?? '';
		$slug         = $extra['slug'] ?? '';
		$url = (string) Url::internal(
			'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $username ) . '&slug=' . urlencode( $slug ),
			'front',
			'gdloadout_view'
		);
		return [
			'title'   => \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_notify_loadout_followed' ),
			'content' => htmlspecialchars( $followerName ) . ' is now following "' . htmlspecialchars( $loadoutName ) . '"',
			'url'     => $url,
		];
	}
}

class Loadouts extends _Loadouts {}
