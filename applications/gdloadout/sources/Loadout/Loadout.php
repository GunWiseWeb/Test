<?php

namespace IPS\gdloadout\Loadout;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Loadout extends \IPS\Patterns\ActiveRecord
{
	public static ?string $databaseTable   = 'gd_loadouts';
	public static string  $databaseColumnId = 'id';
	public static string  $databasePrefix   = '';

	public static function slugify( string $name ): string
	{
		$slug = mb_strtolower( trim( $name ) );
		$slug = preg_replace( '/[^a-z0-9\-]/', '-', $slug );
		$slug = preg_replace( '/-+/', '-', $slug );
		return trim( $slug, '-' ) ?: 'loadout';
	}
}

class Loadout extends _Loadout {}
