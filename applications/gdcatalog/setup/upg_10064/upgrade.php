<?php
namespace IPS\gdcatalog\setup\upg_10064;
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
			if ( isset( $map['SCNAM1'] ) )
			{
				unset( $map['SCNAM1'] );
				\IPS\Db::i()->update(
					'gd_distributor_feeds',
					[ 'field_mapping' => json_encode( $map ) ],
					[ 'distributor=?', 'sports_south' ]
				);
			}
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
