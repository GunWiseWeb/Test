<?php
namespace IPS\gdcatalog\Feed\Distributor;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class SportsSouthAttributeMap
{
	const MAP = [
		// PISTOLS (CATID 26)
		26 => [
			1 => 'gun_type',
			2 => 'action_type',
			3 => 'caliber',
			4 => 'barrel_length',
			5 => 'capacity',
			6 => 'safety_type',
			7 => 'grips',
			8 => 'sight_type',
			9 => 'weight_lbs',
			10 => 'frame_finish',
		],
		// RIFLES CENTERFIRE (CATID 40)
		40 => [
			1 => 'action_type',
			2 => 'caliber',
			3 => 'barrel_length',
			4 => 'capacity',
			5 => 'trigger_type',
			6 => 'safety_type',
			7 => 'overall_length',
			8 => 'weight_lbs',
			9 => 'stock_material',
			10 => 'metal_finish',
		],
		// RIFLES TACTICAL (CATID 58)
		58 => [
			1 => 'action_type',
			2 => 'caliber',
			3 => 'barrel_length',
			4 => 'capacity',
			5 => 'trigger_type',
			6 => 'safety_type',
			7 => 'overall_length',
			8 => 'weight_lbs',
			9 => 'stock_material',
			10 => 'metal_finish',
		],
		// RIFLES RIMFIRE (CATID 94)
		94 => [
			1 => 'action_type',
			2 => 'caliber',
			3 => 'barrel_length',
			4 => 'capacity',
			5 => 'trigger_type',
			6 => 'safety_type',
			7 => 'overall_length',
			8 => 'weight_lbs',
			9 => 'stock_material',
			10 => 'metal_finish',
		],
		// SHOTGUNS (CATID 48)
		48 => [
			1 => 'action_type',
			2 => 'gauge',
			3 => 'barrel_length',
			4 => 'capacity',
			5 => 'chamber',
			6 => 'overall_length',
			7 => 'weight_lbs',
			8 => 'choke_config',
			9 => 'receiver_desc',
			10 => 'stock_material',
		],
		// SHOTGUNS TACTICAL (CATID 59)
		59 => [
			1 => 'action_type',
			2 => 'gauge',
			3 => 'barrel_length',
			4 => 'capacity',
			5 => 'chamber',
			6 => 'overall_length',
			7 => 'weight_lbs',
			8 => 'choke_config',
			9 => 'receiver_desc',
			10 => 'stock_material',
		],
		// REVOLVERS (CATID 64)
		64 => [
			1 => 'gun_type',
			2 => 'action_type',
			3 => 'caliber',
			4 => 'barrel_length',
			5 => 'capacity',
			6 => 'hammer_style',
			7 => 'grips',
			8 => 'sight_type',
			9 => 'weight_lbs',
			10 => 'frame_finish',
		],
		// BREAK-ACTION HANDGUNS (CATID 96)
		96 => [
			1 => 'gun_type',
			2 => 'action_type',
			3 => 'caliber',
			4 => 'barrel_length',
			5 => 'capacity',
			6 => 'safety_type',
			7 => 'grips',
			8 => 'sight_type',
			9 => 'weight_lbs',
			10 => 'metal_finish',
		],
		// CENTERFIRE HANDGUN ROUNDS (CATID 5)
		5 => [
			1 => 'caliber',
			2 => 'bullet_type',
			3 => 'bullet_weight',
			4 => 'muzzle_energy',
			5 => 'muzzle_velocity',
			6 => 'rounds_per_box',
			7 => 'boxes_per_case',
			8 => 'features',
			9 => 'casing_material',
			10 => 'features',
		],
		// RIMFIRE ROUNDS (CATID 6)
		6 => [
			1 => 'caliber',
			2 => 'bullet_type',
			3 => 'bullet_weight',
			4 => 'muzzle_energy',
			5 => 'muzzle_velocity',
			6 => 'casing_material',
			8 => 'rounds_per_box',
			9 => 'boxes_per_case',
		],
		// CENTERFIRE RIFLE ROUNDS (CATID 70)
		70 => [
			1 => 'caliber',
			2 => 'bullet_type',
			3 => 'bullet_weight',
			4 => 'muzzle_energy',
			5 => 'muzzle_velocity',
			6 => 'rounds_per_box',
			7 => 'boxes_per_case',
			8 => 'features',
			9 => 'casing_material',
		],
		// SHOTSHELL LOADS (CATID 49)
		49 => [
			1 => 'gauge',
			2 => 'gun_type',
			3 => 'barrel_length',
			4 => 'bullet_weight',
			5 => 'caliber',
			6 => 'muzzle_velocity',
			7 => 'rounds_per_box',
			8 => 'boxes_per_case',
			9 => 'features',
		],
		// MAGAZINES (CATID 18)
		18 => [
			1 => 'caliber',
			2 => 'capacity',
			3 => 'finish',
			4 => 'model',
			5 => 'stock_material',
			6 => 'gun_type',
		],
		// SCOPES (CATID 46)
		46 => [
			1 => 'magnification',
			2 => 'objective_mm',
			3 => 'features',
			4 => 'eye_relief',
			5 => 'tube_diameter',
			6 => 'overall_length',
			7 => 'weight_lbs',
			8 => 'reticle',
			9 => 'features',
			10 => 'features',
		],
		// RED DOT (CATID 39)
		39 => [
			1 => 'magnification',
			2 => 'objective_mm',
			3 => 'features',
			4 => 'eye_relief',
			5 => 'caliber',
			6 => 'tube_diameter',
			7 => 'overall_length',
			8 => 'weight_lbs',
			9 => 'finish',
			10 => 'features',
		],
		// HOLSTERS (CATID 28)
		28 => [
			1 => 'gun_type',
			2 => 'finish',
			3 => 'stock_material',
			4 => 'features',
			5 => 'features',
			6 => 'finish',
			7 => 'features',
		],
		// STOCKS (CATID 37)
		37 => [
			1 => 'gun_type',
			2 => 'features',
			3 => 'features',
			4 => 'stock_material',
			5 => 'finish',
			6 => 'features',
		],
		// SUPPRESSORS (CATID 93)
		93 => [
			1 => 'gun_type',
			2 => 'caliber',
			3 => 'overall_length',
			4 => 'features',
			9 => 'features',
		],
		// EXTRA BARRELS (CATID 8)
		8 => [
			1 => 'caliber',
			2 => 'barrel_length',
			3 => 'model',
			4 => 'features',
			5 => 'sight_type',
			6 => 'features',
			7 => 'finish',
			8 => 'gun_type',
			9 => 'choke_config',
		],
		// FIREARM PARTS (CATID 74)
		74 => [
			1 => 'gun_type',
			2 => 'model',
			3 => 'features',
			4 => 'features',
			5 => 'stock_material',
			6 => 'action_type',
			7 => 'trigger_type',
			8 => 'safety_type',
			9 => 'features',
			10 => 'finish',
		],
		// ── Accessory categories ──
		1  => [ 1=>'product_type', 3=>'color', 4=>'size', 5=>'material', 7=>'mount_type', 8=>'battery_size', 10=>'fit' ],
		3  => [ 1=>'product_type', 3=>'color', 4=>'material', 5=>'size' ],
		7  => [ 1=>'product_type', 7=>'color', 12=>'material' ],
		9  => [ 7=>'color', 8=>'fit', 12=>'finish', 13=>'mount_type' ],
		10 => [ 1=>'magnification' ],
		11 => [ 1=>'product_type', 2=>'caliber', 3=>'finish', 4=>'color', 5=>'capacity', 6=>'size', 7=>'material' ],
		15 => [ 1=>'product_type', 2=>'color', 4=>'material', 5=>'finish', 9=>'lock_type' ],
		16 => [ 1=>'product_type', 2=>'gauge', 5=>'finish', 9=>'material' ],
		17 => [ 1=>'product_type', 2=>'size', 7=>'material' ],
		19 => [ 1=>'product_type', 2=>'species', 5=>'color', 6=>'material', 7=>'battery_size' ],
		20 => [ 1=>'product_type', 5=>'battery_size', 9=>'color' ],
		21 => [ 1=>'product_type', 2=>'size', 4=>'battery_size' ],
		22 => [ 1=>'product_type', 2=>'species', 3=>'material', 6=>'battery_size', 7=>'color' ],
		23 => [ 1=>'product_type', 3=>'finish', 4=>'material', 5=>'color' ],
		25 => [ 1=>'product_type', 2=>'color', 3=>'nrr' ],
		27 => [ 2=>'product_type', 11=>'color' ],
		31 => [ 1=>'product_type', 7=>'battery_size', 9=>'color', 10=>'material' ],
		32 => [ 4=>'product_type', 1=>'magnification', 6=>'battery_size' ],
		34 => [ 1=>'product_type', 2=>'size', 9=>'material', 11=>'color' ],
		35 => [ 1=>'product_type', 2=>'caliber', 3=>'size', 8=>'material', 12=>'finish' ],
		38 => [ 1=>'product_type', 2=>'caliber', 6=>'material' ],
		41 => [ 1=>'product_type', 5=>'finish', 7=>'material', 11=>'mount_type' ],
		42 => [ 1=>'product_type', 2=>'material', 5=>'mount_type' ],
		43 => [ 2=>'product_type' ],
		44 => [ 1=>'product_type', 2=>'color', 5=>'lock_type', 6=>'material', 7=>'finish' ],
		45 => [ 1=>'product_type', 2=>'species' ],
		47 => [ 1=>'product_type', 4=>'material', 13=>'color' ],
		50 => [ 1=>'product_type', 2=>'material', 3=>'color', 4=>'fit' ],
		51 => [ 1=>'product_type', 5=>'color', 6=>'material' ],
		52 => [ 1=>'magnification' ],
		53 => [ 2=>'product_type', 6=>'material' ],
		55 => [ 1=>'product_type', 5=>'finish', 6=>'material', 9=>'mount_type' ],
		56 => [ 1=>'product_type', 4=>'size', 5=>'color', 6=>'material' ],
		62 => [ 1=>'product_type', 5=>'battery_size' ],
		68 => [ 1=>'product_type' ],
		73 => [ 1=>'product_type', 2=>'size', 3=>'caliber', 4=>'capacity', 6=>'finish', 7=>'material', 9=>'mount_type' ],
		75 => [ 1=>'product_type', 2=>'color', 3=>'material', 8=>'mount_type', 11=>'caliber' ],
		76 => [ 1=>'product_type', 3=>'color', 4=>'material' ],
		77 => [ 1=>'product_type', 4=>'material', 8=>'capacity' ],
		78 => [ 1=>'magnification' ],
		79 => [ 1=>'product_type', 2=>'caliber', 4=>'material', 7=>'mount_type' ],
		80 => [ 1=>'product_type', 4=>'material', 6=>'mount_type' ],
		81 => [ 1=>'product_type', 2=>'material', 8=>'caliber' ],
		82 => [ 1=>'product_type' ],
		83 => [ 1=>'product_type', 2=>'color', 3=>'nrr', 9=>'material' ],
		84 => [ 1=>'product_type', 4=>'material', 6=>'color' ],
		85 => [ 1=>'product_type', 2=>'species' ],
		87 => [ 1=>'product_type', 2=>'size', 9=>'material', 10=>'caliber' ],
		88 => [ 1=>'product_type', 5=>'material' ],
		89 => [ 4=>'product_type' ],
	];

	public static function resolve( int|string $catId, int $itatrPosition ): ?string
	{
		$catIdInt = (int) $catId;
		if ( isset( self::MAP[ $catIdInt ] ) )
		{
			return self::MAP[ $catIdInt ][ $itatrPosition ] ?? null;
		}
		return null;
	}
}
