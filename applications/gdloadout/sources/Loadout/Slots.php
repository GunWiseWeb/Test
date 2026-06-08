<?php

namespace IPS\gdloadout\Loadout;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Slots
{
	public const CORE_SLOTS = [
		'base_firearm'  => [ 'label' => 'Base Firearm', 'icon' => 'fa-solid fa-gun', 'color' => '#dc2626' ],
		'optic'         => [ 'label' => 'Optic', 'icon' => 'fa-solid fa-crosshairs', 'color' => '#2563eb' ],
		'weapon_light'  => [ 'label' => 'Weapon Light', 'icon' => 'fa-solid fa-lightbulb', 'color' => '#eab308' ],
		'laser'         => [ 'label' => 'Laser', 'icon' => 'fa-solid fa-bolt', 'color' => '#dc2626' ],
		'suppressor'    => [ 'label' => 'Suppressor', 'icon' => 'fa-solid fa-volume-xmark', 'color' => '#6b7280' ],
		'foregrip'      => [ 'label' => 'Foregrip', 'icon' => 'fa-solid fa-hand-fist', 'color' => '#78716c' ],
		'rail_mount'    => [ 'label' => 'Rail / Mount', 'icon' => 'fa-solid fa-grip-lines', 'color' => '#0891b2' ],
		'trigger'       => [ 'label' => 'Trigger', 'icon' => 'fa-solid fa-hand-pointer', 'color' => '#be123c' ],
		'stock'         => [ 'label' => 'Stock', 'icon' => 'fa-solid fa-grip', 'color' => '#7c3aed' ],
		'sling'         => [ 'label' => 'Sling', 'icon' => 'fa-solid fa-link', 'color' => '#854d0e' ],
	];

	public const SLOT_CATEGORY = [
		'base_firearm' => [ 'types' => [
			'Handgun'  => [ 'Handguns', 'Pistols', 'Revolvers', 'Derringers', 'Single-Shot Pistols' ],
			'Rifle'    => [ 'Rifles', 'Bolt-Action Rifles', 'Semi-Automatic Rifles', 'Lever-Action Rifles', 'Pump-Action Rifles', 'Single-Shot Rifles', 'Break-Action Rifles', 'Rimfire Rifles' ],
			'Shotgun'  => [ 'Shotguns', 'Semi-Automatic Shotguns', 'Pump-Action Shotguns', 'Over/Under Shotguns', 'Side-by-Side Shotguns', 'Single-Shot Shotguns', 'Break-Action Shotguns' ],
		] ],
		'optic'        => [ 'category' => 'Optics', 'subtypes' => [
			'Red Dot' => 'Red Dot Sights', 'LPVO' => 'LPVOs (1-6x and similar)', 'Scope' => 'Rifle Scopes',
			'Holographic' => 'Holographic Sights', 'Prism' => 'Prism Scopes', 'Magnifier' => 'Magnifiers',
		] ],
		'weapon_light' => [ 'category' => 'Weapon Lights' ],
		'laser'        => [ 'category' => 'Laser Sights' ],
		'suppressor'   => [ 'category' => 'Suppressors' ],
		'foregrip'     => [ 'category' => 'Foregrips & Vertical Grips' ],
		'rail_mount'   => [ 'category' => 'Rails & Mounts' ],
		'trigger'      => [ 'category' => 'Triggers & Trigger Groups' ],
		'stock'        => [ 'category' => 'Stocks & Chassis' ],
		'sling'        => [ 'category' => 'Slings & Swivels' ],
	];

	public const EXTRA_LIBRARY = [
		'magazine'      => 'Magazine',
		'bipod'         => 'Bipod',
		'muzzle_brake'  => 'Muzzle Brake',
		'holster'       => 'Holster',
		'ammo'          => 'Ammo',
		'cleaning'      => 'Cleaning',
		'case'          => 'Case',
		'ear_pro'       => 'Ear Protection',
		'eye_pro'       => 'Eye Protection',
		'targets'       => 'Targets',
		'range_bag'     => 'Range Bag',
	];
}

class Slots extends _Slots {}
