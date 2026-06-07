<?php
namespace IPS\gdcatalog\setup\upg_10094;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$db = \IPS\Db::i();

		/* resolve category ids by slug (environment-safe) */
		$knivesId = 0; $toolsId = 0;
		try { $knivesId = (int) $db->select( 'id', 'gd_categories', [ 'slug=?', 'knives' ] )->first(); } catch ( \Throwable ) {}
		try { $toolsId  = (int) $db->select( 'id', 'gd_categories', [ 'slug=?', 'gunsmithing-tools' ] )->first(); } catch ( \Throwable ) {}
		if ( !$knivesId ) { return TRUE; } /* nothing safe to do */

		/* collect all CATID-30 upcs (exact JSON match) and their brand */
		$all = [];        /* upc => brand */
		foreach ( $db->select( 'upc, brand, raw_distributor_data', 'gd_catalog', [ "raw_distributor_data LIKE ?", '%"CATID":"30"%' ] ) as $r )
		{
			$d = json_decode( (string) $r['raw_distributor_data'], true );
			if ( is_array( $d ) && (string) ( $d['CATID'] ?? '' ) === '30' ) { $all[ $r['upc'] ] = (string) $r['brand']; }
		}

		/* 1) move ALL CATID-30 back to Knives */
		foreach ( array_chunk( array_keys( $all ), 200 ) as $chunk )
		{
			try { $db->update( 'gd_catalog', [ 'category_id' => $knivesId ], $db->in( 'upc', $chunk ) ); } catch ( \Throwable ) {}
		}

		/* 2) move ONLY confirmed tool brands into Gunsmithing Tools */
		if ( $toolsId )
		{
			$toolBrands = [ 'real avid', 'revo', 'wheeler', 'birchwood casey', 'lyman', 'otis', 'hornady', 'dac' ];
			$toolUpcs = [];
			foreach ( $all as $upc => $brand )
			{
				$b = mb_strtolower( trim( $brand ) );
				foreach ( $toolBrands as $tb ) { if ( $b !== '' && str_contains( $b, $tb ) ) { $toolUpcs[] = $upc; break; } }
			}
			foreach ( array_chunk( $toolUpcs, 200 ) as $chunk )
			{
				try { $db->update( 'gd_catalog', [ 'category_id' => $toolsId ], $db->in( 'upc', $chunk ) ); } catch ( \Throwable ) {}
			}
		}

		/* 3) re-point the LIVE sports_south feed mapping: CATID 30 -> Knives */
		try {
			$row = $db->select( 'id, category_mapping', 'gd_distributor_feeds', [ 'distributor=?', 'sports_south' ] )->first();
			$map = json_decode( (string) ( $row['category_mapping'] ?? '{}' ), true );
			if ( !is_array( $map ) ) { $map = []; }
			$map['30'] = $knivesId;
			$db->update( 'gd_distributor_feeds', [ 'category_mapping' => json_encode( $map ) ], [ 'id=?', (int) $row['id'] ] );
		} catch ( \Throwable ) {}

		/* 4) best-effort product_count refresh */
		foreach ( [ $knivesId, $toolsId ] as $cid )
		{
			if ( !$cid ) { continue; }
			try {
				$cnt = (int) $db->select( 'COUNT(*)', 'gd_catalog', [ 'category_id=?', $cid ] )->first();
				$db->update( 'gd_categories', [ 'product_count' => $cnt ], [ 'id=?', $cid ] );
			} catch ( \Throwable ) {}
		}

		return TRUE;
	}
}
class upgrade extends _upgrade {}
