<?php
namespace IPS\gddeals\extensions\core\ContentRouter;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Deals
{
	public array $classes = [];

	public function __construct()
	{
		$this->classes[] = 'IPS\\gddeals\\Deal';
	}
}
class Deals extends _Deals {}
