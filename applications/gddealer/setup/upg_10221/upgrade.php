<?php
namespace IPS\gddealer\setup\upg_10221;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_data_flags' ) )
		{
			\IPS\Db::i()->createTable( [
				'name'    => 'gd_dealer_data_flags',
				'columns' => [
					[ 'name' => 'id',            'type' => 'INT',      'length' => 11,   'allow_null' => false, 'auto_increment' => true,  'unsigned' => true  ],
					[ 'name' => 'dealer_id',     'type' => 'INT',      'length' => 11,   'allow_null' => false, 'auto_increment' => false, 'unsigned' => true  ],
					[ 'name' => 'upc',           'type' => 'VARCHAR',  'length' => 14,   'allow_null' => false, 'auto_increment' => false, 'unsigned' => false ],
					[ 'name' => 'flag_type',     'type' => 'VARCHAR',  'length' => 50,   'allow_null' => false, 'auto_increment' => false, 'unsigned' => false ],
					[ 'name' => 'dealer_value',  'type' => 'TEXT',     'length' => null,  'allow_null' => true,  'auto_increment' => false, 'unsigned' => false ],
					[ 'name' => 'catalog_value', 'type' => 'TEXT',     'length' => null,  'allow_null' => true,  'auto_increment' => false, 'unsigned' => false ],
					[ 'name' => 'status',        'type' => 'VARCHAR',  'length' => 20,   'allow_null' => false, 'auto_increment' => false, 'unsigned' => false, 'default' => 'new' ],
					[ 'name' => 'submitted_at',  'type' => 'DATETIME', 'length' => null,  'allow_null' => true,  'auto_increment' => false, 'unsigned' => false ],
					[ 'name' => 'resolved_at',   'type' => 'DATETIME', 'length' => null,  'allow_null' => true,  'auto_increment' => false, 'unsigned' => false ],
					[ 'name' => 'admin_note',    'type' => 'TEXT',     'length' => null,  'allow_null' => true,  'auto_increment' => false, 'unsigned' => false ],
					[ 'name' => 'created_at',    'type' => 'DATETIME', 'length' => null,  'allow_null' => false, 'auto_increment' => false, 'unsigned' => false ],
					[ 'name' => 'updated_at',    'type' => 'DATETIME', 'length' => null,  'allow_null' => false, 'auto_increment' => false, 'unsigned' => false ],
				],
				'indexes' => [
					[ 'type' => 'primary', 'name' => 'PRIMARY',        'columns' => [ 'id' ] ],
					[ 'type' => 'unique',  'name' => 'idx_dealer_upc', 'columns' => [ 'dealer_id', 'upc', 'flag_type' ] ],
					[ 'type' => 'key',     'name' => 'idx_dealer',     'columns' => [ 'dealer_id' ] ],
					[ 'type' => 'key',     'name' => 'idx_status',     'columns' => [ 'status' ] ],
				],
			] );
		}

		$strings = [
			'gddealer_front_tab_data_flags' => 'Data Flags',
			'gddealer_flag_submitted'       => 'Flag submitted for admin review.',
			'gddealer_flag_type_mpn'        => 'MPN Mismatch',
			'gddealer_flag_type_title'      => 'Title Mismatch',
			'gddealer_flag_type_brand'      => 'Brand Mismatch',
		];

		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $strings as $key => $val )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'      => (int) $langId,
						'word_app'     => 'gddealer',
						'word_key'     => $key,
						'word_default' => $val,
						'word_js'      => 0,
						'word_export'  => 1,
					] );
				}
				catch ( \Throwable ) {}
			}
		}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
