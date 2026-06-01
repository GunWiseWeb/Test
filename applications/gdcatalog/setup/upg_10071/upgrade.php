<?php
namespace IPS\gdcatalog\setup\upg_10071;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		try
		{
			\IPS\Db::i()->update(
				'gd_distributor_feeds',
				[ 'last_run_status' => 'failed' ],
				[ 'last_run_status=? AND last_run < ?', 'running', date( 'Y-m-d H:i:s', time() - 7200 ) ]
			);
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
