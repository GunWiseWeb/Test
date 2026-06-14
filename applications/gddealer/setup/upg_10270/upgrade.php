<?php

namespace IPS\gddealer\setup\upg_10270;

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
		$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_sys_conf_settings', [ 'conf_key=?', 'gddealer_plan_max_json' ] )->first();
		if ( !$exists )
		{
			try
			{
				$tpl = \IPS\Db::i()->select( '*', 'core_sys_conf_settings', [ 'conf_key=?', 'gddealer_plan_basic_json' ] )->first();
				if ( isset( $tpl['conf_id'] ) ) { unset( $tpl['conf_id'] ); }
				$tpl['conf_key']     = 'gddealer_plan_max_json';
				$tpl['conf_value']   = '';
				$tpl['conf_default'] = '';
				\IPS\Db::i()->insert( 'core_sys_conf_settings', $tpl );
			}
			catch ( \Throwable )
			{
				\IPS\Db::i()->insert( 'core_sys_conf_settings', [
					'conf_key'     => 'gddealer_plan_max_json',
					'conf_value'   => '',
					'conf_default' => '',
					'conf_app'     => 'gddealer',
				] );
			}
		}

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
