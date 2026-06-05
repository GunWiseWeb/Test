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

class _RebuildSearchIndex extends QueueAbstract
{
	public function preQueueData( array $data ): ?array
	{
		try
		{
			$total = (int) Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'record_status=?', 'active' ] )->first();
		}
		catch ( \Throwable )
		{
			return null;
		}
		if ( $total === 0 )
		{
			return null;
		}

		try
		{
			( new \IPS\gdcatalog\Search\OpenSearchIndexer() )->recreateIndex();
		}
		catch ( \Throwable $e )
		{
			try { Log::log( 'RebuildSearchIndex recreate failed: ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		$data['total']   = $total;
		$data['indexed'] = 0;
		return $data;
	}

	public function run( array &$data, int $offset ): int
	{
		$batchSize = 250;
		try
		{
			$n = ( new \IPS\gdcatalog\Search\OpenSearchIndexer() )->indexBatch( $offset, $batchSize );
		}
		catch ( \Throwable )
		{
			throw new QueueOutOfRangeException;
		}

		$data['indexed'] = (int) ( $data['indexed'] ?? 0 ) + $n;

		if ( $n < $batchSize )
		{
			throw new QueueOutOfRangeException;
		}
		return $offset + $batchSize;
	}

	public function getProgress( array $data, int $offset ): array
	{
		$total    = (int) ( $data['total'] ?? 0 );
		$complete = $total > 0 ? min( 99, round( 100 * $offset / $total, 1 ) ) : 0;
		return [
			'text'     => sprintf( 'Rebuilding search index: %d / %d products', min( $offset, $total ), $total ),
			'complete' => $complete,
		];
	}

	public function postComplete( array $data, bool $processed = TRUE ): void
	{
		try { Log::log( 'RebuildSearchIndex completed: ' . (int) ( $data['indexed'] ?? 0 ) . ' indexed', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
	}
}

class RebuildSearchIndex extends _RebuildSearchIndex {}
