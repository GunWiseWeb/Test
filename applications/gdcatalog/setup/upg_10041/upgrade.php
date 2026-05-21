<?php
namespace IPS\gdcatalog\setup\upg_10041;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$newStrings = [
			'gdcatalog_feed_inactive'         => 'INACTIVE',
			'gdcatalog_feed_status_running'   => 'Running',
			'gdcatalog_feed_status_completed' => 'Completed',
			'gdcatalog_feed_status_failed'    => 'Failed',
			'gdcatalog_feed_test_connection'  => 'Test Connection',
			'menu__gdcatalog_catalog'         => 'GD Catalog',
			'menutab__gdcatalog'              => 'GD Catalog',
			'menutab__gdcatalog_icon'         => 'database',
		];
		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $newStrings as $key => $val )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'      => (int) $langId,
						'word_app'     => 'gdcatalog',
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
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
