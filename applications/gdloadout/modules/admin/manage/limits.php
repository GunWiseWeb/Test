<?php
namespace IPS\gdloadout\modules\admin\manage;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

class _limits extends \IPS\Dispatcher\Controller
{
	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'limits_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$form = new \IPS\Helpers\Form;

		$existing = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_loadout_group_limits' ) as $row )
			{
				$existing[ (int) $row['group_id'] ] = $row;
			}
		}
		catch ( \Throwable ) {}

		$groups = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'g_id, g_name', 'core_groups' ) as $g )
			{
				$groups[] = $g;
			}
		}
		catch ( \Throwable ) {}

		foreach ( $groups as $g )
		{
			$gid  = (int) $g['g_id'];
			$name = $g['g_name'];
			$s = isset( $existing[ $gid ] ) ? (int) $existing[ $gid ]['max_slots']    : 15;
			$l = isset( $existing[ $gid ] ) ? (int) $existing[ $gid ]['max_loadouts'] : 0;

			$form->addHeader( $name );
			$form->add( new \IPS\Helpers\Form\Number( 'slots_' . $gid, $s, FALSE, [ 'min' => 0 ] ) );
			$form->add( new \IPS\Helpers\Form\Number( 'loadouts_' . $gid, $l, FALSE, [ 'min' => 0 ] ) );
		}

		if ( $values = $form->values() )
		{
			foreach ( $groups as $g )
			{
				$gid = (int) $g['g_id'];
				try
				{
					\IPS\Db::i()->replace( 'gd_loadout_group_limits', [
						'group_id'      => $gid,
						'max_slots'     => (int) ( $values[ 'slots_' . $gid ] ?? 15 ),
						'max_loadouts'  => (int) ( $values[ 'loadouts_' . $gid ] ?? 0 ),
					] );
				}
				catch ( \Throwable ) {}
			}

			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdloadout&module=manage&controller=limits' ), 'saved' );
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_limits_title' );
		\IPS\Output::i()->output = (string) $form;
	}
}
class limits extends _limits {}
