<?php
/**
 * @brief       GD Dealer Manager — Dealer Listing ActiveRecord
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 *
 * One row per dealer per UPC in gd_dealer_listings. The feed importer
 * upserts these rows on each feed run and writes a price_history row only
 * when price or stock actually changes.
 */

namespace IPS\gddealer\Listing;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Listing extends \IPS\Patterns\ActiveRecord
{
	public static ?string $databaseTable   = 'gd_dealer_listings';
	public static string $databasePrefix   = '';
	public static string $databaseColumnId = 'id';

	/* v169: per-class multiton cache so we don't accidentally
	 * return another ActiveRecord subclass cached at the same PK. */
	protected static array $multitons = [];
	protected static ?array $multitonMap = NULL;

	const STATUS_ACTIVE       = 'active';
	const STATUS_OUT_OF_STOCK = 'out_of_stock';
	const STATUS_SUSPENDED    = 'suspended';
	const STATUS_DISCONTINUED = 'discontinued';
	const STATUS_CAPPED       = 'capped';

	/**
	 * Load the row for a dealer+UPC pair, or null if none exists.
	 */
	public static function loadFor( int $dealerId, string $upc ): ?self
	{
		try
		{
			$row = \IPS\Db::i()->select(
				'*', 'gd_dealer_listings', [ 'dealer_id=? AND upc=?', $dealerId, $upc ]
			)->first();
			return static::constructFromData( $row );
		}
		catch ( \UnderflowException )
		{
			return null;
		}
	}

	/**
	 * Paginate every listing belonging to a single dealer.
	 *
	 * @return static[]
	 */
	public static function loadForDealer( int $dealerId, int $offset = 0, int $limit = 50, ?string $statusFilter = null ): array
	{
		$where = [ [ 'dealer_id=?', $dealerId ] ];
		if ( $statusFilter !== null )
		{
			$where[] = [ 'listing_status=?', $statusFilter ];
		}

		$out = [];
		foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_listings', $where, 'last_seen_in_feed DESC', [ $offset, $limit ] ) as $row )
		{
			$out[] = static::constructFromData( $row );
		}
		return $out;
	}

	/**
	 * Active in-stock listings for a UPC, ordered by total cost ascending.
	 *
	 * @return array  Raw rows — used by Plugin 3 price comparison. Keeps the
	 *                query close to a single index scan.
	 */
	public static function activeForUpc( string $upc ): array
	{
		$rows = [];
		foreach (
			\IPS\Db::i()->select(
				'*', 'gd_dealer_listings',
				[ 'upc=? AND listing_status=? AND in_stock=?', $upc, self::STATUS_ACTIVE, 1 ]
			) as $row
		) {
			$rows[] = $row;
		}
		return $rows;
	}
}

class Listing extends _Listing {}
