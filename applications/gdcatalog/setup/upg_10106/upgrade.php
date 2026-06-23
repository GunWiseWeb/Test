<?php

namespace IPS\gdcatalog\setup\upg_10106;

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
		$table = \IPS\Db::i()->getTableDefinition( 'gd_catalog' );
		if ( !isset( $table['columns']['product_type'] ) )
		{
			\IPS\Db::i()->addColumn( 'gd_catalog', [
				'name'           => 'product_type',
				'type'           => 'VARCHAR',
				'length'         => 80,
				'allow_null'     => true,
				'default'        => null,
			] );
		}

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable $e ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable $e ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
		return TRUE;
	}
}
class upgrade extends _upgrade {}
