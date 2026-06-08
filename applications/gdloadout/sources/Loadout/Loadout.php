<?php

namespace IPS\gdloadout\Loadout;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Loadout
{
	public static function slugify( string $name ): string
	{
		$slug = mb_strtolower( trim( $name ) );
		$slug = preg_replace( '/[^a-z0-9\s\-]/', '', $slug );
		$slug = preg_replace( '/[\s\-]+/', '-', $slug );
		$slug = trim( $slug, '-' );
		return $slug ?: 'loadout';
	}
}

class Loadout extends _Loadout {}
