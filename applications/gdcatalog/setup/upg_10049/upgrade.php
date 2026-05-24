<?php
namespace IPS\gdcatalog\setup\upg_10049;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$cols = [ 'upc_invalid_count', 'upc_flagged_count' ];
		foreach ( $cols as $col )
		{
			try
			{
				\IPS\Db::i()->addColumn( 'gd_import_log', [
					'name'       => $col,
					'type'       => 'INT',
					'length'     => null,
					'unsigned'   => true,
					'allow_null' => false,
					'default'    => 0,
				] );
			}
			catch ( \Throwable ) {}
		}
		return TRUE;
	}

	public function step2(): bool
	{
		$attrCols = [ 'stock_material', 'slide_material' ];
		foreach ( $attrCols as $col )
		{
			try
			{
				\IPS\Db::i()->addColumn( 'gd_catalog', [
					'name'       => $col,
					'type'       => 'VARCHAR',
					'length'     => 100,
					'allow_null' => true,
					'default'    => null,
				] );
			}
			catch ( \Throwable ) {}
		}
		return TRUE;
	}

	public function step3(): bool
	{
		$categories = [
			'Handguns' => [
				'Pistols', 'Revolvers', 'Derringers', 'Single-Shot Pistols', 'Flare Pistols',
			],
			'Rifles' => [
				'Semi-Automatic Rifles', 'Bolt-Action Rifles', 'Lever-Action Rifles',
				'Pump-Action Rifles', 'Single-Shot Rifles', 'Break-Action Rifles',
				'Muzzleloaders', 'Rimfire Rifles',
			],
			'Shotguns' => [
				'Semi-Automatic Shotguns', 'Pump-Action Shotguns', 'Break-Action Shotguns',
				'Over/Under Shotguns', 'Side-by-Side Shotguns', 'Single-Shot Shotguns',
			],
			'Ammunition' => [
				'Handgun Ammunition', 'Rifle Ammunition', 'Shotgun Ammunition',
				'Rimfire Ammunition', 'Centerfire Ammunition', 'Specialty & Exotic',
				'Blanks & Less-Lethal',
			],
			'NFA Items' => [
				'Suppressors', 'Short-Barreled Rifles (SBR)', 'Short-Barreled Shotguns (SBS)',
				'Machine Guns', 'Any Other Weapons (AOW)', 'Destructive Devices',
			],
			'Magazines' => [
				'Handgun Magazines', 'Rifle Magazines', 'Shotgun Magazines',
				'Drum Magazines', 'Extended Magazines',
			],
			'Optics' => [
				'Red Dot Sights', 'Holographic Sights', 'Rifle Scopes',
				'LPVOs (1-6x and similar)', 'Prism Scopes', 'Night Vision Optics',
				'Thermal Optics', 'Magnifiers', 'Laser Sights', 'Iron Sights',
				'Rangefinders', 'Spotting Scopes', 'Binoculars',
			],
			'Parts & Accessories' => [
				'Barrels', 'Triggers & Trigger Groups', 'Stocks & Chassis',
				'Grips & Grip Panels', 'Rails & Mounts', 'Handguards & Forends',
				'Muzzle Devices', 'Bolts & Bolt Carriers', 'Buffers & Springs',
				'Slides', 'Frames & Receivers', 'Sight Mounts', 'Magazine Wells',
			],
			'Holsters & Carry' => [
				'IWB Holsters', 'OWB Holsters', 'Shoulder Holsters', 'Ankle Holsters',
				'Appendix Carry (AIWB)', 'Duty Holsters', 'Vehicle Holsters',
				'Pocket Holsters', 'Chest Rigs & Plate Carriers', 'Magazine Pouches',
			],
			'Storage & Safety' => [
				'Gun Safes', 'Handgun Safes', 'Long Gun Cases (Hard)',
				'Long Gun Cases (Soft)', 'Handgun Cases', 'Lock Boxes',
				'Trigger Locks', 'Cable Locks', 'Vault Doors', 'Safe Accessories',
			],
			'Cleaning & Maintenance' => [
				'Cleaning Kits', 'Lubricants & CLP', 'Solvents & Degreasers',
				'Bore Snakes & Brushes', 'Patches & Jags', 'Ultrasonic Cleaners',
				'Cleaning Rods', 'Gun Vises & Cradles',
			],
			'Tactical Gear' => [
				'Weapon Lights', 'Bipods & Monopods', 'Slings & Swivels',
				'Foregrips & Vertical Grips', 'Suppressors & Solvent Traps',
				'Cheek Rests & Risers', 'Shell Holders & Carriers',
				'Shooting Bags & Rests', 'Ear & Eye Protection', 'Gloves & Apparel',
			],
			'Hunting Gear' => [
				'Game Calls', 'Scent Control', 'Hunting Blinds', 'Feeders & Attractants',
				'Trail Cameras', 'Tree Stands', 'Field Dressing Tools', 'Decoys', 'Archery',
			],
			'Training & Safety' => [
				'Firearm Training Tools', 'Dry-Fire Systems', 'Safety Flags',
				'Snap Caps & Dummy Rounds', 'Books & DVDs',
			],
			'Electronics & Comms' => [
				'Radio & Communication', 'GPS Devices', 'Rangefinder Apps',
			],
		];

		$position = 0;
		foreach ( $categories as $parentName => $children )
		{
			$slug = mb_strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $parentName ) );
			$slug = trim( $slug, '-' );

			$parentId = null;
			try
			{
				$parentId = (int) \IPS\Db::i()->select( 'id', 'gd_categories', [ 'slug=? AND parent_id=0', $slug ] )->first();
			}
			catch ( \Throwable ) {}

			if ( $parentId === null || $parentId === 0 )
			{
				try
				{
					$parentId = \IPS\Db::i()->insert( 'gd_categories', [
						'parent_id'     => 0,
						'name'          => $parentName,
						'slug'          => $slug,
						'position'      => $position,
						'product_count' => 0,
					] );
				}
				catch ( \Throwable )
				{
					$position++;
					continue;
				}
			}
			$position++;

			$childPos = 0;
			foreach ( $children as $childName )
			{
				$childSlug = $slug . '-' . mb_strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $childName ) );
				$childSlug = trim( $childSlug, '-' );

				try
				{
					$existing = \IPS\Db::i()->select( 'COUNT(*)', 'gd_categories', [ 'slug=?', $childSlug ] )->first();
					if ( (int) $existing > 0 )
					{
						$childPos++;
						continue;
					}
				}
				catch ( \Throwable ) {}

				try
				{
					\IPS\Db::i()->insert( 'gd_categories', [
						'parent_id'     => $parentId,
						'name'          => $childName,
						'slug'          => $childSlug,
						'position'      => $childPos,
						'product_count' => 0,
					] );
				}
				catch ( \Throwable ) {}
				$childPos++;
			}
		}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
