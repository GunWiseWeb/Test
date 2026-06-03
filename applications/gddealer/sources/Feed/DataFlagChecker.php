<?php
namespace IPS\gddealer\Feed;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

class _DataFlagChecker
{
	const CHECK_FIELDS = [
		'mpn'   => 'mpn',
		'title' => 'title',
		'brand' => 'brand',
	];

	public static function check( int $dealerId, string $upc, array $canonical ): void
	{
		try {
			$product = \IPS\Db::i()->select(
				'upc, mpn, title, brand',
				'gd_catalog',
				[ 'upc=?', $upc ]
			)->first();
		} catch ( \Throwable ) {
			return;
		}

		$now = date( 'Y-m-d H:i:s' );

		foreach ( self::CHECK_FIELDS as $feedField => $catalogField ) {
			$dealerVal  = trim( (string) ( $canonical[ $feedField ] ?? '' ) );
			$catalogVal = trim( (string) ( $product[ $catalogField ] ?? '' ) );

			if ( $dealerVal === '' || $catalogVal === '' ) {
				continue;
			}

			$mismatch = ( strtolower( $dealerVal ) !== strtolower( $catalogVal ) );

			if ( $mismatch ) {
				try {
					$existing = null;
					try {
						$existing = \IPS\Db::i()->select(
							'id, status',
							'gd_dealer_data_flags',
							[ 'dealer_id=? AND upc=? AND flag_type=?', $dealerId, $upc, $feedField ]
						)->first();
					} catch ( \UnderflowException ) {}

					if ( $existing === null ) {
						\IPS\Db::i()->insert( 'gd_dealer_data_flags', [
							'dealer_id'     => $dealerId,
							'upc'           => $upc,
							'flag_type'     => $feedField,
							'dealer_value'  => $dealerVal,
							'catalog_value' => $catalogVal,
							'status'        => 'new',
							'created_at'    => $now,
							'updated_at'    => $now,
						] );
					} elseif ( $existing['status'] === 'resolved' ) {
						\IPS\Db::i()->update( 'gd_dealer_data_flags', [
							'dealer_value'  => $dealerVal,
							'catalog_value' => $catalogVal,
							'status'        => 'new',
							'resolved_at'   => null,
							'updated_at'    => $now,
						], [ 'dealer_id=? AND upc=? AND flag_type=?', $dealerId, $upc, $feedField ] );
					} else {
						\IPS\Db::i()->update( 'gd_dealer_data_flags', [
							'dealer_value'  => $dealerVal,
							'catalog_value' => $catalogVal,
							'updated_at'    => $now,
						], [ 'dealer_id=? AND upc=? AND flag_type=?', $dealerId, $upc, $feedField ] );
					}
				} catch ( \Throwable ) {}
			} else {
				try {
					\IPS\Db::i()->update( 'gd_dealer_data_flags', [
						'status'      => 'resolved',
						'resolved_at' => $now,
						'updated_at'  => $now,
					], [ 'dealer_id=? AND upc=? AND flag_type=? AND status != ?', $dealerId, $upc, $feedField, 'resolved' ] );
				} catch ( \Throwable ) {}
			}
		}
	}
}

class DataFlagChecker extends _DataFlagChecker {}
