<?php

namespace IPS\gddeals\setup\upg_10041;

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
			\IPS\Db::i()->insert( 'core_sys_conf_settings', [
				'conf_key'     => 'gddeals_show_coupon_strip',
				'conf_value'   => '1',
				'conf_default' => '1',
				'conf_app'     => 'gddeals',
			] );
		}
		catch ( \Throwable ) {}

		$newStrings = [
			'gddeals_settings_display'       => 'Display',
			'gddeals_show_coupon_strip'      => 'Show coupon strip on the deals page',
			'gddeals_show_coupon_strip_desc' => 'When off, the Coupons & promo codes strip is hidden above the deals list. The dedicated Coupons page is unaffected.',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gddeals',
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

		try { unset( \IPS\Data\Store::i()->settings ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
