<?php

namespace IPS\gdloadout\Loadout;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Slots
{
	public const COMPLETE_FIREARM_CORE = [
		'base_firearm'  => [ 'label' => 'Base Firearm', 'icon' => 'fa-solid fa-gun', 'required' => false ],
		'optic'         => [ 'label' => 'Optic', 'icon' => 'fa-solid fa-crosshairs', 'required' => false ],
		'weapon_light'  => [ 'label' => 'Weapon Light', 'icon' => 'fa-solid fa-lightbulb', 'required' => false ],
		'laser'         => [ 'label' => 'Laser', 'icon' => 'fa-solid fa-bolt', 'required' => false ],
		'suppressor'    => [ 'label' => 'Suppressor', 'icon' => 'fa-solid fa-volume-xmark', 'required' => false ],
		'sling'         => [ 'label' => 'Sling', 'icon' => 'fa-solid fa-link', 'required' => false ],
		'rail_mount'    => [ 'label' => 'Rail / Mount', 'icon' => 'fa-solid fa-grip-lines', 'required' => false ],
		'scope_rings'   => [ 'label' => 'Scope Rings', 'icon' => 'fa-solid fa-ring', 'required' => false ],
	];

	public const COMPONENT_CORE = [
		'lower_receiver' => [ 'label' => 'Lower Receiver', 'icon' => 'fa-solid fa-microchip', 'required' => true ],
		'upper_receiver' => [ 'label' => 'Upper Receiver', 'icon' => 'fa-solid fa-microchip', 'required' => false ],
		'barrel'         => [ 'label' => 'Barrel', 'icon' => 'fa-solid fa-wand-magic-sparkles', 'required' => false ],
		'handguard'      => [ 'label' => 'Handguard', 'icon' => 'fa-solid fa-shield-halved', 'required' => false ],
		'muzzle'         => [ 'label' => 'Muzzle Device', 'icon' => 'fa-solid fa-fire', 'required' => false ],
		'bcg'            => [ 'label' => 'Bolt Carrier Group', 'icon' => 'fa-solid fa-gears', 'required' => false ],
		'buffer'         => [ 'label' => 'Buffer & Spring', 'icon' => 'fa-solid fa-compress', 'required' => false ],
		'trigger'        => [ 'label' => 'Trigger', 'icon' => 'fa-solid fa-hand-pointer', 'required' => false ],
		'stock'          => [ 'label' => 'Stock', 'icon' => 'fa-solid fa-grip', 'required' => false ],
		'grip'           => [ 'label' => 'Grip', 'icon' => 'fa-solid fa-hand-fist', 'required' => false ],
		'optic'          => [ 'label' => 'Optic', 'icon' => 'fa-solid fa-crosshairs', 'required' => false ],
		'scope_rings'    => [ 'label' => 'Scope Rings', 'icon' => 'fa-solid fa-ring', 'required' => false ],
		'rail_mount'     => [ 'label' => 'Rail / Mount', 'icon' => 'fa-solid fa-grip-lines', 'required' => false ],
		'weapon_light'   => [ 'label' => 'Weapon Light', 'icon' => 'fa-solid fa-lightbulb', 'required' => false ],
		'laser'          => [ 'label' => 'Laser', 'icon' => 'fa-solid fa-bolt', 'required' => false ],
		'suppressor'     => [ 'label' => 'Suppressor', 'icon' => 'fa-solid fa-volume-xmark', 'required' => false ],
		'sling'          => [ 'label' => 'Sling', 'icon' => 'fa-solid fa-link', 'required' => false ],
	];

	public const ACCESSORY_SLOTS = [
		'magazine'    => [ 'label' => 'Magazine', 'icon' => 'fa-solid fa-layer-group', 'required' => false ],
		'holster'     => [ 'label' => 'Holster', 'icon' => 'fa-solid fa-suitcase', 'required' => false ],
		'ear_eye_pro' => [ 'label' => 'Ear & Eye Protection', 'icon' => 'fa-solid fa-headphones', 'required' => false ],
		'cleaning'    => [ 'label' => 'Cleaning Kit', 'icon' => 'fa-solid fa-broom', 'required' => false ],
		'bipod'       => [ 'label' => 'Bipod', 'icon' => 'fa-solid fa-maximize', 'required' => false ],
	];

	public const SLOT_CATEGORIES = [
		'base_firearm'   => [ 'field' => 'subcategory', 'types' => [
			'Handgun'  => [ 'Handguns', 'Pistols', 'Revolvers', 'Derringers', 'Single-Shot Pistols' ],
			'Rifle'    => [ 'Rifles', 'Bolt-Action Rifles', 'Semi-Automatic Rifles', 'Lever-Action Rifles', 'Pump-Action Rifles', 'Single-Shot Rifles', 'Break-Action Rifles', 'Rimfire Rifles' ],
			'Shotgun'  => [ 'Shotguns', 'Semi-Automatic Shotguns', 'Pump-Action Shotguns', 'Over/Under Shotguns', 'Side-by-Side Shotguns', 'Single-Shot Shotguns', 'Break-Action Shotguns' ],
		] ],
		'optic'          => [ 'field' => 'category',    'category' => 'Optics' ],
		'weapon_light'   => [ 'field' => 'subcategory', 'category' => 'Weapon Lights' ],
		'laser'          => [ 'field' => 'subcategory', 'category' => 'Laser Sights' ],
		'suppressor'     => [ 'field' => 'subcategory', 'category' => 'Suppressors' ],
		'sling'          => [ 'field' => 'subcategory', 'category' => 'Slings & Swivels' ],
		'rail_mount'     => [ 'field' => 'subcategory', 'category' => 'Rails & Mounts' ],
		'scope_rings'    => [ 'field' => 'subcategory', 'category' => 'Scope Rings' ],
		'lower_receiver' => [ 'field' => 'subcategory', 'category' => 'Lower Receivers' ],
		'upper_receiver' => [ 'field' => 'subcategory', 'category' => 'Upper Receivers' ],
		'barrel'         => [ 'field' => 'subcategory', 'category' => 'Barrels' ],
		'handguard'      => [ 'field' => 'subcategory', 'category' => 'Handguards & Forends' ],
		'muzzle'         => [ 'field' => 'subcategory', 'category' => 'Muzzle Devices' ],
		'bcg'            => [ 'field' => 'subcategory', 'category' => 'Bolts & Bolt Carriers' ],
		'buffer'         => [ 'field' => 'subcategory', 'category' => 'Buffers & Springs' ],
		'trigger'        => [ 'field' => 'subcategory', 'category' => 'Triggers & Trigger Groups' ],
		'stock'          => [ 'field' => 'subcategory', 'category' => 'Stocks & Chassis' ],
		'grip'           => [ 'field' => 'subcategory', 'category' => 'Grips & Grip Panels' ],
		'magazine'       => [ 'field' => 'category',    'category' => 'Magazines' ],
		'holster'        => [ 'field' => 'category',    'category' => 'Holsters & Carry' ],
		'ear_eye_pro'    => [ 'field' => 'subcategory', 'category' => 'Ear & Eye Protection' ],
		'cleaning'       => [ 'field' => 'subcategory', 'category' => 'Cleaning Kits' ],
		'bipod'          => [ 'field' => 'subcategory', 'category' => 'Bipods & Monopods' ],
	];

	public const PLATFORMS = [
		'ar15'  => 'AR-15',
		'ar10'  => 'AR-10 / .308',
		'akm'   => 'AKM / AK-47',
		'ak74'  => 'AK-74',
		'other' => 'Other Platform',
	];
}

class Slots extends _Slots {}
