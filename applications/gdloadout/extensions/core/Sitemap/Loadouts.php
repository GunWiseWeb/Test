<?php

namespace IPS\gdloadout\extensions\core\Sitemap;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Loadouts
{
	public function getUrls(): array
	{
		return [];
	}
}

class Loadouts extends _Loadouts {}
