<?php
namespace IPS\gdcatalog\setup\upg_10021;

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
		/* gdcatalog v1.0.21 - Explicit Sports South CATID -> gd_categories
		 * remapping. Replaces v1.0.15's keyword-based auto-mapper which
		 * left 48 categories unmapped and incorrectly bucketed ammo as
		 * rifles (485 products in Rifles included CENTERFIRE RIFLE ROUNDS).
		 *
		 * Approach:
		 *   Hardcoded explicit mapping for all 93 Sports South CATIDs.
		 *   Each mapping is human-curated based on the Sports South category
		 *   name and the gd_categories tree structure. Replaces existing
		 *   mappings via REPLACE INTO.
		 *
		 *   NOTE: This will OVERWRITE any 'manual' mappings. Currently
		 *   no manual edits exist - admin will need to redo any after
		 *   this runs. ACP UI for managing the map is future work.
		 *
		 * After install:
		 *   1. Admin must Run Import to update existing 1012 products'
		 *      category_id based on new mappings.
		 *   2. Filter dropdown will then work properly - selecting
		 *      "Ammunition" returns ammo, "Rifles" returns just rifles
		 *      (not ammo too), etc.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10020). */

		/* Step 1: Sanity check */
		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gdcatalog' ]
			)->first();

			$longVer = (int) ( $row['app_long_version'] ?? 0 );
			$msg = sprintf(
				'gdcatalog v1.0.21 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10021' ); } catch ( \Throwable ) {}

			if ( $longVer < 10020 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.21 WARNING: app_long_version=%d below 10020',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10021' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.21 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10021' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Apply explicit mappings.
		 *
		 * sportssouth_catid => gd_category_id
		 *
		 * Mapping decisions:
		 *   - All firearms categories route to specific gun parent
		 *     (Rifles=5, Pistols=2, Revolvers=3, Shotguns=11, Handguns=1)
		 *   - All ammo routes under Ammunition tree (17 parent or specific
		 *     subcategories 18-22)
		 *   - All firearm parts route to Parts & Accessories (42)
		 *   - All optics route to Optics (34) or specific subcategories
		 *   - Hunting accessories route to Hunting Gear (77) or subcat
		 *   - Cleaning items route to Cleaning & Maintenance (64) or subcat
		 *   - Storage/safety items route to Storage & Safety (58) or subcat
		 *   - Tactical accessories route to Tactical Gear (70) or subcat
		 *   - Suppressors -> NFA Items > Suppressors (24)
		 *   - UNASSIGNED catid=0 stays unmapped (0) */
		$mappings = [
			/* catid => gd_category_id */
			0  => 0,    /* UNASSIGNED - stays unmapped */
			1  => 42,   /* ACCESSORIES MISCELLANEOUS -> Parts & Accessories */
			2  => 1,    /* AIR GUNS -> Handguns (closest semantic match - no air gun cat) */
			3  => 42,   /* ATV ACCESSORIES -> Parts & Accessories */
			4  => 17,   /* BLANK ROUNDS -> Ammunition */
			5  => 18,   /* CENTERFIRE HANDGUN ROUNDS -> Handgun Ammo */
			6  => 21,   /* RIMFIRE ROUNDS -> Rimfire */
			7  => 77,   /* ARCHERY AND ACCESSORIES -> Hunting Gear */
			8  => 43,   /* EXTRA BARRELS -> Barrels */
			9  => 72,   /* LASER SIGHTS -> Lasers */
			10 => 34,   /* BINOCULARS -> Optics */
			11 => 42,   /* BLACK POWDER ACCESSORIES -> Parts & Accessories */
			12 => 22,   /* BLACK POWDER BULLETS -> Specialty/Exotic */
			13 => 10,   /* BLACK POWDER FIREARMS -> Muzzleloaders */
			14 => 70,   /* APPAREL -> Tactical Gear */
			15 => 60,   /* GUNCASES -> Hard Cases */
			16 => 49,   /* CHOKE TUBES -> Muzzle Devices */
			17 => 64,   /* CLEANING AND RESTORATION -> Cleaning & Maintenance */
			18 => 29,   /* MAGAZINES AND ACCESSORIES -> Magazines */
			19 => 77,   /* DECOYS -> Hunting Gear */
			20 => 42,   /* ELECTRONICS -> Parts & Accessories */
			21 => 81,   /* FEEDERS -> Feeders */
			22 => 78,   /* GAME CALLS -> Game Calls */
			23 => 46,   /* GRIPS AND RECOIL PADS -> Grips */
			25 => 70,   /* EYE PROTECTION -> Tactical Gear */
			26 => 2,    /* PISTOLS -> Pistols */
			27 => 80,   /* BLINDS AND ACCESSORIES -> Blinds */
			28 => 50,   /* HOLSTERS -> Holsters & Carry */
			29 => 42,   /* MEDIA -> Parts & Accessories */
			30 => 42,   /* KNIVES -> Parts & Accessories (no knife cat) */
			31 => 71,   /* LIGHTS -> Weapon Lights */
			32 => 39,   /* NIGHT VISION -> Night Vision */
			34 => 70,   /* PERSONAL PROTECTION -> Tactical Gear */
			35 => 22,   /* RELOADING ACCESSORIES -> Specialty/Exotic */
			36 => 22,   /* RELOADING BULLETS -> Specialty/Exotic */
			37 => 45,   /* STOCKS AND FORENDS -> Stocks */
			38 => 22,   /* DIES -> Specialty/Exotic (reloading) */
			39 => 35,   /* RED DOT SCOPES -> Red Dots */
			40 => 5,    /* RIFLES CENTERFIRE -> Rifles */
			41 => 47,   /* RINGS AND ADAPTORS -> Rails */
			42 => 22,   /* PRESSES -> Specialty/Exotic (reloading) */
			43 => 77,   /* TRAPS AND CLAY THROWERS -> Hunting Gear */
			44 => 59,   /* GUN VAULTS AND SAFES -> Gun Safes */
			45 => 79,   /* HUNTING SCENTS -> Scent Control */
			46 => 36,   /* SCOPES -> Rifle Scopes */
			47 => 42,   /* TARGETS -> Parts & Accessories */
			48 => 11,   /* SHOTGUNS -> Shotguns */
			49 => 20,   /* SHOTSHELL LEAD LOADS -> Shotgun Ammo */
			50 => 42,   /* GUN SIGHTS -> Parts & Accessories */
			51 => 74,   /* SLINGS -> Slings */
			52 => 34,   /* SPOTTING -> Optics */
			53 => 73,   /* GUN RESTS - BIPODS - TRIPODS -> Bipods */
			55 => 47,   /* BASES -> Rails */
			56 => 77,   /* CAMPING -> Hunting Gear */
			57 => 42,   /* COMBO -> Parts & Accessories */
			58 => 5,    /* RIFLES CENTERFIRE TACTICAL -> Rifles */
			59 => 11,   /* SHOTGUNS TACTICAL -> Shotguns */
			60 => 42,   /* BATTERIES -> Parts & Accessories */
			61 => 77,   /* COOLERS -> Hunting Gear */
			62 => 82,   /* CAMERAS -> Trail Cameras */
			63 => 42,   /* UPPERS -> Parts & Accessories */
			64 => 3,    /* REVOLVERS -> Revolvers */
			65 => 42,   /* SPECIALTY -> Parts & Accessories */
			66 => 42,   /* LOWERS -> Parts & Accessories */
			67 => 42,   /* FRAMES -> Parts & Accessories */
			68 => 42,   /* KNIFE ACCESSORIES -> Parts & Accessories */
			69 => 17,   /* DUMMY ROUNDS -> Ammunition */
			70 => 19,   /* CENTERFIRE RIFLE ROUNDS -> Rifle Ammo */
			71 => 20,   /* SHOTSHELL STEEL LOADS -> Shotgun Ammo */
			72 => 20,   /* SHOTSHELL NON-TOX LOADS -> Shotgun Ammo */
			73 => 42,   /* AIR GUN ACCESSORIES -> Parts & Accessories */
			74 => 42,   /* FIREARM PARTS -> Parts & Accessories */
			75 => 42,   /* HOLDERS AND ACCESSORIES -> Parts & Accessories */
			76 => 74,   /* SWIVELS -> Slings (swivels attach to slings) */
			77 => 42,   /* CONVERSION KITS -> Parts & Accessories */
			78 => 34,   /* RANGE FINDERS -> Optics */
			79 => 65,   /* BORE SIGHTERS AND ARBORS -> Cleaning Kits */
			80 => 42,   /* SCOPE COVERS AND SHADES -> Parts & Accessories */
			81 => 22,   /* COMPONENTS -> Specialty/Exotic */
			82 => 22,   /* POWDERS -> Specialty/Exotic */
			83 => 70,   /* HEARING PROTECTION -> Tactical Gear */
			84 => 61,   /* CARRYING BAGS -> Soft Cases */
			85 => 79,   /* REPELLENTS -> Scent Control */
			86 => 17,   /* AIR GUN AMMO -> Ammunition */
			87 => 65,   /* CLEANING KITS -> Cleaning Kits */
			88 => 60,   /* UTILITY BOXES -> Hard Cases */
			89 => 42,   /* DISPLAYS -> Parts & Accessories */
			90 => 20,   /* SHOTSHELL SLUG LOADS -> Shotgun Ammo */
			91 => 20,   /* SHOTSHELL BUCKSHOT LOADS -> Shotgun Ammo */
			92 => 64,   /* REFURBISH DENT OR SCRATCH -> Cleaning & Maintenance */
			93 => 24,   /* SUPPRESSORS -> Suppressors */
			94 => 5,    /* RIFLES RIMFIRE -> Rifles */
			96 => 1,    /* BREAK-ACTION HANDGUNS -> Handguns */
		];

		$now = time();
		$applied = 0;
		$failed  = 0;

		foreach ( $mappings as $catid => $gdCatId )
		{
			try
			{
				/* Use REPLACE INTO semantics via IPS\Db. The unique key is
				 * sportssouth_catid (PK). REPLACE will delete-then-insert. */
				\IPS\Db::i()->replace( 'gd_sportssouth_category_map', [
					'sportssouth_catid' => $catid,
					'gd_category_id'    => $gdCatId,
					'mapping_source'    => 'auto',
					'created_at'        => $now,
				] );
				$applied++;
			}
			catch ( \Throwable $rowException )
			{
				$failed++;
				try
				{
					\IPS\Log::log(
						sprintf( 'gdcatalog v1.0.21 failed to map catid=%d -> gd_category_id=%d: %s', $catid, $gdCatId, $rowException->getMessage() ),
						'gdcatalog_upg_10021'
					);
				}
				catch ( \Throwable ) {}
			}
		}

		try
		{
			\IPS\Log::log(
				sprintf( 'gdcatalog v1.0.21 explicit category remap complete: applied=%d failed=%d', $applied, $failed ),
				'gdcatalog_upg_10021'
			);
		}
		catch ( \Throwable ) {}

		/* Step 3: Cache invalidation */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'extensions%' OR store_key LIKE 'applications%'" ] ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/extensions*' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/applications*' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'gdcatalog v1.0.21 - explicit Sports South CATID -> gd_categories remapping';
	}
}

class upgrade extends _upgrade {}
