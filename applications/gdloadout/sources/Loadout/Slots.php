<?php
namespace IPS\gdloadout\Loadout;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

class Slots
{
	public const CORE = [
		'base_firearm' => [ 'label' => 'Base Firearm',    'icon' => 'crosshairs',    'accepts' => [ 'Handguns', 'Rifles', 'Shotguns', 'NFA Items' ] ],
		'optic'        => [ 'label' => 'Optic',           'icon' => 'bullseye',      'accepts' => [ 'Optics' ] ],
		'weapon_light' => [ 'label' => 'Weapon Light',    'icon' => 'lightbulb',     'accepts' => [ 'Accessories' ] ],
		'laser'        => [ 'label' => 'Laser / Sight',   'icon' => 'dot-circle',    'accepts' => [ 'Accessories' ] ],
		'suppressor'   => [ 'label' => 'Suppressor',      'icon' => 'volume-mute',   'accepts' => [ 'NFA Items' ] ],
		'foregrip'     => [ 'label' => 'Foregrip / Grip', 'icon' => 'hand-rock',     'accepts' => [ 'Accessories' ] ],
		'sling'        => [ 'label' => 'Sling',           'icon' => 'link',          'accepts' => [ 'Accessories' ] ],
		'holster'      => [ 'label' => 'Holster',         'icon' => 'shield-alt',    'accepts' => [ 'Holsters' ] ],
		'ammo'         => [ 'label' => 'Ammunition',      'icon' => 'box',           'accepts' => [ 'Ammunition' ] ],
		'cleaning'     => [ 'label' => 'Cleaning Kit',    'icon' => 'broom',         'accepts' => [ 'Accessories', 'Cleaning' ] ],
	];

	public static function all(): array
	{
		return static::CORE;
	}
}
