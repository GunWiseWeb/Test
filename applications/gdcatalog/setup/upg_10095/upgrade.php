<?php
namespace IPS\gdcatalog\setup\upg_10095;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$cols = [
			'holster_type'=>'VARCHAR(80) NULL DEFAULT NULL','holster_color'=>'VARCHAR(80) NULL DEFAULT NULL',
			'holster_material'=>'VARCHAR(80) NULL DEFAULT NULL','holster_hand'=>'VARCHAR(80) NULL DEFAULT NULL',
			'apparel_pattern'=>'VARCHAR(80) NULL DEFAULT NULL','apparel_size'=>'VARCHAR(80) NULL DEFAULT NULL',
			'apparel_material'=>'VARCHAR(80) NULL DEFAULT NULL',
			'optic_type'=>'VARCHAR(80) NULL DEFAULT NULL','optic_material'=>'VARCHAR(80) NULL DEFAULT NULL',
			'optic_color'=>'VARCHAR(80) NULL DEFAULT NULL','optic_platform'=>'VARCHAR(80) NULL DEFAULT NULL',
			'hunt_call_type'=>'VARCHAR(80) NULL DEFAULT NULL','hunt_game'=>'VARCHAR(80) NULL DEFAULT NULL',
			'blade_shape'=>'VARCHAR(80) NULL DEFAULT NULL','blade_length'=>'VARCHAR(80) NULL DEFAULT NULL',
			'blade_material'=>'VARCHAR(80) NULL DEFAULT NULL','blade_edge'=>'VARCHAR(80) NULL DEFAULT NULL',
			'knife_handle'=>'VARCHAR(80) NULL DEFAULT NULL',
		];
		foreach ( $cols as $name => $ddl )
		{
			try {
				if ( !\IPS\Db::i()->checkForColumn( 'gd_catalog', $name ) )
				{ \IPS\Db::i()->query( "ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_catalog` ADD COLUMN `{$name}` {$ddl}" ); }
			} catch ( \Throwable ) {}
		}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
