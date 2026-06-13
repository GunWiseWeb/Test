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

	const BRAND_SUFFIXES = [
		'INC', 'LLC', 'LTD', 'CO', 'CORP', 'CORPORATION', 'COMPANY',
		'ARMS', 'FIREARMS', 'MFG', 'MANUFACTURING', 'USA', 'INDUSTRIES',
		'INTERNATIONAL', 'INTL', 'GROUP', 'ENTERPRISES', 'TACTICAL',
	];

	public static function normBasic( string $val ): string
	{
		$val = mb_strtoupper( $val );
		$val = preg_replace( '/[^A-Z0-9\s]/', ' ', $val );
		return trim( preg_replace( '/\s+/', ' ', $val ) );
	}

	public static function brandTokens( string $brand ): array
	{
		$tokens = preg_split( '/\s+/', self::normBasic( $brand ) );
		$tokens = array_diff( $tokens, self::BRAND_SUFFIXES );
		return array_values( array_unique( $tokens ) );
	}

	public static function brandsMatch( string $a, string $b ): bool
	{
		if ( self::normBasic( $a ) === self::normBasic( $b ) ) {
			return true;
		}
		$ta = self::brandTokens( $a );
		$tb = self::brandTokens( $b );
		if ( empty( $ta ) || empty( $tb ) ) {
			return false;
		}
		$shared = array_intersect( $ta, $tb );
		return count( $shared ) > 0;
	}

	public static function normMpn( string $mpn ): string
	{
		return preg_replace( '/[^A-Z0-9]/', '', mb_strtoupper( $mpn ) );
	}

	public static function titlesMatch( string $a, string $b ): bool
	{
		$na = self::normBasic( $a );
		$nb = self::normBasic( $b );
		if ( $na === $nb ) {
			return true;
		}
		if ( mb_strpos( $na, $nb ) !== false || mb_strpos( $nb, $na ) !== false ) {
			return true;
		}
		$ta = preg_split( '/\s+/', $na );
		$tb = preg_split( '/\s+/', $nb );
		$max = max( count( $ta ), count( $tb ) );
		if ( $max === 0 ) {
			return true;
		}
		$shared = count( array_intersect( $ta, $tb ) );
		return ( $shared / $max ) >= 0.6;
	}

	public static function isMismatch( string $field, string $dealerVal, string $catalogVal ): bool
	{
		switch ( $field ) {
			case 'brand':
				return !self::brandsMatch( $dealerVal, $catalogVal );
			case 'mpn':
				return self::normMpn( $dealerVal ) !== self::normMpn( $catalogVal );
			case 'title':
				return !self::titlesMatch( $dealerVal, $catalogVal );
			default:
				return mb_strtolower( $dealerVal ) !== mb_strtolower( $catalogVal );
		}
	}

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

			$mismatch = self::isMismatch( $feedField, $dealerVal, $catalogVal );

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
