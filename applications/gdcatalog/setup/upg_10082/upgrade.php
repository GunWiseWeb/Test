<?php
namespace IPS\gdcatalog\setup\upg_10082;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	/**
	 * Step 1 — create new categories, write the corrected CATID->id mapping onto
	 * Sports South feed(s), and re-bucket all active products from their stored CATID.
	 */
	public function step1(): bool
	{
		$db = \IPS\Db::i();

		/* New categories to ensure exist: name => parentName|NULL */
		$newCats = [
			'Uncategorized' => NULL, 'Air Guns' => NULL, 'Air Gun Ammo' => 'Air Guns', 'Air Gun Accessories' => 'Air Guns',
			'Reloading' => NULL, 'Reloading Bullets' => 'Reloading', 'Reloading Dies' => 'Reloading', 'Reloading Presses' => 'Reloading',
			'Reloading Powders' => 'Reloading', 'Reloading Components' => 'Reloading', 'Reloading Accessories' => 'Reloading',
			'Muzzleloading' => NULL, 'Black Powder Accessories' => 'Muzzleloading', 'Black Powder Bullets' => 'Muzzleloading',
			'Knives' => NULL, 'Knife Accessories' => 'Knives', 'Apparel' => NULL,
		];

		$slug = static function ( string $n ): string {
			$s = strtolower( trim( $n ) );
			$s = str_replace( '&', 'and', $s );
			$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
			return trim( $s, '-' );
		};

		$nameToId = [];
		foreach ( $db->select( 'id, name', 'gd_categories' ) as $c ) { $nameToId[ $c['name'] ] = (int) $c['id']; }

		foreach ( [ 0, 1 ] as $pass )
		{
			foreach ( $newCats as $name => $parentName )
			{
				$isTop = ( $parentName === NULL );
				if ( ( $pass === 0 && !$isTop ) || ( $pass === 1 && $isTop ) ) { continue; }
				if ( isset( $nameToId[ $name ] ) ) { continue; }
				$parentId = $isTop ? 0 : ( $nameToId[ $parentName ] ?? 0 );
				$id = $db->insert( 'gd_categories', [
					'parent_id' => $parentId, 'name' => $name, 'slug' => $slug( $name ), 'position' => 900, 'product_count' => 0,
				] );
				$nameToId[ $name ] = (int) $id;
			}
		}

		$uncatId = $nameToId['Uncategorized'];

		/* CATID -> target category NAME */
		$catidToName = [
			'0' => 'Uncategorized',
			'1' => 'Parts & Accessories',
			'2' => 'Air Guns',
			'3' => 'Uncategorized',
			'4' => 'Blanks & Less-Lethal',
			'5' => 'Handgun Ammunition',
			'6' => 'Rimfire Ammunition',
			'7' => 'Archery',
			'8' => 'Barrels',
			'9' => 'Laser Sights',
			'10' => 'Binoculars',
			'11' => 'Black Powder Accessories',
			'12' => 'Black Powder Bullets',
			'13' => 'Muzzleloaders',
			'14' => 'Apparel',
			'15' => 'Storage & Safety',
			'16' => 'Parts & Accessories',
			'17' => 'Cleaning & Maintenance',
			'18' => 'Magazines',
			'19' => 'Decoys',
			'20' => 'Electronics & Comms',
			'21' => 'Feeders & Attractants',
			'22' => 'Game Calls',
			'23' => 'Grips & Grip Panels',
			'25' => 'Ear & Eye Protection',
			'26' => 'Pistols',
			'27' => 'Hunting Blinds',
			'28' => 'Holsters & Carry',
			'29' => 'Books & DVDs',
			'30' => 'Knives',
			'31' => 'Weapon Lights',
			'32' => 'Night Vision Optics',
			'34' => 'Tactical Gear',
			'35' => 'Reloading Accessories',
			'36' => 'Reloading Bullets',
			'37' => 'Stocks & Chassis',
			'38' => 'Reloading Dies',
			'39' => 'Red Dot Sights',
			'40' => 'Rifles',
			'41' => 'Rails & Mounts',
			'42' => 'Reloading Presses',
			'43' => 'Tactical Gear',
			'44' => 'Gun Safes',
			'45' => 'Scent Control',
			'46' => 'Rifle Scopes',
			'47' => 'Tactical Gear',
			'48' => 'Shotguns',
			'49' => 'Shotgun Ammunition',
			'50' => 'Iron Sights',
			'51' => 'Slings & Swivels',
			'52' => 'Spotting Scopes',
			'53' => 'Bipods & Monopods',
			'55' => 'Sight Mounts',
			'56' => 'Uncategorized',
			'57' => 'Rifles',
			'58' => 'Semi-Automatic Rifles',
			'59' => 'Shotguns',
			'60' => 'Electronics & Comms',
			'61' => 'Uncategorized',
			'62' => 'Trail Cameras',
			'63' => 'Frames & Receivers',
			'64' => 'Revolvers',
			'65' => 'Handguns',
			'66' => 'Frames & Receivers',
			'67' => 'Frames & Receivers',
			'68' => 'Knife Accessories',
			'69' => 'Snap Caps & Dummy Rounds',
			'70' => 'Rifle Ammunition',
			'71' => 'Shotgun Ammunition',
			'72' => 'Shotgun Ammunition',
			'73' => 'Air Gun Accessories',
			'74' => 'Parts & Accessories',
			'75' => 'Shell Holders & Carriers',
			'76' => 'Slings & Swivels',
			'77' => 'Parts & Accessories',
			'78' => 'Rangefinders',
			'79' => 'Cleaning & Maintenance',
			'80' => 'Optics',
			'81' => 'Reloading Components',
			'82' => 'Reloading Powders',
			'83' => 'Ear & Eye Protection',
			'84' => 'Long Gun Cases (Soft)',
			'85' => 'Hunting Gear',
			'86' => 'Air Gun Ammo',
			'87' => 'Cleaning Kits',
			'88' => 'Storage & Safety',
			'89' => 'Uncategorized',
			'90' => 'Shotgun Ammunition',
			'91' => 'Shotgun Ammunition',
			'92' => 'Uncategorized',
			'93' => 'Suppressors',
			'94' => 'Rimfire Rifles',
			'96' => 'Handguns',
		];

		/* CATID -> id (unknown target name -> Uncategorized) */
		$catidToId = [];
		foreach ( $catidToName as $catid => $nm ) { $catidToId[ (string) $catid ] = $nameToId[ $nm ] ?? $uncatId; }

		/* Write corrected mapping onto Sports South feed(s) */
		$mappingJson = json_encode( $catidToId );
		foreach ( $db->select( 'id, name', 'gd_distributor_feeds' ) as $f )
		{
			if ( stripos( (string) $f['name'], 'south' ) !== FALSE )
			{
				$db->update( 'gd_distributor_feeds', [ 'category_mapping' => $mappingJson ], [ 'id=?', (int) $f['id'] ] );
			}
		}

		/* Re-bucket all active products in a single pass via CASE */
		$P = 'JSON_VALUE(raw_distributor_data, \'$.CATID\')';
		$case = 'UPDATE gd_catalog SET category_id = CASE ' . $P;
		foreach ( $catidToId as $catid => $cid ) { $case .= " WHEN '" . (int) $catid . "' THEN " . (int) $cid; }
		$case .= ' ELSE ' . (int) $uncatId . " END WHERE record_status='active'";
		try { $db->query( $case ); } catch ( \Throwable ) {}

		/* Refresh product_count */
		try {
			$db->update( 'gd_categories', [ 'product_count' => 0 ] );
			foreach ( $db->select( 'category_id, COUNT(*) AS n', 'gd_catalog', "record_status='active'", NULL, NULL, 'category_id' ) as $r )
			{
				$db->update( 'gd_categories', [ 'product_count' => (int) $r['n'] ], [ 'id=?', (int) $r['category_id'] ] );
			}
		} catch ( \Throwable ) {}

		return TRUE;
	}

	/**
	 * Step 2 — clear caches and enqueue the background search-index rebuild
	 * (runs via the core 'queue' task; never blocks the upgrade).
	 */
	public function step2(): bool
	{
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Task::queue( 'gdcatalog', 'RebuildSearchIndex', [] ); } catch ( \Throwable ) {}
		return TRUE;
	}
}

class upgrade extends _upgrade {}
