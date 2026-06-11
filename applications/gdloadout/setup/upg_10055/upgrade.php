<?php

namespace IPS\gdloadout\setup\upg_10055;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$words = [
			'gdloadout_notify_loadout_updated'  => 'A loadout you follow was updated',
			'gdloadout_notify_loadout_upvoted'  => 'Someone upvoted your loadout',
			'gdloadout_notify_loadout_followed' => 'Someone followed your loadout',
		];

		foreach ( $words as $key => $val )
		{
			$has = false;
			try { \IPS\Db::i()->select( 'word_key', 'core_sys_lang_words', [ 'word_key=? AND word_app=?', $key, 'gdloadout' ] )->first(); $has = true; } catch ( \Throwable ) {}
			if ( !$has )
			{
				try
				{
					foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
					{
						try
						{
							\IPS\Db::i()->insert( 'core_sys_lang_words', [
								'lang_id'      => (int) $langId,
								'word_app'     => 'gdloadout',
								'word_key'     => $key,
								'word_default' => $val,
								'word_js'      => 0,
								'word_export'  => 1,
							] );
						}
						catch ( \Throwable ) {}
					}
				}
				catch ( \Throwable ) {}
			}
		}

		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
