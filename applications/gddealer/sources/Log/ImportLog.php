<?php
/**
 * @brief       GD Dealer Manager — Import log writer
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 */

namespace IPS\gddealer\Log;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ImportLog extends \IPS\Patterns\ActiveRecord
{
	public static ?string $databaseTable   = 'gd_dealer_import_log';
	public static string $databasePrefix   = '';
	public static string $databaseColumnId = 'id';

	/* v169: per-class multiton cache so we don't accidentally
	 * return another ActiveRecord subclass cached at the same PK. */
	protected static array $multitons = [];
	protected static ?array $multitonMap = NULL;

	/**
	 * Open a fresh running log row for a dealer and return it.
	 */
	public static function start( int $dealerId, string $tier ): self
	{
		$id = \IPS\Db::i()->insert( 'gd_dealer_import_log', [
			'dealer_id'         => $dealerId,
			'subscription_tier' => $tier,
			'run_start'         => date( 'Y-m-d H:i:s' ),
			'status'            => 'running',
		]);
		return self::load( $id );
	}

	/**
	 * Mark this log row completed.
	 */
	public function complete( array $stats ): void
	{
		foreach ( [
			'records_total', 'records_created', 'records_updated', 'records_unchanged',
			'records_unmatched', 'records_capped', 'price_drops', 'price_increases', 'alerts_triggered',
		] as $k )
		{
			if ( array_key_exists( $k, $stats ) )
			{
				$this->$k = (int) $stats[ $k ];
			}
		}
		$this->run_end = date( 'Y-m-d H:i:s' );
		$this->status  = 'completed';
		$this->save();
	}

	public function fail( string $error ): void
	{
		$this->run_end   = date( 'Y-m-d H:i:s' );
		$this->status    = 'failed';
		$this->error_log = $error;
		$this->save();
	}

	/**
	 * @return static[]
	 */
	public static function loadForDealer( int $dealerId, int $limit = 50 ): array
	{
		$out = [];
		foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_import_log', [ 'dealer_id=?', $dealerId ], 'run_start DESC', [ 0, $limit ] ) as $row )
		{
			$out[] = static::constructFromData( $row );
		}
		return $out;
	}
}

class ImportLog extends _ImportLog {}
