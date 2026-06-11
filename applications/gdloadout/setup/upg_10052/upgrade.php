<?php

namespace IPS\gdloadout\setup\upg_10052;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$keys = [ 'loadout_updated', 'loadout_upvoted', 'loadout_followed', 'suggestion_received', 'suggestion_resolved' ];
		foreach ( $keys as $k )
		{
			$exists = false;
			try { \IPS\Db::i()->select( 'notification_key', 'core_notification_defaults', [ 'notification_key=?', $k ] )->first(); $exists = true; } catch ( \Throwable ) {}
			if ( !$exists )
			{
				try { \IPS\Db::i()->insert( 'core_notification_defaults', [ 'notification_key' => $k, 'default' => 'inline', 'disabled' => '', 'editable' => 1 ] ); } catch ( \Throwable ) {}
			}
		}

		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
