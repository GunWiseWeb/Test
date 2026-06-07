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
	protected static array $cache = [];

	public static function forMember( ?Member $member = NULL ): array
	{
		$member = $member ?: Member::loggedIn();
		$memberId = (int) $member->member_id;

		if ( isset( static::$cache[ $memberId ] ) )
		{
			return static::$cache[ $memberId ];
		}

		$groupIds = [ (int) $member->member_group_id ];
		$secondary = $member->mgroup_others ?? '';
		if ( $secondary )
		{
			foreach ( explode( ',', $secondary ) as $gid )
			{
				$gid = (int) trim( $gid );
				if ( $gid > 0 )
				{
					$groupIds[] = $gid;
				}
			}
		}
		$groupIds = array_unique( $groupIds );

		$bestLoadouts = 0;
		$bestSlots    = 15;
		$foundAny     = false;

		foreach ( $groupIds as $gid )
		{
			try
			{
				$row = \IPS\Db::i()->select( '*', 'gd_loadout_group_limits', [ 'group_id=?', $gid ] )->first();
				$foundAny = true;

				$ml = (int) $row['max_loadouts'];
				$ms = (int) $row['max_slots'];

				if ( $ml === 0 )
				{
					$bestLoadouts = 0;
				}
				elseif ( $bestLoadouts !== 0 && $ml > $bestLoadouts )
				{
					$bestLoadouts = $ml;
				}
				elseif ( $bestLoadouts !== 0 )
				{
					// keep current (higher)
				}
				else
				{
					// bestLoadouts is already 0 (unlimited)
				}

				if ( $ms === 0 )
				{
					$bestSlots = 0;
				}
				elseif ( $bestSlots !== 0 && ( $ms > $bestSlots ) )
				{
					$bestSlots = $ms;
				}
			}
			catch ( \Throwable ) {}
		}

		if ( !$foundAny )
		{
			$bestLoadouts = 0;
			$bestSlots    = 15;
		}

		$result = [ 'max_loadouts' => $bestLoadouts, 'max_slots' => $bestSlots ];
		static::$cache[ $memberId ] = $result;

		return $result;
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
