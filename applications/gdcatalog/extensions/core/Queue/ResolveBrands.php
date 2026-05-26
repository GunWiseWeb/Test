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

class _ResolveBrands extends QueueAbstract
{
	public function preQueueData( array $data ): ?array
	{
		try
		{
			$brandCount = (int) Db::i()->select( 'COUNT(*)', 'gd_sportssouth_brands' )->first();
			if ( $brandCount === 0 )
			{
				return null;
			}
		}
		catch ( \Throwable )
		{
			return null;
		}

		$data['resolved'] = 0;
		return $data;
	}

	public function run( array &$data, int $offset ): int
	{
		$batchSize = 500;
		$processed = 0;

		$brandMap = [];
		try
		{
			foreach ( Db::i()->select( 'brdno, brdnam', 'gd_sportssouth_brands' ) as $b )
			{
				$brandMap[ (string) $b['brdno'] ] = $b['brdnam'];
			}
		}
		catch ( \Throwable )
		{
			throw new QueueOutOfRangeException;
		}

		if ( empty( $brandMap ) )
		{
			throw new QueueOutOfRangeException;
		}

		try
		{
			$rows = Db::i()->select(
				'upc, brand, raw_distributor_data',
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
				$raw = json_decode( $row['raw_distributor_data'], true );
				if ( !is_array( $raw ) )
				{
					$processed++;
					continue;
				}
				$brdno = (string) ( $raw['ITBRDNO'] ?? '' );
				if ( $brdno === '' )
				{
					$brdno = (string) ( $raw['IMFGNO'] ?? '' );
				}
				if ( $brdno !== '' && isset( $brandMap[ $brdno ] ) )
				{
					$newBrand = $brandMap[ $brdno ];
					if ( $newBrand !== $row['brand'] )
					{
						Db::i()->update( 'gd_catalog', [ 'brand' => $newBrand ], [ 'upc=?', $row['upc'] ] );
						$data['resolved'] = ( $data['resolved'] ?? 0 ) + 1;
					}
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
		$resolved = (int) ( $data['resolved'] ?? 0 );

		return [
			'text'     => sprintf( 'Resolving brand names: %d products checked, %d updated', $offset, $resolved ),
			'complete' => $complete,
		];
	}

	public function postComplete( array $data, bool $processed = TRUE ): void
	{
		$resolved = (int) ( $data['resolved'] ?? 0 );
		try { Log::log( 'ResolveBrands queue completed: ' . $resolved . ' products updated', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
	}
}

class ResolveBrands extends _ResolveBrands {}
