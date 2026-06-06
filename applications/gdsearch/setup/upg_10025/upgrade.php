<?php
namespace IPS\gdsearch\setup\upg_10025;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		try
		{
			$exists = \IPS\Db::i()->select( 'COUNT(*)', 'core_notification_defaults', [ 'notification_key=?', 'price_drop' ] )->first();
			if ( !$exists )
			{
				\IPS\Db::i()->insert( 'core_notification_defaults', [
					'notification_key' => 'price_drop',
					'default'          => 'inline,email',
					'disabled'         => '',
				] );
			}
		}
		catch ( \Throwable ) {}

		try { \IPS\Application::load( 'gdsearch' )->installEmailTemplates(); }
		catch ( \Throwable ) {}

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				$strings = [
					'gdsearch_notif_price_drop'          => 'Price drop alerts',
					'gdsearch_notif_price_drop_desc'     => "Email and on-site alert when a product you're watching drops to or below your target price.",
					'notifications__gdsearch_price_drop' => 'Price drop alerts',
					'notification__price_drop'           => 'Price drop alert',
				];
				foreach ( $strings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdsearch',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
