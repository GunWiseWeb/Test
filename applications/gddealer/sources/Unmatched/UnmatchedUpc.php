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
	 *
	 * v1.0.180: Optional $snapshot parameter captures the dealer's parsed
	 * canonical fields for this UPC at the moment it was flagged unmatched.
	 * This snapshot powers the gdcatalog admin review queue (v1.0.40 there)
	 * - admin can see title/brand/mpn/image_url/msrp the dealer provided and
	 * pre-fill the catalog add() form. When null, behavior is unchanged
	 * (backward-compatible for any callers that don't pass snapshot data).
	 *
	 * On UPDATE (subsequent occurrence): the snapshot is always overwritten
	 * with the latest values. The freshest dealer data wins, which is what
	 * we want if the dealer fixed a typo or improved the title between runs.
	 *
	 * @param string                $upc       UPC to record
	 * @param int                   $dealerId  Dealer's member_id
	 * @param array<string,mixed>|null $snapshot Optional canonical fields to JSON-encode
	 * @return void
	 */
	public static function record( string $upc, int $dealerId, ?array $snapshot = null ): void
	{
		$now = date( 'Y-m-d H:i:s' );

		/* Encode snapshot if provided. JSON_UNESCAPED_SLASHES preserves
		 * image URLs cleanly. JSON_UNESCAPED_UNICODE preserves international
		 * characters in titles. Returns null if encoding fails. */
		$snapshotJson = null;
		if ( is_array( $snapshot ) && !empty( $snapshot ) )
		{
			try
			{
				$encoded = json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( $encoded !== false )
				{
					$snapshotJson = $encoded;
				}
			}
			catch ( \Throwable )
			{
				/* Bad data shouldn't break unmatched tracking - just skip
				 * the snapshot and continue with the count-only behavior. */
				$snapshotJson = null;
			}
		}

		try
		{
			$existing = \IPS\Db::i()->select(
				'*', 'gd_unmatched_upcs', [ 'upc=? AND dealer_id=?', $upc, $dealerId ]
			)->first();

			$updateData = [
				'last_seen'        => $now,
				'occurrence_count' => (int) $existing['occurrence_count'] + 1,
			];

			/* Only update snapshot_json if caller provided fresh data.
			 * If caller passes null, keep whatever was previously stored
			 * (don't wipe a good snapshot when the next run can't produce one). */
			if ( $snapshotJson !== null )
			{
				$updateData['snapshot_json'] = $snapshotJson;
			}

			\IPS\Db::i()->update( 'gd_unmatched_upcs', $updateData, [ 'id=?', (int) $existing['id'] ]);
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
				'snapshot_json'    => $snapshotJson,
			]);
		}
	}

	/**
	 * Unmatched UPCs across all dealers sorted by occurrence count.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function loadAll( int $offset = 0, int $limit = 100, bool $reportedOnly = false ): array
	{
		$where = [ [ 'admin_excluded=?', 0 ] ];
		if ( $reportedOnly )
		{
			$where[] = [ 'dealer_reported_at IS NOT NULL' ];
		}

		$rows = [];
		foreach ( \IPS\Db::i()->select(
			'*', 'gd_unmatched_upcs',
			$where,
			'( dealer_reported_at IS NOT NULL ) DESC, occurrence_count DESC, last_seen DESC',
			[ $offset, $limit ]
		) as $r )
		{
			$rows[] = $r;
		}
		return $rows;
	}

	public static function countDealerReported(): int
	{
		try
		{
			return (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_unmatched_upcs',
				[ 'admin_excluded=? AND dealer_reported_at IS NOT NULL', 0 ] )->first();
		}
		catch ( \Throwable ) { return 0; }
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
