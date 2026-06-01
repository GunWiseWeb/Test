<?php
namespace IPS\gdcatalog\setup\upg_10066;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		foreach ( [ 'BackfillAttributes', 'ResolveBrands' ] as $taskKey )
		{
			try
			{
				\IPS\Task\Queue::queue( 'gdcatalog', $taskKey, [ 'offset' => 0 ] );
			}
			catch ( \Throwable ) {}
		}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
