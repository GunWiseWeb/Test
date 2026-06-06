<?php
namespace IPS\gdsearch\setup\upg_10028;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				try
				{
					$has = \IPS\Db::i()->select( 'COUNT(*)', 'core_sys_lang_words', [ 'lang_id=? AND word_app=? AND word_key=?', $langId, 'gdsearch', 'notifications__gdsearch_PriceAlerts' ] )->first();
					if ( !$has )
					{
						\IPS\Db::i()->insert( 'core_sys_lang_words', [
							'lang_id'      => $langId,
							'word_app'     => 'gdsearch',
							'word_key'     => 'notifications__gdsearch_PriceAlerts',
							'word_default' => 'Price drop alerts',
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		try { \IPS\Lang::deleteCompiled(); }      catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
