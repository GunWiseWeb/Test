<?php
/**
 * @brief    Background Task - Sports South Full Catalog Import
 * @since    v1.0.24
 *
 * Processes the Sports South full catalog (~58k products) in background
 * chunks of 1000 per run() invocation. Uses the dailyItemUpdate API's
 * LastItem parameter to page through results - each chunk picks up where
 * the previous left off, identified by the max ITEMNO seen.
 *
 * Termination: when dailyItemUpdate returns an empty result set, we've
 * reached the end of the catalog and throw OutOfRangeException to signal
 * task completion.
 *
 * Per CLAUDE.md rule #2: dual class declarations required for IPS
 * application classes - we declare \IPS\gdcatalog\extensions\core\Queue\
 * SportsSouthImport at the end after the abstract _SportsSouthImport.
 */

namespace IPS\gdcatalog\extensions\core\Queue;

use IPS\Db;
use IPS\Extensions\QueueAbstract;
use IPS\gdcatalog\Feed\Distributor;
use IPS\gdcatalog\Feed\Distributor\SportsSouthClient;
use IPS\gdcatalog\Feed\Importer;
use IPS\Log;
use IPS\Member;
use IPS\Task\Queue\OutOfRangeException as QueueOutOfRangeException;
use OutOfRangeException;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Background Task: Sports South Full Catalog Import
 */
