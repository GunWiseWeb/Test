<?php

namespace IPS\gddealer\extensions\core\MemberSync;

use IPS\Extensions\MemberSyncAbstract;
use IPS\Member;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Dealer extends MemberSyncAbstract
{
	public function onProfileUpdate( Member $member, array $changes ): void
	{
		if ( !isset( $changes['member_group_id'] ) && !isset( $changes['mgroup_others'] ) )
		{
			return;
		}

		try
		{
			\IPS\gddealer\Dealer\Dealer::syncTierFromGroups( $member );
		}
		catch ( \Throwable ) {}
	}

	public function onMerge( Member $member, array $mergedMembers ): void
	{
		try
		{
			\IPS\gddealer\Dealer\Dealer::syncTierFromGroups( $member );
		}
		catch ( \Throwable ) {}
	}

	public function onCreateAccount( Member $member ): void
	{
	}

	public function onDelete( Member $member ): void
	{
	}
}

class Dealer extends _Dealer {}
