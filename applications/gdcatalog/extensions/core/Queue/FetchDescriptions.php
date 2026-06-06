<?php
namespace IPS\gdcatalog\extensions\core\Queue;

use IPS\Db;
use IPS\Extensions\QueueAbstract;
use IPS\gdcatalog\Feed\Distributor;
use IPS\gdcatalog\Feed\Distributor\SportsSouthClient;
use IPS\Log;
use IPS\Task\Queue\OutOfRangeException as QueueOutOfRangeException;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _FetchDescriptions extends QueueAbstract
{
	public function preQueueData( array $data ): ?array
	{
		try
		{
			$total = (int) Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'raw_distributor_data IS NOT NULL AND desc_fetched=0' ] )->first();
			if ( $total === 0 ) { return null; }
		}
		catch ( \Throwable ) { return null; }

		$data['total']   = $total;
		$data['fetched'] = 0;
		return $data;
	}

	public function run( array &$data, int $offset ): int
	{
		$batchSize = 40;

		$client = null;
		try
		{
			foreach ( Distributor::loadAll() as $f )
			{
				$name = strtolower( (string) ( $f->distributor ?? '' ) );
				if ( str_contains( $name, 'south' ) || str_contains( $name, 'sports' ) )
				{
					$client = SportsSouthClient::fromDistributor( $f );
					break;
				}
			}
		}
		catch ( \Throwable ) {}
		if ( $client === null ) { throw new QueueOutOfRangeException; }

		try
		{
			$rows = Db::i()->select(
				'upc, raw_distributor_data',
				'gd_catalog',
				[ 'raw_distributor_data IS NOT NULL AND desc_fetched=0' ],
				'upc ASC',
				[ 0, $batchSize ]
			);
		}
		catch ( \Throwable ) { throw new QueueOutOfRangeException; }

		$processed = 0;
		foreach ( $rows as $row )
		{
			$update = [ 'desc_fetched' => 1 ];
			try
			{
				$raw    = json_decode( (string) $row['raw_distributor_data'], true );
				$itemno = is_array( $raw ) ? trim( (string) ( $raw['ITEMNO'] ?? '' ) ) : '';
				if ( $itemno !== '' )
				{
					$text = $client->getText( $itemno );
					if ( $text !== '' )
					{
						$update['description_full'] = $text;
						$data['fetched'] = ( $data['fetched'] ?? 0 ) + 1;
					}
					usleep( 150000 );
				}
			}
			catch ( \Throwable ) {}

			try { Db::i()->update( 'gd_catalog', $update, [ 'upc=?', $row['upc'] ] ); } catch ( \Throwable ) {}
			$processed++;
		}

		if ( $processed < $batchSize ) { throw new QueueOutOfRangeException; }
		return $offset + $batchSize;
	}

	public function getProgress( mixed $data, int $offset ): array
	{
		$total = (int) ( $data['total'] ?? 0 );
		$done  = $offset;
		$complete = $total > 0 ? min( 99, round( 100 * $done / $total, 1 ) ) : 0;
		return [
			'text'     => sprintf( 'Fetching product descriptions: %d processed, %d with text', $done, (int) ( $data['fetched'] ?? 0 ) ),
			'complete' => $complete,
		];
	}

	public function postComplete( array $data, bool $processed = TRUE ): void
	{
		try { Log::log( 'FetchDescriptions queue completed: ' . (int) ( $data['fetched'] ?? 0 ) . ' descriptions stored', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
	}
}

class FetchDescriptions extends _FetchDescriptions {}