class _SportsSouthImport extends QueueAbstract
{
	/**
	 * Parse data before queuing.
	 *
	 * Validates the feed exists and is Sports South. Initializes counters.
	 * Returns NULL to abort if invalid.
	 *
	 * @param array $data Must contain 'feed_id'.
	 * @return array|null
	 */
	public function preQueueData( array $data ): ?array
	{
		$feedId = (int) ( $data['feed_id'] ?? 0 );
		if ( $feedId <= 0 )
		{
			try { Log::log( 'SportsSouthImport queue: missing feed_id in queue data', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		try
		{
			$feed = Distributor::load( $feedId );
		}
		catch ( \OutOfRangeException )
		{
			try { Log::log( 'SportsSouthImport queue: feed_id=' . $feedId . ' not found', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		if ( $feed->auth_type !== 'sportssouth' )
		{
			try { Log::log( 'SportsSouthImport queue: feed_id=' . $feedId . ' is not sportssouth (auth_type=' . $feed->auth_type . ')', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		/* Mark the feed as running so the dashboard reflects status */
		try { $feed->markRunning(); } catch ( \Throwable ) {}

		/* Initialize counters in $data - persisted across run() invocations */
		$data['feed_id'] = $feedId;
		$data['chunks_processed']   = 0;
		$data['products_processed'] = 0;
		$data['products_created']   = 0;
		$data['products_updated']   = 0;
		$data['products_errored']   = 0;
		$data['started_at']         = time();

		try { Log::log( 'SportsSouthImport queue: enqueued for feed_id=' . $feedId, 'gdcatalog_queue' ); } catch ( \Throwable ) {}

		return $data;
	}

	/**
	 * Run one chunk of the background task.
	 *
	 * $offset is the LastItem value to pass to dailyItemUpdate. Each chunk
	 * fetches up to 1000 products starting after this item number.
	 *
	 * @param array $data   Mutable - we increment counters in here.
	 * @param int   $offset Current LastItem; 0 on first run.
	 * @return int  New offset = max ITEMNO from this chunk.
	 * @throws QueueOutOfRangeException When catalog is fully consumed.
	 */
	public function run( array &$data, int $offset ): int
	{
		$feedId = (int) ( $data['feed_id'] ?? 0 );

		try
		{
			$feed = Distributor::load( $feedId );
		}
		catch ( \OutOfRangeException )
		{
			try { Log::log( 'SportsSouthImport queue run: feed_id=' . $feedId . ' disappeared mid-task', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		$client = SportsSouthClient::fromDistributor( $feed );

		$credErrors = $client->validate();
		if ( !empty( $credErrors ) )
		{
			try { Log::log( 'SportsSouthImport queue run: feed_id=' . $feedId . ' credentials invalid: ' . implode( '; ', $credErrors ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			try { $feed->markFailed(); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		/* Fetch one chunk - 1000 products starting after $offset (LastItem). */
		try
		{
			/* sinceDate=1/1/1990 returns all products per Sports South docs.
			 * LastItem=$offset pages through results. */
			$products = $client->dailyItemUpdate( '1/1/1990', $offset );
		}
		catch ( \Throwable $e )
		{
			try { Log::log( 'SportsSouthImport queue run: feed_id=' . $feedId . ' API error at offset=' . $offset . ': ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			/* Don't fail the whole task on a single API hiccup - return same
			 * offset and let the next cron tick retry. The queue runner will
			 * eventually give up if it keeps failing. */
			return $offset;
		}

		/* Empty response = end of catalog. */
		if ( empty( $products ) )
		{
			try { Log::log( 'SportsSouthImport queue run: feed_id=' . $feedId . ' reached end at offset=' . $offset . '. Total chunks=' . $data['chunks_processed'] . ' total products=' . $data['products_processed'], 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		/* Process this chunk through the existing Importer pipeline.
		 *
		 * We instantiate Importer in "chunk mode" via runChunk() which:
		 *   1. Enriches each record (brand/category/attrs/picref transforms)
		 *   2. Maps via FieldMapper
		 *   3. Creates or updates each product via processRecord
		 *   4. Returns per-chunk stats
		 *
		 * Importer::runChunk is a new method added in v1.0.24 specifically
		 * for queue-driven imports. It bypasses MAX_RECORDS_PER_RUN and
		 * skips processDiscontinuations (which only runs in postComplete). */
		$chunkStats = [
			'total'   => 0,
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errored' => 0,
		];

		try
		{
			$chunkStats = Importer::runChunk( $feed, $products );
		}
		catch ( \Throwable $e )
		{
			try { Log::log( 'SportsSouthImport queue run: feed_id=' . $feedId . ' chunk processing failed at offset=' . $offset . ': ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			/* Continue past the failed chunk rather than retry-loop infinitely. */
		}

		/* Determine new offset = max ITEMNO in this batch.
		 * Sports South ITEMNO is an integer; we cast/compare numerically. */
		$maxItemno = $offset;
		foreach ( $products as $row )
		{
			$itemno = (int) ( $row['ITEMNO'] ?? 0 );
			if ( $itemno > $maxItemno )
			{
				$maxItemno = $itemno;
			}
		}

		/* If we couldn't find a larger ITEMNO, something is wrong - abort
		 * to prevent infinite loop. */
		if ( $maxItemno <= $offset )
		{
			try { Log::log( 'SportsSouthImport queue run: feed_id=' . $feedId . ' offset stuck at ' . $offset . ' - aborting to prevent infinite loop', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		/* Update counters */
		$data['chunks_processed']++;
		$data['products_processed'] += (int) $chunkStats['total'];
		$data['products_created']   += (int) $chunkStats['created'];
		$data['products_updated']   += (int) $chunkStats['updated'];
		$data['products_errored']   += (int) $chunkStats['errored'];

		return $maxItemno;
	}

	/**
	 * Progress text and percentage for the ACP queue status panel.
	 *
	 * We don't know the exact total products up front (would require an
	 * extra API call to dailyItemCount), so we estimate progress as a
	 * function of chunks processed. Sports South catalog is ~58k = ~58 chunks.
	 *
	 * @param mixed $data
	 * @param int   $offset
	 * @return array{text: string, complete: float}
	 */
	public function getProgress( mixed $data, int $offset ): array
	{
		$feedId    = (int) ( $data['feed_id'] ?? 0 );
		$chunks    = (int) ( $data['chunks_processed'] ?? 0 );
		$processed = (int) ( $data['products_processed'] ?? 0 );

		/* Estimate: 58k products / 1000 per chunk = 58 chunks. Cap at 99%
		 * until we throw OutOfRangeException for the true 100%. */
		$estimatedChunks = 58;
		$complete = $chunks > 0 ? min( 99, round( 100 / $estimatedChunks * $chunks, 1 ) ) : 0;

		$text = sprintf(
			'Sports South full catalog import: %d chunks processed, %d products processed (LastItem=%d)',
			$chunks,
			$processed,
			$offset
		);

		return [ 'text' => $text, 'complete' => $complete ];
	}

	/**
	 * Called after the task completes (whether by reaching the end or
	 * being canceled). Used to record final stats and trigger the
	 * discontinuation pass.
	 *
	 * @param array $data       Final $data after the last run().
	 * @param bool  $processed  True if any chunks ran. False if preQueueData returned null.
	 * @return void
	 */
	public function postComplete( array $data, bool $processed = TRUE ) : void
	{
		if ( !$processed )
		{
			return;
		}

		$feedId  = (int) ( $data['feed_id'] ?? 0 );
		$chunks  = (int) ( $data['chunks_processed'] ?? 0 );
		$total   = (int) ( $data['products_processed'] ?? 0 );
		$created = (int) ( $data['products_created'] ?? 0 );
		$updated = (int) ( $data['products_updated'] ?? 0 );
		$errored = (int) ( $data['products_errored'] ?? 0 );

		try
		{
			$feed = Distributor::load( $feedId );
			$feed->markCompleted( $total );
		}
		catch ( \Throwable ) {}

		try
		{
			Log::log(
				sprintf(
					'SportsSouthImport queue postComplete: feed_id=%d chunks=%d total=%d created=%d updated=%d errored=%d duration=%ds',
					$feedId,
					$chunks,
					$total,
					$created,
					$updated,
					$errored,
					time() - (int) ( $data['started_at'] ?? time() )
				),
				'gdcatalog_queue'
			);
		}
		catch ( \Throwable ) {}
	}
}

class SportsSouthImport extends _SportsSouthImport {}
