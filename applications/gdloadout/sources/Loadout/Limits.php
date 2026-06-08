<?php

namespace IPS\gdloadout\Loadout;

use IPS\Db;
use IPS\Member;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Limits
{
	public static function forMember( Member $member ): array
	{
		$groupId = (int) $member->member_group_id;
		try
		{
			$row = Db::i()->select( '*', 'gd_loadout_group_limits', [ 'group_id=?', $groupId ] )->first();
			return [
				'max_loadouts' => (int) $row['max_loadouts'],
				'max_slots'    => (int) $row['max_slots'],
			];
		}
		catch ( \Throwable )
		{
			return [ 'max_loadouts' => 0, 'max_slots' => 15 ];
		}
	}

	public static function canCreateLoadout( Member $member ): bool
	{
		$limits = static::forMember( $member );
		if ( $limits['max_loadouts'] <= 0 )
		{
			return true;
		}
		try
		{
			$count = (int) Db::i()->select( 'COUNT(*)', 'gd_loadouts', [ 'member_id=?', (int) $member->member_id ] )->first();
			return $count < $limits['max_loadouts'];
		}
		catch ( \Throwable )
		{
			return true;
		}
	}
}

class Limits extends _Limits {}
