<?php
namespace IPS\gddealer\setup\upg_10216;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$strings = [
			'gddealer_directory_title'       => 'Dealer Directory',
			'gddealer_directory_subtitle'    => '%1$s active dealers on GunRack.deals',
			'gddealer_directory_no_results'  => 'No dealers found',
			'gddealer_directory_search'      => 'Search',
			'gddealer_directory_filter_tier' => 'Tier',
			'gddealer_directory_sort'        => 'Sort By',
		];

		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $strings as $key => $val )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'      => (int) $langId,
						'word_app'     => 'gddealer',
						'word_key'     => $key,
						'word_default' => $val,
						'word_js'      => 0,
						'word_export'  => 1,
					] );
				}
				catch ( \Throwable ) {}
			}
		}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
