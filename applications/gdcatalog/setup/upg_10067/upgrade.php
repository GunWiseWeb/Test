<?php
namespace IPS\gdcatalog\setup\upg_10067;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		try
		{
			$raw = \IPS\Db::i()->select( 'field_mapping', 'gd_distributor_feeds', [ 'distributor=?', 'sports_south' ] )->first();
			$map = json_decode( (string) $raw, true ) ?? [];
			if ( isset( $map['WTPBX'] ) && $map['WTPBX'] === 'weight_oz' )
			{
				$map['WTPBX'] = 'weight_lbs';
				\IPS\Db::i()->update( 'gd_distributor_feeds', [ 'field_mapping' => json_encode( $map ) ], [ 'distributor=?', 'sports_south' ] );
			}
		}
		catch ( \Throwable ) {}

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
