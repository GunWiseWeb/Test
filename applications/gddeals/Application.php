<?php
namespace IPS\gddeals;

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
		return 'tags';
	}

	public static function colorCss(): string
	{
		$defaults = [
			'gddeals_c_primary' => '#1f3a63', 'gddeals_c_primary_hover' => '#16305a',
			'gddeals_c_dealer' => '#1a7f37', 'gddeals_c_dealer_hover' => '#15692e',
			'gddeals_c_discount' => '#d97706', 'gddeals_c_heading' => '#1a2b4a',
			'gddeals_c_text' => '#16181d', 'gddeals_c_text_muted' => '#6b7480',
			'gddeals_c_text_faint' => '#8a93a0', 'gddeals_c_text_strike' => '#9aa3ad',
			'gddeals_c_border' => '#e6e9ee', 'gddeals_c_border_hover' => '#d3d9e0',
			'gddeals_c_border_light' => '#dfe4ea', 'gddeals_c_surface' => '#ffffff',
			'gddeals_c_surface_alt' => '#f1f3f5', 'gddeals_c_promo_bg' => '#fff4e5',
			'gddeals_c_promo_text' => '#8a5a00', 'gddeals_c_ship_bg' => '#e7f4ec',
			'gddeals_c_expired' => '#c0392b', 'gddeals_c_expired_bg' => '#fdecea',
		];
		$out = '';
		foreach ( $defaults as $k => $def )
		{
			$v = (string) \IPS\Settings::i()->$k;
			if ( $v === '' ) { $v = $def; }
			$out .= '--gd-' . substr( $k, 10 ) . ':' . $v . ';';
		}
		return '<style>:root{' . $out . '}</style>';
	}

	public function installOther()
	{
		require_once \IPS\ROOT_PATH . '/applications/gddeals/setup/install.php';
	}
}
class Application extends _Application {}
