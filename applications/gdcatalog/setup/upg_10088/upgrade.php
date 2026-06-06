<?php
namespace IPS\gdcatalog\setup\upg_10088;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$prefix = \IPS\Db::i()->prefix;
		$table  = $prefix . 'gd_catalog';

		$cols = [
			'grain'       => "ALTER TABLE `{$table}` ADD COLUMN `grain` SMALLINT UNSIGNED NULL DEFAULT NULL",
			'case_type'   => "ALTER TABLE `{$table}` ADD COLUMN `case_type` VARCHAR(60) NULL DEFAULT NULL",
			'bullet_type' => "ALTER TABLE `{$table}` ADD COLUMN `bullet_type` VARCHAR(60) NULL DEFAULT NULL",
		];

		foreach ( $cols as $colName => $sql )
		{
			try
			{
				$exists = \IPS\Db::i()->query( "SHOW COLUMNS FROM `{$table}` LIKE '{$colName}'" );
				if ( $exists->num_rows === 0 )
				{
					\IPS\Db::i()->query( $sql );
				}
			}
			catch ( \Throwable ) {}
		}

		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}

		return TRUE;
	}
}
class upgrade extends _upgrade {}
