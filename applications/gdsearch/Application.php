<?php
/**
 * @brief       GD Search Application Class
 * @package     IPS Community Suite
 * @subpackage  GD Search
 * @since       04 Jun 2026
 * @version     1.0.61
 */
namespace IPS\gdsearch;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _Application extends \IPS\Application
{
    public function get__icon(): string
    {
        return 'magnifying-glass';
    }

    public function installOther()
    {
        require_once \IPS\ROOT_PATH . '/applications/gdsearch/setup/install.php';
    }
}
class Application extends _Application {}
