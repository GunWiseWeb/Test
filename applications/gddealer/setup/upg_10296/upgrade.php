<?php

namespace IPS\gddealer\setup\upg_10296;

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
			'gddealer_store_info'    => 'Store Information',
			'gddealer_visit_website' => 'Visit Website',
			'gddealer_sec_info'      => 'Info',
			'gddealer_sec_deals'     => 'Deals',
			'gddealer_sec_coupons'   => 'Coupons',
			'gddealer_sec_listings'  => 'Listings',
			'gddealer_sec_reviews'   => 'Reviews',
			'gddealer_stats'         => 'Stats',
		];
		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $newStrings as $key => $val )
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

		require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		\IPS\gddealer\Setup\CanonicalTemplates::ensure();
		\IPS\gddealer\Setup\CanonicalTemplates::clearCaches();

		return TRUE;
	}
}
class upgrade extends _upgrade {}
