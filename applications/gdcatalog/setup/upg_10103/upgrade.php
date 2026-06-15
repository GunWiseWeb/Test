<?php

namespace IPS\gdcatalog\setup\upg_10103;

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
		$db = \IPS\Db::i();

		try
		{
			$exists = (int) $db->select( 'COUNT(*)', 'core_sys_conf_settings', [ 'conf_key=?', 'gdcatalog_auto_resolve_enabled' ] )->first();
			if ( !$exists )
			{
				$db->insert( 'core_sys_conf_settings', [
					'conf_key'     => 'gdcatalog_auto_resolve_enabled',
					'conf_value'   => '0',
					'conf_default' => '0',
					'conf_app'     => 'gdcatalog',
				] );
			}
		}
		catch ( \Throwable ) {}

		$newStrings = [
			'gdcatalog_auto_resolve_enabled'      => 'Auto-resolve feed conflicts',
			'gdcatalog_auto_resolve_enabled_desc'  => 'When off, distributor feed conflicts wait for manual review and are never auto-accepted.',
		];
		try
		{
			foreach ( $db->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						$db->replace( 'core_sys_lang_words', [
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
		}
		catch ( \Throwable ) {}

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
