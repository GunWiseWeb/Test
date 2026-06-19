<?php
namespace IPS\gdrebates\setup\upg_10001;

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
		if ( !\IPS\Db::i()->checkForColumn( 'gd_rebate_sources', 'model_override' ) )
		{
			\IPS\Db::i()->addColumn( 'gd_rebate_sources', [
				'name'           => 'model_override',
				'type'           => 'VARCHAR',
				'length'         => 20,
				'allow_null'     => false,
				'default'        => '',
				'auto_increment' => false,
			] );
		}

		try
		{
			\IPS\Db::i()->replace( 'core_tasks', [
				'app'       => 'gdrebates',
				'key'       => 'ParseRebates',
				'frequency' => 'P0Y0M7DT0H0M0S',
				'running'   => 0,
				'enabled'   => 1,
			] );
		}
		catch ( \Throwable $e ) {}

		$newStrings = [
			'gdrebates_model'              => 'Default Claude model',
			'gdrebates_src_model_override'  => 'Model override',
			'gdrebates_src_parse'          => 'Parse now',
			'gdrebates_src_parse_done'     => 'Parse complete',
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
							'word_app'     => 'gdrebates',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e ) {}
				}
			}
		}
		catch ( \Throwable $e ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}
class upgrade extends _upgrade {}
