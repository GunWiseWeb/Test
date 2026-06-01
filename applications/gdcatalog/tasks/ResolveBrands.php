<?php
namespace IPS\gdcatalog\tasks;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ResolveBrands extends \IPS\Task\Queue\Handler
{
	public string $key = 'ResolveBrands';

	public function run( array &$data, int $offset ): ?int
	{
		$batchSize = 500;
		$count     = 0;

		$brandMap = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'brdno, brdnam', 'gd_sportssouth_brands' ) as $b )
			{
				$brandMap[ (string) $b['brdno'] ] = $b['brdnam'];
			}
		}
		catch ( \Throwable )
		{
			throw new \IPS\Task\Queue\OutOfRangeException;
		}

		if ( empty( $brandMap ) )
		{
			throw new \IPS\Task\Queue\OutOfRangeException;
		}

		$rows = \IPS\Db::i()->select(
			'upc, brand, raw_distributor_data',
			'gd_catalog',
			[ 'primary_source=?', 'sports_south' ],
			'upc ASC',
			[ $offset, $batchSize ]
		);

		foreach ( $rows as $row )
		{
			try
			{
				$raw   = json_decode( (string) $row['raw_distributor_data'], true );
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
						\IPS\Db::i()->update( 'gd_catalog', [ 'brand' => $newBrand ], [ 'upc=?', $row['upc'] ] );
					}
				}
				$count++;
			}
			catch ( \Throwable ) {}
		}

		if ( $count < $batchSize )
		{
			throw new \IPS\Task\Queue\OutOfRangeException;
		}

		return $offset + $batchSize;
	}

	public function getProgress( array $data, int $offset ): array
	{
		$total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'primary_source=?', 'sports_south' ] )->first();
		return [ 'current' => $offset, 'end' => $total ];
	}
}
