<?php
/**
 * @brief       GD Dealer Manager — Unmatched UPC tracker
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 */

namespace IPS\gddealer\Unmatched;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class UnmatchedUpc
{
	/**
	 * Record a UPC+dealer pair that couldn't be matched to a master catalog
	 * product. Upserts — increments occurrence_count if already tracked,
	 * creates a new row otherwise.
	 */
	public static function record( string $upc, int $dealerId ): void
	{
		$now = date( 'Y-m-d H:i:s' );
		try
		{
			$existing = \IPS\Db::i()->select(
				'*', 'gd_unmatched_upcs', [ 'upc=? AND dealer_id=?', $upc, $dealerId ]
			)->first();

			\IPS\Db::i()->update( 'gd_unmatched_upcs', [
				'last_seen'        => $now,
				'occurrence_count' => (int) $existing['occurrence_count'] + 1,
			], [ 'id=?', (int) $existing['id'] ]);
		}
		catch ( \UnderflowException )
		{
			\IPS\Db::i()->insert( 'gd_unmatched_upcs', [
				'upc'              => $upc,
				'dealer_id'        => $dealerId,
				'first_seen'       => $now,
				'last_seen'        => $now,
				'occurrence_count' => 1,
				'admin_excluded'   => 0,
			]);
		}
	}

	/**
	 * Unmatched UPCs across all dealers sorted by occurrence count.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function loadAll( int $offset = 0, int $limit = 100 ): array
	{
		$rows = [];
		foreach ( \IPS\Db::i()->select(
			'*', 'gd_unmatched_upcs',
			[ 'admin_excluded=?', 0 ],
			'occurrence_count DESC, last_seen DESC',
			[ $offset, $limit ]
		) as $r )
		{
			$rows[] = $r;
		}
		return $rows;
	}

	/**
	 * Unmatched UPCs for a single dealer.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function loadForDealer( int $dealerId, int $offset = 0, int $limit = 100 ): array
	{
		$rows = [];
		foreach ( \IPS\Db::i()->select(
			'*', 'gd_unmatched_upcs',
			[ 'dealer_id=? AND admin_excluded=?', $dealerId, 0 ],
			'occurrence_count DESC',
			[ $offset, $limit ]
		) as $r )
		{
			$rows[] = $r;
		}
		return $rows;
	}

	public static function exclude( int $id ): void
	{
		\IPS\Db::i()->update( 'gd_unmatched_upcs', [ 'admin_excluded' => 1 ], [ 'id=?', $id ]);
	}

	/**
	 * Remove rows for UPCs that have since been added to the master catalog.
	 * Called at the end of each feed import.
	 */
	public static function sweepMatched(): int
	{
		/* v168: \IPS\Db::i()->delete() returns the affected row count
		 * directly (an int). Calling ->rowCount() on it threw fatal. */
		return (int) \IPS\Db::i()->delete(
			'gd_unmatched_upcs',
			'upc IN ( SELECT upc FROM ' . \IPS\Db::i()->prefix . 'gd_catalog )'
		);
	}
}
