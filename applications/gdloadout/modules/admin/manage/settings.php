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

		$suggestMode = 'anyone';
		try { $suggestMode = (string) ( \IPS\Settings::i()->gdloadout_suggest_mode ?: 'anyone' ); } catch ( \Throwable ) {}

		$form->add( new \IPS\Helpers\Form\Select(
			'gdloadout_suggest_mode',
			$suggestMode,
			FALSE,
			[
				'options' => [
					'anyone'       => \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_suggest_mode_anyone' ),
					'group'        => \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_suggest_mode_group' ),
					'threshold'    => \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_suggest_mode_threshold' ),
					'owner_toggle' => \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_suggest_mode_owner_toggle' ),
				],
			]
		) );

		$suggestGroups = '';
		try { $suggestGroups = (string) \IPS\Settings::i()->gdloadout_suggest_groups; } catch ( \Throwable ) {}
		$form->add( new \IPS\Helpers\Form\Text( 'gdloadout_suggest_groups', $suggestGroups, FALSE ) );

		$suggestMinPosts = 0;
		try { $suggestMinPosts = (int) \IPS\Settings::i()->gdloadout_suggest_min_posts; } catch ( \Throwable ) {}
		$form->add( new \IPS\Helpers\Form\Number( 'gdloadout_suggest_min_posts', $suggestMinPosts, FALSE, [ 'min' => 0 ] ) );

		$suggestMinRep = 0;
		try { $suggestMinRep = (int) \IPS\Settings::i()->gdloadout_suggest_min_rep; } catch ( \Throwable ) {}
		$form->add( new \IPS\Helpers\Form\Number( 'gdloadout_suggest_min_rep', $suggestMinRep, FALSE, [ 'min' => 0 ] ) );

		if ( $values = $form->values() )
		{
			$forumId = 0;
			if ( isset( $values['gdloadout_share_forum'] ) && $values['gdloadout_share_forum'] instanceof \IPS\Node\Model )
			{
				$forumId = (int) $values['gdloadout_share_forum']->_id;
			}

			$settingsToSave = [
				'gdloadout_share_forum'        => $forumId,
				'gdloadout_suggest_mode'       => $values['gdloadout_suggest_mode'] ?? 'anyone',
				'gdloadout_suggest_groups'     => trim( (string) ( $values['gdloadout_suggest_groups'] ?? '' ) ),
				'gdloadout_suggest_min_posts'  => (int) ( $values['gdloadout_suggest_min_posts'] ?? 0 ),
				'gdloadout_suggest_min_rep'    => (int) ( $values['gdloadout_suggest_min_rep'] ?? 0 ),
			];

			try
			{
				\IPS\Settings::i()->changeValues( $settingsToSave );
			}
			catch ( \Throwable )
			{
				foreach ( $settingsToSave as $confKey => $confVal )
				{
					try
					{
						Db::i()->replace( 'core_sys_conf_settings', [
							'conf_key'     => $confKey,
							'conf_value'   => (string) $confVal,
							'conf_default' => $confKey === 'gdloadout_share_forum' ? '0' : ( $confKey === 'gdloadout_suggest_mode' ? 'anyone' : '0' ),
							'conf_app'     => 'gdloadout',
						] );
					}
					catch ( \Throwable ) {}
				}
				try { unset( \IPS\Data\Store::i()->settings ); } catch ( \Throwable ) {}
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
