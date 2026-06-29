<?php

namespace IPS\gddeals\setup\upg_10046;

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
		$words = [
			'gddeals_badge_auto'      => 'Auto Deal',
			'gddeals_badge_community' => 'Community',
			'gddeals_auto_explainer'  => 'Automatically detected from live dealer prices.',
		];
		try
		{
			foreach ( \IPS\Lang::languages() as $lang )
			{
				foreach ( $words as $k => $v )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $lang->id,
							'word_app'     => 'gddeals',
							'word_key'     => $k,
							'word_default' => $v,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
