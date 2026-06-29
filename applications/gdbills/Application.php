<?php
namespace IPS\gdbills;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Application extends \IPS\Application
{
	public function get__icon(): string
	{
		return 'gavel';
	}
}

class Application extends _Application {}
