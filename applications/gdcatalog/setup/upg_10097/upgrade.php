<?php
namespace IPS\gdcatalog\setup\upg_10097;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$cols = [
			'optic_magnification' => 'VARCHAR(80) NULL DEFAULT NULL',
			'optic_objective'     => 'VARCHAR(80) NULL DEFAULT NULL',
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
