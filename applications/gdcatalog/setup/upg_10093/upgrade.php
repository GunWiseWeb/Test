<?php
namespace IPS\gdcatalog\setup\upg_10093;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$db = \IPS\Db::i();

		/* 1) ensure the "Gunsmithing Tools" top-level category exists */
		$slug = 'gunsmithing-tools';
		$newId = 0;
		try { $newId = (int) $db->select( 'id', 'gd_categories', [ 'slug=?', $slug ] )->first(); } catch ( \Throwable ) {}
		if ( !$newId )
		{
			$newId = (int) $db->insert( 'gd_categories', [
				'parent_id' => 0, 'name' => 'Gunsmithing Tools', 'slug' => $slug, 'position' => 800, 'product_count' => 0,
			] );
		}

		/* 2) re-point SS CATID 30 in the LIVE sports_south feed category_mapping */
		try {
			$row = $db->select( 'id, category_mapping', 'gd_distributor_feeds', [ 'distributor=?', 'sports_south' ] )->first();
			$map = json_decode( (string) ( $row['category_mapping'] ?? '{}' ), true );
			if ( !is_array( $map ) ) { $map = []; }
			$map['30'] = $newId;
			$db->update( 'gd_distributor_feeds', [ 'category_mapping' => json_encode( $map ) ], [ 'id=?', (int) $row['id'] ] );
		} catch ( \Throwable ) {}

		/* 3) move existing CATID-30 products into the new category (exact JSON match, not title) */
		$upcs = [];
		foreach ( $db->select( 'upc, raw_distributor_data', 'gd_catalog', [ "raw_distributor_data LIKE ?", '%"CATID":"30"%' ] ) as $r )
		{
			$d = json_decode( (string) $r['raw_distributor_data'], true );
			if ( is_array( $d ) && (string) ( $d['CATID'] ?? '' ) === '30' ) { $upcs[] = $r['upc']; }
		}
		foreach ( array_chunk( $upcs, 200 ) as $chunk )
		{
			try { $db->update( 'gd_catalog', [ 'category_id' => $newId ], $db->in( 'upc', $chunk ) ); } catch ( \Throwable ) {}
		}

		/* 4) best-effort product_count refresh on the new category */
		try {
			$cnt = (int) $db->select( 'COUNT(*)', 'gd_catalog', [ 'category_id=?', $newId ] )->first();
			$db->update( 'gd_categories', [ 'product_count' => $cnt ], [ 'id=?', $newId ] );
		} catch ( \Throwable ) {}

		return TRUE;
	}
}
class upgrade extends _upgrade {}
