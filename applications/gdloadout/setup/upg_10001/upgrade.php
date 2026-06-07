<?php
namespace IPS\gdloadout\setup\upg_10001;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$words = [
			'__app_gdloadout'              => 'Loadouts',
			'frontnavigation_loadouts'     => 'Loadouts',
			'menutab__gdloadout'           => 'Loadouts',
			'menutab__gdloadout_icon'      => 'layer-group',
			'gdloadout_hub_title'          => 'Loadouts',
			'gdloadout_hub_empty'          => 'No community builds yet — the loadout builder is coming soon.',
			'menu__gdloadout_loadouts_hub' => 'Loadouts',
		];
		try {
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $words as $k => $v )
				{
					try {
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdloadout',
							'word_key'     => $k,
							'word_default' => $v,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					} catch ( \Throwable ) {}
				}
			}
		} catch ( \Throwable ) {}

		try { \IPS\core\FrontNavigation::buildDefaultFrontNavigation(); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->frontNavigation ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
