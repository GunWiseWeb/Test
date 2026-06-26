<?php

namespace IPS\gddealer\setup\upg_10300;

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
		require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		\IPS\gddealer\Setup\CanonicalTemplates::purgeCanonicalTemplates();

		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			try
			{
				\IPS\Db::i()->replace( 'core_sys_lang_words', [
					'lang_id'      => (int) $langId,
					'word_app'     => 'gddealer',
					'word_key'     => 'gddealer_nav',
					'word_default' => 'Navigation',
					'word_js'      => 0,
					'word_export'  => 1,
				] );
			}
			catch ( \Throwable ) {}
		}

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
