<?php

namespace IPS\gdloadout\setup\upg_10009;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$xmlPath = \IPS\ROOT_PATH . '/applications/gdloadout/data/lang.xml';
		if ( file_exists( $xmlPath ) )
		{
			$xml = simplexml_load_file( $xmlPath );
			$langIds = iterator_to_array( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) );
			foreach ( $xml->app->word as $word )
			{
				$key = (string) $word['key'];
				$js  = (int) ( $word['js'] ?? 0 );
				$val = (string) $word;
				foreach ( $langIds as $langId )
				{
					try {
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdloadout',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => $js,
							'word_export'  => 1,
						] );
					} catch ( \Throwable $e ) {}
				}
			}
		}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
