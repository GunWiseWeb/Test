<?php
/**
 * @brief  GD Compliance — Flag lookup helper
 *
 * Read-only API the catalog / product templates can call to surface a
 * "Restricted for sale into: …" notice. Stays loosely coupled — no
 * direct template wiring at this stage. When gdcompliance is disabled,
 * the helpers return empty results, so any caller gracefully shows
 * nothing instead of throwing.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Flag
{
	/**
	 * Return the distinct restricted state codes for a given UPC.
	 *
	 *   Flag::forUpc('00007121') → ['CA','IL','NJ',...]
	 *
	 * @return string[]
	 */
	public static function forUpc( string $upc ): array
	{
		$upc = trim( $upc );
		if ( $upc === '' ) { return []; }

		$states = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'DISTINCT state_code', 'gd_compliance_flags',
				[ 'upc=?', $upc ], 'state_code ASC'
			) as $row )
			{
				$code = strtoupper( (string) ( is_array( $row ) ? ( $row['state_code'] ?? '' ) : $row ) );
				if ( $code !== '' ) { $states[] = $code; }
			}
		}
		catch ( \Throwable ) { return []; }

		return $states;
	}

	/**
	 * Detail rows for a UPC: per-state {firearm_type, parsed_capacity,
	 * rule_id, reason}. Heavier than forUpc() — use when you need the
	 * "why" not just the "which states".
	 *
	 * @return array<int, array{state_code:string,firearm_type:string,parsed_capacity:?int,rule_id:int,reason:?string}>
	 */
	public static function detailsForUpc( string $upc ): array
	{
		$upc = trim( $upc );
		if ( $upc === '' ) { return []; }

		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'state_code, firearm_type, parsed_capacity, rule_id, reason',
				'gd_compliance_flags', [ 'upc=?', $upc ], 'state_code ASC'
			) as $row )
			{
				$out[] = $row;
			}
		}
		catch ( \Throwable ) {}

		return $out;
	}

	/**
	 * Bulk-load restricted states for many UPCs in one query — for
	 * catalog-list rendering. Returns ['upc' => ['CA','NY',...]].
	 *
	 * @param string[] $upcs
	 * @return array<string, string[]>
	 */
	public static function forUpcs( array $upcs ): array
	{
		$out = [];
		$upcs = array_values( array_filter( array_map( 'strval', $upcs ), fn( $u ) => trim( $u ) !== '' ) );
		if ( empty( $upcs ) ) { return $out; }

		try
		{
			$placeholders = implode( ',', array_fill( 0, count( $upcs ), '?' ) );
			$args         = array_merge( [ "upc IN ({$placeholders})" ], $upcs );
			foreach ( \IPS\Db::i()->select( 'upc, state_code', 'gd_compliance_flags', $args, 'upc ASC, state_code ASC' ) as $row )
			{
				$u  = (string) $row['upc'];
				$st = strtoupper( (string) $row['state_code'] );
				if ( $u === '' || $st === '' ) { continue; }
				$out[ $u ][] = $st;
			}
		}
		catch ( \Throwable ) {}

		return $out;
	}
}

class Flag extends _Flag {}
