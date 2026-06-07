<?php
namespace IPS\gdloadout\Loadout;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

class Limits
{
	protected static array $cache = [];

	public static function forMember( \IPS\Member $member ): array
	{
		$mid = (int) $member->member_id;
		if ( isset( static::$cache[ $mid ] ) )
		{
			return static::$cache[ $mid ];
		}

		$groupIds = array_filter( array_map( 'intval', explode( ',', $member->member_group_id . ',' . $member->mgroup_others ) ) );
		if ( empty( $groupIds ) )
		{
			$groupIds = [ (int) $member->member_group_id ];
		}

		$maxSlots    = 15;
		$maxLoadouts = 0;
		$found       = false;

		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_loadout_group_limits', \IPS\Db::i()->in( 'group_id', $groupIds ) ) as $row )
			{
				$found = true;
				$s = (int) $row['max_slots'];
				$l = (int) $row['max_loadouts'];

				if ( $s === 0 || ( $s > $maxSlots && $maxSlots !== 0 ) )
				{
					$maxSlots = $s;
				}
				if ( $l === 0 || ( $l > $maxLoadouts && $maxLoadouts !== 0 ) )
				{
					$maxLoadouts = $l;
				}
			}
		}
		catch ( \Throwable ) {}

		if ( !$found )
		{
			$maxSlots    = 15;
			$maxLoadouts = 0;
		}

		static::$cache[ $mid ] = [ 'max_slots' => $maxSlots, 'max_loadouts' => $maxLoadouts ];
		return static::$cache[ $mid ];
	}

	public static function isVip( \IPS\Member $member ): bool
	{
		return false;
	}
}
