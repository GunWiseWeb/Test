<?php
namespace IPS\gdcatalog\extensions\core\Queue;

use IPS\Db;
use IPS\Extensions\QueueAbstract;
use IPS\Log;
use IPS\Task\Queue\OutOfRangeException as QueueOutOfRangeException;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _BackfillAttributes extends QueueAbstract
{
	public function preQueueData( array $data ): ?array
	{
		try
		{
			$total = (int) Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'primary_source=? AND raw_distributor_data IS NOT NULL', 'sports_south' ] )->first();
			if ( $total === 0 )
			{
				return null;
			}
		}
		catch ( \Throwable )
		{
			return null;
		}

		$data['updated'] = 0;
		return $data;
	}

	public function run( array &$data, int $offset ): int
	{
		$map = \IPS\gdcatalog\Feed\Distributor\SportsSouthAttributeMap::MAP;
		$batchSize = 500;
		$processed = 0;

		try
		{
			$rows = Db::i()->select(
				'upc, raw_distributor_data',
				'gd_catalog',
				[ 'primary_source=? AND raw_distributor_data IS NOT NULL', 'sports_south' ],
				'upc ASC',
				[ $offset, $batchSize ]
			);
		}
		catch ( \Throwable )
		{
			throw new QueueOutOfRangeException;
		}

		foreach ( $rows as $row )
		{
			try
			{
				$raw = json_decode( (string) $row['raw_distributor_data'], true );
				if ( !is_array( $raw ) )
				{
					$processed++;
					continue;
				}

				$catId  = (int) ( $raw['CATID'] ?? 0 );
				$catMap = $map[ $catId ] ?? [];
				$updates = [];

				for ( $i = 1; $i <= 20; $i++ )
				{
					$itatrKey = $i === 10 ? 'ITATR0' : 'ITATR' . $i;
					$val = trim( (string) ( $raw[ $itatrKey ] ?? '' ) );
					if ( $val === '' || !isset( $catMap[ $i ] ) )
					{
						continue;
					}
					$col = $catMap[ $i ];
					if ( $col === 'features' && isset( $updates['features'] ) )
					{
						$updates['features'] .= ', ' . $val;
					}
					else
					{
						$updates[ $col ] = $val;
					}
				}

				if ( !empty( $raw['LENGTH'] ) )  $updates['overall_length'] = (string) $raw['LENGTH'];
				if ( !empty( $raw['WTPBX'] ) )   $updates['weight_lbs']     = (string) $raw['WTPBX'];
				if ( !empty( $raw['IMODEL'] ) )  $updates['model']          = (string) $raw['IMODEL'];
				if ( !empty( $raw['MFGINO'] ) )  $updates['mpn']            = (string) $raw['MFGINO'];
				if ( !empty( $raw['SERIES'] ) )
				{
					$updates['features'] = trim( ( $updates['features'] ?? '' ) . ' ' . $raw['SERIES'] );
				}

				if ( !empty( $updates ) )
				{
					Db::i()->update( 'gd_catalog', $updates, [ 'upc=?', $row['upc'] ] );
					$data['updated'] = ( $data['updated'] ?? 0 ) + 1;
				}
				$processed++;
			}
			catch ( \Throwable ) {}
		}

		if ( $processed < $batchSize )
		{
			throw new QueueOutOfRangeException;
		}

		return $offset + $batchSize;
	}

	public function getProgress( mixed $data, int $offset ): array
	{
		$total = 0;
		try
		{
			$total = (int) Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'primary_source=?', 'sports_south' ] )->first();
		}
		catch ( \Throwable ) {}

		$complete = $total > 0 ? min( 99, round( 100 * $offset / $total, 1 ) ) : 0;
		$updated  = (int) ( $data['updated'] ?? 0 );

		return [
			'text'     => sprintf( 'Backfilling attributes: %d products checked, %d updated', $offset, $updated ),
			'complete' => $complete,
		];
	}

	public function postComplete( array $data, bool $processed = TRUE ): void
	{
		$updated = (int) ( $data['updated'] ?? 0 );
		try { Log::log( 'BackfillAttributes queue completed: ' . $updated . ' products updated', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
	}
}

class BackfillAttributes extends _BackfillAttributes {}
