<?php

namespace IPS\gdloadout\modules\admin\manage;

use IPS\Db;
use IPS\Member;
use IPS\Output;
use IPS\Request;
use IPS\Session;
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
		\IPS\Dispatcher::i()->checkAcpPermission( 'limits_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$groups = [];
		try
		{
			foreach ( Db::i()->select( 'g_id, g_name', 'core_groups', NULL, 'g_id ASC' ) as $g )
			{
				$groups[ (int) $g['g_id'] ] = $g['g_name'];
			}
		}
		catch ( \Throwable ) {}

		$limits = [];
		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadout_group_limits' ) as $row )
			{
				$limits[ (int) $row['group_id'] ] = $row;
			}
		}
		catch ( \Throwable ) {}

		$csrfKey = Session::i()->csrfKey;
		$saveUrl = (string) \IPS\Http\Url::internal( 'app=gdloadout&module=manage&controller=limits&do=save' );

		Output::i()->title  = Member::loggedIn()->language()->addToStack( 'gdloadout_limits_title' );
		Output::i()->output = Theme::i()->getTemplate( 'manage', 'gdloadout', 'admin' )->limits( $groups, $limits, $csrfKey, $saveUrl );
	}

	protected function save(): void
	{
		Session::i()->csrfCheck();

		$groupLimits = Request::i()->group_limits ?? [];

		if ( \is_array( $groupLimits ) )
		{
			foreach ( $groupLimits as $groupId => $vals )
			{
				$groupId     = (int) $groupId;
				$maxLoadouts = max( 0, (int) ( $vals['max_loadouts'] ?? 0 ) );
				$maxSlots    = max( 0, (int) ( $vals['max_slots'] ?? 0 ) );

				try
				{
					Db::i()->replace( 'gd_loadout_group_limits', [
						'group_id'     => $groupId,
						'max_loadouts' => $maxLoadouts,
						'max_slots'    => $maxSlots,
					] );
				}
				catch ( \Throwable ) {}
			}
		}

		Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdloadout&module=manage&controller=limits' ),
			'gdloadout_limits_saved'
		);
	}
}

class limits extends _limits {}
