<?php

namespace IPS\gdloadout\extensions\core\FrontNavigation;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Loadouts
{
	public function type(): string
	{
		return 'custom';
	}
}

class Loadouts extends _Loadouts {}
