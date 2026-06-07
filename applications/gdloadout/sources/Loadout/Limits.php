<?php

namespace IPS\gdloadout\Loadout;

use IPS\Member;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Limits
{
	public static function forMember( ?Member $member = NULL ): array
	{
		$member = $member ?: Member::loggedIn();
		$groupId = (int) $member->member_group_id;

		$defaults = [ 'max_loadouts' => 0, 'max_slots' => 0 ];

		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_loadout_group_limits', [ 'group_id=?', $groupId ] )->first();
			return [
				'max_loadouts' => (int) $row['max_loadouts'],
				'max_slots'    => (int) $row['max_slots'],
			];
		}
		catch ( \Throwable )
		{
			return $defaults;
		}
	}

	public static function canCreateLoadout( ?Member $member = NULL ): bool
	{
		$member = $member ?: Member::loggedIn();
		$limits = static::forMember( $member );

		if ( $limits['max_loadouts'] === 0 )
		{
			return true;
		}

		try
		{
			$count = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_loadouts', [ 'member_id=?', (int) $member->member_id ] )->first();
			return $count < $limits['max_loadouts'];
		}
		catch ( \Throwable )
		{
			return true;
		}
	}

	public static function canAddSlot( int $loadoutId, ?Member $member = NULL ): bool
	{
		$limits = static::forMember( $member );

		if ( $limits['max_slots'] === 0 )
		{
			return true;
		}

		try
		{
			$count = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_loadout_items', [ 'loadout_id=?', $loadoutId ] )->first();
			return $count < $limits['max_slots'];
		}
		catch ( \Throwable )
		{
			return true;
		}
	}
}

class Limits extends _Limits {}
