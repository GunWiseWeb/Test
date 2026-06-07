<?php

namespace IPS\gdloadout\Loadout;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Slots
{
	public const CORE_SLOTS = [
		'base_firearm'  => [ 'icon' => 'crosshairs',  'color' => '#c0392b' ],
		'optic'         => [ 'icon' => 'bullseye',     'color' => '#2980b9' ],
		'weapon_light'  => [ 'icon' => 'lightbulb',    'color' => '#f39c12' ],
		'laser'         => [ 'icon' => 'dot-circle',   'color' => '#e74c3c' ],
		'suppressor'    => [ 'icon' => 'volume-mute',  'color' => '#7f8c8d' ],
		'foregrip'      => [ 'icon' => 'hand-rock',    'color' => '#8e44ad' ],
		'sling'         => [ 'icon' => 'link',         'color' => '#27ae60' ],
		'holster'       => [ 'icon' => 'shield-alt',   'color' => '#2c3e50' ],
		'ammo'          => [ 'icon' => 'box',          'color' => '#d35400' ],
		'cleaning'      => [ 'icon' => 'broom',        'color' => '#16a085' ],
	];

	public const EXTRA_LIBRARY = [
		'Bipod', 'Magazine', 'Muzzle Brake', 'Flash Hider', 'Compensator',
		'Stock', 'Pistol Grip', 'Handguard', 'Rail Cover', 'Trigger',
		'Buffer Tube', 'Charging Handle', 'Bolt Carrier Group', 'Barrel',
		'Scope Mount', 'Red Dot Mount', 'Night Vision', 'Thermal Optic',
		'Bayonet', 'Case', 'Gun Safe', 'Ear Protection', 'Eye Protection',
		'Shooting Bag', 'Range Bag', 'Targets',
	];

	public static function coreSlotKeys(): array
	{
		return array_keys( self::CORE_SLOTS );
	}
}

class Slots extends _Slots {}
