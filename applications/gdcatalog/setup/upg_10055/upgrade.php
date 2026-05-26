<?php
namespace IPS\gdcatalog\setup\upg_10055;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$sportsSouthMapping = json_encode([
			'ITUPC'   => 'upc',
			'SHDESC'  => 'title',
			'IDESC'   => 'short_description',
			'SCNAM1'  => 'brand',
			'CATID'   => 'category_id',
			'CPRC'    => 'msrp',
			'QTYOH'   => 'quantity',
			'PICREF'  => 'image_url',
			'ITEMNO'  => 'distributor_sku',
			'ITBRDNO' => 'brand_id',
			'IMFGNO'  => 'manufacturer_id',
			'MFGINO'  => 'manufacturer_sku',
			'IMODEL'  => 'model',
			'SERIES'  => 'series',
			'ITATR1'  => 'attr1',
			'ITATR2'  => 'attr2',
			'ITATR3'  => 'attr3',
			'ITATR4'  => 'attr4',
			'ITATR5'  => 'attr5',
			'ITATR6'  => 'attr6',
			'ITATR7'  => 'attr7',
			'ITATR8'  => 'attr8',
			'ITATR9'  => 'attr9',
			'ITATR0'  => 'attr10',
			'TXTREF'  => 'description_ref',
			'LENGTH'  => 'length',
			'WIDTH'   => 'width',
			'HEIGHT'  => 'height',
			'WTPBX'   => 'weight',
			'UOM'     => 'unit_of_measure',
			'CHGDTE'  => 'last_modified',
		]);

		try
		{
			\IPS\Db::i()->update(
				'gd_distributor_feeds',
				[ 'field_mapping' => $sportsSouthMapping ],
				[ 'distributor=? AND (field_mapping IS NULL OR field_mapping=?)', 'sports_south', '' ]
			);
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
