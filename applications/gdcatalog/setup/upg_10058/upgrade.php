<?php
namespace IPS\gdcatalog\setup\upg_10058;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		if ( !\IPS\Db::i()->checkForTable( 'gd_sportssouth_brands' ) )
		{
			\IPS\Db::i()->createTable( [
				'name'    => 'gd_sportssouth_brands',
				'columns' => [
					[ 'name' => 'brdno',  'type' => 'INT',     'length' => 11,  'allow_null' => false, 'auto_increment' => false, 'binary' => false, 'unsigned' => true,  'zerofill' => false, 'values' => [], 'default' => null, 'comment' => '' ],
					[ 'name' => 'brdnam', 'type' => 'VARCHAR', 'length' => 255, 'allow_null' => true,  'auto_increment' => false, 'binary' => false, 'unsigned' => false, 'zerofill' => false, 'values' => [], 'default' => null, 'comment' => '' ],
				],
				'indexes' => [
					[ 'type' => 'primary', 'name' => 'PRIMARY', 'length' => [], 'columns' => [ 'brdno' ] ],
				],
			] );
		}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
