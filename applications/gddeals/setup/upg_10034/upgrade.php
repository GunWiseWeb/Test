<?php
namespace IPS\gddeals\setup\upg_10034;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$newStrings = [
			'gddeals_c_primary'            => 'Primary (navy)',
			'gddeals_c_primary_hover'      => 'Primary hover',
			'gddeals_c_dealer'             => 'Dealer / verified (green)',
			'gddeals_c_dealer_hover'       => 'Dealer hover',
			'gddeals_c_discount'           => 'Discount (amber)',
			'gddeals_c_heading'            => 'Headings',
			'gddeals_c_text'               => 'Title text',
			'gddeals_c_text_muted'         => 'Muted text',
			'gddeals_c_text_faint'         => 'Faint text',
			'gddeals_c_text_strike'        => 'Strikethrough price',
			'gddeals_c_border'             => 'Card border',
			'gddeals_c_border_hover'       => 'Border hover',
			'gddeals_c_border_light'       => 'Light border / divider',
			'gddeals_c_surface'            => 'Card background',
			'gddeals_c_surface_alt'        => 'Alt background',
			'gddeals_c_promo_bg'           => 'Promo badge background',
			'gddeals_c_promo_text'         => 'Promo badge text',
			'gddeals_c_ship_bg'            => 'Free-shipping badge bg',
			'gddeals_c_expired'            => 'Expired text',
			'gddeals_c_expired_bg'         => 'Expired badge bg',
			'gddeals_colhdr_brand'         => 'Brand colors',
			'gddeals_colhdr_text'          => 'Text',
			'gddeals_colhdr_surfaces'      => 'Surfaces & borders',
			'gddeals_colhdr_badges'        => 'Badges',
			'gddeals_colors_title'         => 'Deal Colors',
			'menu__gddeals_deals_settings' => 'Colors',
			'r__settings_manage'           => 'Manage Deal Colors',
		];

		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $newStrings as $key => $val )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'      => (int) $langId,
						'word_app'     => 'gddeals',
						'word_key'     => $key,
						'word_default' => $val,
						'word_js'      => 0,
						'word_export'  => 1,
					] );
				}
				catch ( \Throwable ) {}
			}
		}

		$colorDefaults = [
			'gddeals_c_primary'       => '#1f3a63',
			'gddeals_c_primary_hover' => '#16305a',
			'gddeals_c_dealer'        => '#1a7f37',
			'gddeals_c_dealer_hover'  => '#15692e',
			'gddeals_c_discount'      => '#d97706',
			'gddeals_c_heading'       => '#1a2b4a',
			'gddeals_c_text'          => '#16181d',
			'gddeals_c_text_muted'    => '#6b7480',
			'gddeals_c_text_faint'    => '#8a93a0',
			'gddeals_c_text_strike'   => '#9aa3ad',
			'gddeals_c_border'        => '#e6e9ee',
			'gddeals_c_border_hover'  => '#d3d9e0',
			'gddeals_c_border_light'  => '#dfe4ea',
			'gddeals_c_surface'       => '#ffffff',
			'gddeals_c_surface_alt'   => '#f1f3f5',
			'gddeals_c_promo_bg'      => '#fff4e5',
			'gddeals_c_promo_text'    => '#8a5a00',
			'gddeals_c_ship_bg'       => '#e7f4ec',
			'gddeals_c_expired'       => '#c0392b',
			'gddeals_c_expired_bg'    => '#fdecea',
		];

		foreach ( $colorDefaults as $key => $default )
		{
			try
			{
				\IPS\Db::i()->replace( 'core_sys_conf_settings', [
					'conf_key'     => $key,
					'conf_value'   => '',
					'conf_default' => $default,
					'conf_app'     => 'gddeals',
				] );
			}
			catch ( \Throwable ) {}
		}

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }     catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
