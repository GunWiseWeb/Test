<?php
namespace IPS\gdcatalog\setup\upg_10099;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$db = \IPS\Db::i();

		/* Carry forward from v1.0.97: optic columns (guarded) */
		$cols = [
			'optic_magnification' => 'VARCHAR(80) NULL DEFAULT NULL',
			'optic_objective'     => 'VARCHAR(80) NULL DEFAULT NULL',
		];
		foreach ( $cols as $name => $ddl )
		{
			try {
				if ( !$db->checkForColumn( 'gd_catalog', $name ) )
				{
					$db->query( "ALTER TABLE `" . $db->prefix . "gd_catalog` ADD COLUMN `{$name}` {$ddl}" );
				}
			} catch ( \Throwable ) {}
		}

		/* v1.0.98: Seed Scope Rings / Upper Receivers / Lower Receivers
		   as children of Parts & Accessories (idempotent) */
		$seeds = [
			'Parts & Accessories' => [ 'Scope Rings', 'Upper Receivers', 'Lower Receivers' ],
			'Tactical Gear'       => [ 'Flashlights & Headlamps' ],
		];
		foreach ( $seeds as $parentName => $children )
		{
			try
			{
				$parentId = (int) $db->select( 'id', 'gd_categories', [ 'name=?', $parentName ] )->first();
			}
			catch ( \Throwable )
			{
				$parentId = 0;
			}
			if ( $parentId > 0 )
			{
				foreach ( $children as $childName )
				{
					try
					{
						$exists = (int) $db->select( 'COUNT(*)', 'gd_categories', [ 'name=? AND parent_id=?', $childName, $parentId ] )->first();
						if ( $exists === 0 )
						{
							$slug = strtolower( str_replace( [ ' ', '&' ], [ '-', 'and' ], $childName ) );
							$pos  = (int) $db->select( 'MAX(position)', 'gd_categories', [ 'parent_id=?', $parentId ] )->first() + 1;
							$db->insert( 'gd_categories', [
								'parent_id'      => $parentId,
								'name'           => $childName,
								'slug'           => $slug,
								'position'       => $pos,
								'product_count'  => 0,
							] );
						}
					}
					catch ( \Throwable ) {}
				}
			}
		}

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}
class upgrade extends _upgrade {}
