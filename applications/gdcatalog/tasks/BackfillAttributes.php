<?php
namespace IPS\gdcatalog\tasks;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _BackfillAttributes extends \IPS\Task\Queue\Handler
{
	public string $key = 'BackfillAttributes';

	public function run( array &$data, int $offset ): ?int
	{
		$map       = \IPS\gdcatalog\Feed\Distributor\SportsSouthAttributeMap::MAP;
		$batchSize = 500;
		$count     = 0;

		$rows = \IPS\Db::i()->select(
			'upc, raw_distributor_data',
			'gd_catalog',
			[ 'primary_source=? AND raw_distributor_data IS NOT NULL', 'sports_south' ],
			'upc ASC',
			[ $offset, $batchSize ]
		);

		foreach ( $rows as $row )
		{
			try
			{
				$raw    = json_decode( (string) $row['raw_distributor_data'], true );
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
					if ( $col === 'features' && isset( $updates['features'] ) && $updates['features'] !== '' )
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

				if ( !empty( $raw['SHDESC'] ) || !empty( $raw['IDESC'] ) )
				{
					$title       = (string) ( $raw['SHDESC'] ?? '' );
					$description = (string) ( $raw['IDESC']  ?? '' );
					$canonicalCatId = 0;
					try
					{
						$canonicalCatId = (int) \IPS\Db::i()->select( 'category_id', 'gd_catalog', [ 'upc=?', $row['upc'] ] )->first();
					}
					catch ( \Throwable ) {}

					$parsed = \IPS\gdcatalog\Feed\TitleParser::parse( $title, $description, $canonicalCatId, $updates );
					foreach ( $parsed as $col => $val )
					{
						if ( !isset( $updates[ $col ] ) || $updates[ $col ] === '' )
						{
							$updates[ $col ] = $val;
						}
					}
				}

				if ( !empty( $updates ) )
				{
					\IPS\Db::i()->update( 'gd_catalog', $updates, [ 'upc=?', $row['upc'] ] );
				}

				try
				{
					$fullProduct = \IPS\Db::i()->select( '*', 'gd_catalog', [ 'upc=?', $row['upc'] ] )->first();
					$score = \IPS\gdcatalog\Feed\CompletenessScorer::score( $fullProduct );
					\IPS\Db::i()->update( 'gd_catalog', [ 'completeness_score' => $score ], [ 'upc=?', $row['upc'] ] );
				}
				catch ( \Throwable ) {}

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
