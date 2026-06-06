<?php
namespace IPS\gdsearch\setup\upg_10027;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		foreach ( [ 'gdsearch_price_drop', 'price_drop' ] as $k )
		{
			try {
				$has = \IPS\Db::i()->select( 'COUNT(*)', 'core_notification_defaults', [ 'notification_key=?', $k ] )->first();
				if ( !$has ) {
					\IPS\Db::i()->insert( 'core_notification_defaults', [ 'notification_key' => $k, 'default' => 'inline,email', 'disabled' => '' ] );
				}
			} catch ( \Throwable ) {}
		}
		try { \IPS\Application::load( 'gdsearch' )->installEmailTemplates(); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
