<?php

namespace IPS\gdloadout\modules\admin\manage;

use IPS\Db;
use IPS\Helpers\Form;
use IPS\Output;
use IPS\Request;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _settings extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'settings_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$form = new Form;

		$forumsEnabled = false;
		try { $forumsEnabled = \IPS\Application::appIsEnabled( 'forums' ); } catch ( \Throwable ) {}

		if ( $forumsEnabled )
		{
			$currentForum = NULL;
			$forumId = 0;
			try { $forumId = (int) \IPS\Settings::i()->gdloadout_share_forum; } catch ( \Throwable ) {}

			if ( $forumId > 0 )
			{
				try { $currentForum = \IPS\forums\Forum::load( $forumId ); } catch ( \Throwable ) {}
			}

			$form->add( new \IPS\Helpers\Form\Node(
				'gdloadout_share_forum',
				$currentForum,
				FALSE,
				[
					'class'           => 'IPS\\forums\\Forum',
					'permissionCheck' => 'view',
				]
			) );
		}
		else
		{
			$form->addMessage( 'gdloadout_forums_not_configured' );
		}

		if ( $values = $form->values() )
		{
			$forumId = 0;
			if ( isset( $values['gdloadout_share_forum'] ) && $values['gdloadout_share_forum'] instanceof \IPS\Node\Model )
			{
				$forumId = (int) $values['gdloadout_share_forum']->_id;
			}

			try
			{
				\IPS\Settings::i()->changeValues( [ 'gdloadout_share_forum' => $forumId ] );
			}
			catch ( \Throwable )
			{
				try
				{
					Db::i()->replace( 'core_sys_conf_settings', [
						'conf_key'     => 'gdloadout_share_forum',
						'conf_value'   => $forumId,
						'conf_default' => '0',
						'conf_app'     => 'gdloadout',
					] );
					unset( \IPS\Data\Store::i()->settings );
				}
				catch ( \Throwable ) {}
			}

			Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdloadout&module=manage&controller=settings' )
			);
			return;
		}

		Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_settings' );
		Output::i()->output = $form;
	}
}

class settings extends _settings {}
