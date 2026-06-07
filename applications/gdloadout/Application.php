<?php
namespace IPS\gdloadout;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
/**
 * @version 1.0.0
 */
class _Application extends \IPS\Application
{
	public function get__icon(): string { return 'layer-group'; }
	public function installOther(): void
	{
		require_once \IPS\ROOT_PATH . '/applications/gdloadout/setup/install.php';
	}
}
class Application extends _Application {}
