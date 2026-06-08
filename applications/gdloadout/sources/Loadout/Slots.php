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
		'sling'         => [ 'label' => 'Sling', 'icon' => 'fa-solid fa-link', 'color' => '#854d0e' ],
		'holster'       => [ 'label' => 'Holster', 'icon' => 'fa-solid fa-briefcase', 'color' => '#7c3aed' ],
		'ammo'          => [ 'label' => 'Ammo', 'icon' => 'fa-solid fa-cubes', 'color' => '#b45309' ],
		'cleaning'      => [ 'label' => 'Cleaning', 'icon' => 'fa-solid fa-spray-can-sparkles', 'color' => '#0d9488' ],
	];

	public const EXTRA_LIBRARY = [
		'magazine'         => 'Magazine',
		'bipod'            => 'Bipod',
		'muzzle_brake'     => 'Muzzle Brake',
		'stock'            => 'Stock',
		'trigger'          => 'Trigger',
		'rail_mount'       => 'Rail / Mount',
		'case'             => 'Case',
		'ear_pro'          => 'Ear Protection',
		'eye_pro'          => 'Eye Protection',
		'targets'          => 'Targets',
		'range_bag'        => 'Range Bag',
	];
}

class Slots extends _Slots {}
