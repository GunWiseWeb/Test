<?php
namespace IPS\gdcatalog\setup\upg_10076;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		try
		{
			\IPS\Db::i()->query(
				"ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_catalog`
				 MODIFY COLUMN `rounds_per_box` VARCHAR(50) NULL DEFAULT NULL"
			);
		}
		catch ( \Throwable ) {}

		try
		{
			\IPS\Db::i()->query(
				"ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_catalog`
				 MODIFY COLUMN `boxes_per_case` VARCHAR(50) NULL DEFAULT NULL"
			);
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
