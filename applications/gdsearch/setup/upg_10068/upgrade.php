<?php

namespace IPS\gdsearch\setup\upg_10068;

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
			'gdsearch_facets_title' => 'Main Catalog Page Facets',
			'gdsearch_facets_intro' => 'These toggles control which facets appear on the main catalog page (all products). Turning one off hides it there only — every facet still appears on its category page when that category has matching products.',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdsearch',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e ) {}
				}
			}
		}
		catch ( \Throwable $e ) {}

		try { unset( \IPS\Data\Store::i()->gdsearch_hidden_facets ); } catch ( \Throwable $e ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable $e ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable $e ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
