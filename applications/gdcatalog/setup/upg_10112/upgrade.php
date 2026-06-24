<?php

namespace IPS\gdcatalog\setup\upg_10112;

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

		$cols = [
			'product_type' => [ 'type' => 'VARCHAR', 'length' => 80 ],
			'material'     => [ 'type' => 'VARCHAR', 'length' => 80 ],
			'color'        => [ 'type' => 'VARCHAR', 'length' => 60 ],
			'finish'       => [ 'type' => 'VARCHAR', 'length' => 60 ],
			'size'         => [ 'type' => 'VARCHAR', 'length' => 60 ],
			'mount_type'   => [ 'type' => 'VARCHAR', 'length' => 80 ],
			'fit'          => [ 'type' => 'VARCHAR', 'length' => 150 ],
			'battery_size' => [ 'type' => 'VARCHAR', 'length' => 40 ],
			'nrr'          => [ 'type' => 'VARCHAR', 'length' => 20 ],
			'lock_type'    => [ 'type' => 'VARCHAR', 'length' => 60 ],
			'species'      => [ 'type' => 'VARCHAR', 'length' => 80 ],
		];

		foreach ( $cols as $name => $def )
		{
			if ( !isset( $table['columns'][ $name ] ) )
			{
				\IPS\Db::i()->addColumn( 'gd_catalog', [
					'name'       => $name,
					'type'       => $def['type'],
					'length'     => $def['length'],
					'allow_null' => true,
					'default'    => null,
				] );
			}
		}

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
		return TRUE;
	}
}
class upgrade extends _upgrade {}
