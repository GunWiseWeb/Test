<?php
namespace IPS\gdcatalog\setup\upg_10069;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$alters = [
			"ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_catalog` MODIFY COLUMN `barrel_length` VARCHAR(50) NULL DEFAULT NULL",
			"ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_catalog` MODIFY COLUMN `capacity` VARCHAR(50) NULL DEFAULT NULL",
			"ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_catalog` MODIFY COLUMN `overall_length` VARCHAR(50) NULL DEFAULT NULL",
			"ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_catalog` MODIFY COLUMN `caliber` VARCHAR(100) NULL DEFAULT NULL",
		];
		foreach ( $alters as $sql )
		{
			try
			{
				\IPS\Db::i()->query( $sql );
			}
			catch ( \Throwable ) {}
		}

		try
		{
			\IPS\Task\Queue::queue( 'gdcatalog', 'BackfillAttributes', [ 'offset' => 0 ] );
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
