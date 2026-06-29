<?php

namespace IPS\gddeals\setup\upg_10048;

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
		/* Seed the correct ACP menu lang key (IPS format is
		   menu__{app}_{module}_{controller}). v1.0.47 seeded the wrong key
		   "menu__deals_autodeals" which IPS never reads, so the menu item
		   rendered as the raw key. */
		try
		{
			foreach ( \IPS\Lang::languages() as $lang )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'      => (int) $lang->id,
						'word_app'     => 'gddeals',
						'word_key'     => 'menu__gddeals_deals_autodeals',
						'word_default' => 'Auto Deals',
						'word_js'      => 0,
						'word_export'  => 1,
					] );
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		/* Clean up the orphaned wrong-format key from v1.0.47. */
		try
		{
			\IPS\Db::i()->delete( 'core_sys_lang_words', [
				'word_app=? AND word_key=?', 'gddeals', 'menu__deals_autodeals'
			] );
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->acpmenu ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
