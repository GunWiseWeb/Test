<?php
namespace IPS\gdcatalog\setup\upg_10080;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		try
		{
			\IPS\Db::i()->update(
				'core_tasks',
				[ 'enabled' => 1 ],
				[ 'app=? AND `key`=?', 'core', 'queue' ]
			);
		}
		catch ( \Throwable ) {}

		foreach ( [ 'BackfillAttributes', 'ResolveBrands' ] as $taskKey )
		{
			try
			{
				\IPS\Db::i()->delete( 'core_queue', [ 'app=? AND `key`=?', 'gdcatalog', $taskKey ] );
				\IPS\Db::i()->insert( 'core_queue', [
					'app'      => 'gdcatalog',
					'key'      => $taskKey,
					'data'     => json_encode( [ 'offset' => 0 ] ),
					'priority' => 5,
				] );
			}
			catch ( \Throwable ) {}
		}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
