<?php

namespace IPS\gdloadout\setup\upg_10036;

class _upgrade
{
	public function step1(): bool
	{
		try { \IPS\Db::i()->delete( 'core_cache', [ 'cache_key LIKE ?', '%furl%' ] ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
