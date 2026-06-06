<?php
namespace IPS\gdsearch\setup\upg_10023;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

class _upgrade
{
	public function step1(): bool
	{
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_price_alerts' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_price_alerts',
					'columns' => [
						[ 'name' => 'id',                  'type' => 'BIGINT',   'length' => 20, 'unsigned' => TRUE, 'allow_null' => FALSE, 'auto_increment' => TRUE ],
						[ 'name' => 'member_id',           'type' => 'INT',      'length' => 10, 'unsigned' => TRUE, 'allow_null' => FALSE, 'default' => 0 ],
						[ 'name' => 'upc',                 'type' => 'VARCHAR',  'length' => 20, 'allow_null' => FALSE, 'default' => '' ],
						[ 'name' => 'threshold',           'type' => 'DECIMAL',  'length' => '10,2', 'allow_null' => FALSE, 'default' => 0 ],
						[ 'name' => 'created',             'type' => 'INT',      'length' => 10, 'unsigned' => TRUE, 'allow_null' => FALSE, 'default' => 0 ],
						[ 'name' => 'last_notified',       'type' => 'INT',      'length' => 10, 'unsigned' => TRUE, 'allow_null' => FALSE, 'default' => 0 ],
						[ 'name' => 'last_notified_price', 'type' => 'DECIMAL',  'length' => '10,2', 'allow_null' => TRUE,  'default' => NULL ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',      'columns' => [ 'id' ] ],
						[ 'type' => 'unique',  'name' => 'member_upc',   'columns' => [ 'member_id', 'upc' ] ],
						[ 'type' => 'key',     'name' => 'upc_threshold', 'columns' => [ 'upc', 'threshold' ] ],
					],
				] );
			}
		}
		catch ( \Throwable ) {}

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'      => (int) $langId,
						'word_app'     => 'gdsearch',
						'word_key'     => 'gdsearch_my_alerts_title',
						'word_default' => 'My Price Alerts',
						'word_js'      => 0,
						'word_export'  => 1,
					] );
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		try { \IPS\Theme::deleteCompiledTemplate(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}

class upgrade extends _upgrade {}
