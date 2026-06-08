<?php

namespace IPS\gdloadout\modules\admin\manage;

use IPS\Db;
use IPS\Member;
use IPS\Output;
use IPS\Theme;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _limits extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'gdloadout_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$limits = [];
		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadout_group_limits' ) as $row )
			{
				$groupName = 'Group #' . $row['group_id'];
				try { $groupName = \IPS\Member\Group::load( (int) $row['group_id'] )->name; } catch ( \Throwable ) {}
				$row['group_name'] = $groupName;
				$limits[] = $row;
			}
		}
		catch ( \Throwable ) {}

		Output::i()->title  = 'Loadout Group Limits';
		Output::i()->output = Theme::i()->getTemplate( 'manage', 'gdloadout', 'admin' )->limits( $limits );
	}

	protected function featured(): void
	{
		$featured = [];
		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadouts', [ 'featured=?', 1 ], 'featured_position ASC' ) as $row )
			{
				$row['owner_name'] = 'Unknown';
				try { $row['owner_name'] = Member::load( (int) $row['member_id'] )->name; } catch ( \Throwable ) {}
				$featured[] = $row;
			}
		}
		catch ( \Throwable ) {}

		Output::i()->title  = 'Featured Loadouts';
		Output::i()->output = Theme::i()->getTemplate( 'manage', 'gdloadout', 'admin' )->featured( $featured );
	}
}

class limits extends _limits {}
