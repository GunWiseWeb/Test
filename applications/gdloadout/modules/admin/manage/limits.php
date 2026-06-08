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
		\IPS\Dispatcher::i()->checkAcpPermission( 'limits_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$groups = [];
		try { foreach ( \IPS\Member\Group::groups( TRUE, FALSE ) as $g ) { $groups[ (int) $g->g_id ] = (string) $g->name; } } catch ( \Throwable ) {}

		$limits = [];
		try { foreach ( Db::i()->select( '*', 'gd_loadout_group_limits' ) as $row ) { $limits[ (int) $row['group_id'] ] = $row; } } catch ( \Throwable ) {}

		$csrfKey = \IPS\Session::i()->csrfKey;
		$saveUrl = (string) \IPS\Http\Url::internal( 'app=gdloadout&module=manage&controller=limits&do=save' );

		Output::i()->title  = Member::loggedIn()->language()->addToStack( 'gdloadout_limits_title' );
		Output::i()->output = Theme::i()->getTemplate( 'manage', 'gdloadout', 'admin' )->limits( $groups, $limits, $csrfKey, $saveUrl );
	}

	protected function save(): void
	{
		\IPS\Session::i()->csrfCheck();

		$groupLimits = \IPS\Request::i()->group_limits ?? [];
		if ( \is_array( $groupLimits ) )
		{
			foreach ( $groupLimits as $groupId => $vals )
			{
				try
				{
					Db::i()->replace( 'gd_loadout_group_limits', [
						'group_id'     => (int) $groupId,
						'max_loadouts' => max( 0, (int) ( $vals['max_loadouts'] ?? 0 ) ),
						'max_slots'    => max( 0, (int) ( $vals['max_slots'] ?? 0 ) ),
					] );
				}
				catch ( \Throwable ) {}
			}
		}

		Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdloadout&module=manage&controller=limits' ), 'gdloadout_limits_saved' );
	}
}

class limits extends _limits {}
