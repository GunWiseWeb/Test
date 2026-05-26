<?php
namespace IPS\gdcatalog\setup\upg_10056;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$sportsSouthMapping = json_encode([
			'ITUPC'   => 'upc',
			'SHDESC'  => 'title',
			'IDESC'   => 'description',
			'SCNAM1'  => 'brand',
			'CATID'   => 'category_id',
			'CPRC'    => 'msrp',
			'PICREF'  => 'image_url',
			'IMODEL'  => 'model',
			'MFGINO'  => 'mpn',
			'WTPBX'   => 'weight_oz',
			'LENGTH'  => 'overall_length',
		]);

		try
		{
			\IPS\Db::i()->update(
				'gd_distributor_feeds',
				[ 'field_mapping' => $sportsSouthMapping ],
				[ 'distributor=?', 'sports_south' ]
			);
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
