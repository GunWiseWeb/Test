<?php
namespace IPS\gdcatalog\setup\upg_10079;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		try
		{
			\IPS\Db::i()->delete( 'core_queue', [
				'app=? AND (queue_key=? OR queue_key=?)',
				'gdcatalog',
				'BackfillAttributes',
				'ResolveBrands',
			] );
		}
		catch ( \Throwable ) {}

		try
		{
			\IPS\Task\Queue::queue( 'gdcatalog', 'BackfillAttributes', [ 'offset' => 0 ] );
		}
		catch ( \Throwable ) {}

		try
		{
			\IPS\Task\Queue::queue( 'gdcatalog', 'ResolveBrands', [ 'offset' => 0 ] );
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
