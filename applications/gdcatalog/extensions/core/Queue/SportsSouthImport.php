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

		/* v1.0.26: Persist feed_id to core_store so postComplete() can
		 * recover it if $data gets cleared during the OutOfRangeException
		 * defensive abort path. */
		try
		{
			\IPS\Data\Store::i()->gdcatalog_active_import_feed_id = $feedId;
			\IPS\Data\Store::i()->gdcatalog_active_import_started  = time();
		}
		catch ( \Throwable ) {}

		$data['chunks_processed']       = 0;
		$data['products_processed']     = 0;
		$data['products_created']       = 0;
		$data['products_updated']       = 0;
		$data['products_errored']       = 0;
		$data['started_at']             = time();
		$data['ss_completed_naturally'] = false;

		/* v1.0.126 (Phase 10): purge any leftover seen-UPCs file +
		 * completion flag from a previous aborted run so the fresh
		 * queue starts with a clean accumulator. Without this, a
		 * re-queued import would inherit stale UPCs and skew the
		 * postComplete discontinuation coverage guard. */
		try
		{
			$upcsPath = self::seenUpcsPath( $feedId );
			if ( is_file( $upcsPath ) ) { @unlink( $upcsPath ); }
			unset( \IPS\Data\Store::i()->{'gdcatalog_ss_completed_naturally_' . $feedId} );
		}
		catch ( \Throwable ) {}

		try { Log::log( 'SportsSouthImport queue: enqueued for feed_id=' . $feedId, 'gdcatalog_queue' ); } catch ( \Throwable ) {}

		return $data;
	}

	/**
	 * v1.0.126 (Phase 10): seen-UPC file path for one feed's
	 * currently-queued full import. One line per UPC (append-safe,
	 * dedup at postComplete). Lives in uploads/ so it survives PHP
	 * restarts across queue ticks. Deleted after discontinuation
	 * runs (natural completion) or on abort/failed completion.
	 */
	protected static function seenUpcsPath( int $feedId ): string
	{
		return \IPS\ROOT_PATH . '/uploads/gdcatalog_ss_seen_upcs_' . $feedId . '.jsonl';
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
			/* v1.0.126 (Phase 10): mark the completion cause so
			 * postComplete can distinguish a natural end-of-catalog
			 * from an abort. BOTH the mutable $data flag AND the
			 * core_store flag are set: $data is the fast path, and
			 * the store flag survives the "$data resets during
			 * OutOfRangeException" case documented on preQueueData:89.
			 * Only when this flag is truthy will postComplete run
			 * discontinuation. */
			$data['ss_completed_naturally'] = true;
			try { \IPS\Data\Store::i()->{'gdcatalog_ss_completed_naturally_' . $feedId} = 1; } catch ( \Throwable ) {}

			try { Log::log( 'SportsSouthImport queue run: feed_id=' . $feedId . ' reached end at offset=' . $offset . '. Total chunks=' . $data['chunks_processed'] . ' total products=' . $data['products_processed'], 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		/* v1.0.126 (Phase 10): accumulate seen UPCs from THIS chunk's
		 * raw payload BEFORE runChunk, so a chunk-processing throw
		 * still counts the UPCs as "observed in source". Uses the
		 * same FieldMapper + UpcValidator pipeline
		 * processNormalizedRecord uses per record — no second UPC
		 * parser. Written as one line per UPC (append-only) to
		 * avoid ballooning the queue row with a 58k-entry array;
		 * postComplete reads and dedupes into an array<string, true>
		 * before handing off to processDiscontinuationsForSeenUpcs. */
		try
		{
			$fm       = new \IPS\gdcatalog\Feed\FieldMapper( $feed->field_mapping );
			$upcsPath = self::seenUpcsPath( $feedId );
			$fh       = @fopen( $upcsPath, 'a' );
			if ( $fh )
			{
				foreach ( $products as $raw )
				{
					$rawUpc = $fm->extractUpc( is_array( $raw ) ? $raw : [] );
					if ( $rawUpc === null ) { continue; }
					$upc = \IPS\gdcatalog\Feed\UpcValidator::normalize( $rawUpc );
					if ( $upc === null || $upc === '' ) { continue; }
					fwrite( $fh, $upc . "\n" );
				}
				fclose( $fh );
			}
		}
		catch ( \Throwable $e )
		{
			try { Log::log( 'SportsSouthImport queue run: seen-UPC append failed feed_id=' . $feedId . ': ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
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
		/* v1.0.26: Recover feed_id from core_store if $data was reset
		 * (which happens when OutOfRangeException is thrown from run()
		 * after consuming the entire catalog). */
		$feedIdFromData = (int) ( $data['feed_id'] ?? 0 );
		if ( $feedIdFromData === 0 )
		{
			try
			{
				$recovered = (int) ( \IPS\Data\Store::i()->gdcatalog_active_import_feed_id ?? 0 );
				if ( $recovered > 0 )
				{
					$data['feed_id'] = $recovered;
					try { \IPS\Log::log( 'SportsSouthImport postComplete recovered feed_id=' . $recovered . ' from core_store', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
				}
			}
			catch ( \Throwable ) {}
		}

		/* v1.0.26: If we have a feed_id (recovered or not), mark the
		 * feed completed defensively regardless of $data['chunks_processed']. */
		$feedIdForRecovery = (int) ( $data['feed_id'] ?? 0 );
		if ( $feedIdForRecovery > 0 )
		{
			try
			{
				\IPS\Db::i()->update( 'gd_distributor_feeds',
					[
						'last_run_status' => 'completed',
						'last_run'        => date( 'Y-m-d H:i:s' ),
					],
					[ 'id=?', $feedIdForRecovery ]
				);
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'SportsSouthImport postComplete feed update failed: ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			}
		}

		/* v1.0.26: Clear the core_store keys regardless of outcome. */
		try { unset( \IPS\Data\Store::i()->gdcatalog_active_import_feed_id ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->gdcatalog_active_import_started  ); } catch ( \Throwable ) {}

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

		$feed = null;
		try
		{
			$feed = Distributor::load( $feedId );
			$feed->markCompleted( $total );
		}
		catch ( \Throwable ) {}

		/* v1.0.126 (Phase 10): run the existing discontinuation
		 * algorithm ONCE, ONLY on a natural end-of-catalog completion.
		 * The natural-completion flag is set on the empty-response
		 * branch of run() (both in $data and core_store — the store
		 * copy survives the "$data reset after OutOfRangeException"
		 * path). If either signal is truthy AND we have a valid feed,
		 * hand the accumulated seen-UPC set to
		 * Importer::processDiscontinuationsForSeenUpcs — the same
		 * public helper GenericImport uses, which delegates to
		 * processDiscontinuations's existing 80% coverage guard +
		 * hard floor of 100 + miss-counter threshold. Rules and
		 * thresholds are unchanged: the guard was already able to
		 * distinguish a full SS import (~99% coverage) from a
		 * partial import (<1.7%); this phase simply plumbs the
		 * seenUpcs argument through. Failed / cancelled / aborted
		 * queue runs never set the natural flag → discontinuation
		 * is skipped and the accumulated file is still cleaned up. */
		$naturally = !empty( $data['ss_completed_naturally'] );
		if ( !$naturally )
		{
			try
			{
				$flag = \IPS\Data\Store::i()->{'gdcatalog_ss_completed_naturally_' . $feedId} ?? null;
				$naturally = ( (int) $flag ) === 1;
			}
			catch ( \Throwable ) {}
		}

		if ( $naturally && $feed !== null )
		{
			$seen = self::readSeenUpcs( $feedId );
			if ( !empty( $seen ) )
			{
				try
				{
					Importer::processDiscontinuationsForSeenUpcs( $feed, $seen );
					try { Log::log( sprintf( 'SportsSouthImport postComplete: discontinuation ran feed_id=%d seen=%d', $feedId, count( $seen ) ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
				}
				catch ( \Throwable $e )
				{
					try { Log::log( 'SportsSouthImport postComplete discontinuation FAILED feed_id=' . $feedId . ': ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
				}
			}
			else
			{
				try { Log::log( 'SportsSouthImport postComplete: natural completion but zero seen UPCs feed_id=' . $feedId . ' — discontinuation skipped', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			}
		}
		else if ( !$naturally )
		{
			try { Log::log( 'SportsSouthImport postComplete: NOT a natural completion feed_id=' . $feedId . ' — discontinuation skipped', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
		}

		/* v1.0.126 (Phase 10): cleanup regardless of outcome. The
		 * seen-UPC file has no value beyond this postComplete — either
		 * discontinuation just consumed it, or the run aborted and
		 * the partial set must not survive to skew a future run's
		 * coverage guard. */
		try
		{
			$upcsPath = self::seenUpcsPath( $feedId );
			if ( is_file( $upcsPath ) ) { @unlink( $upcsPath ); }
			unset( \IPS\Data\Store::i()->{'gdcatalog_ss_completed_naturally_' . $feedId} );
		}
		catch ( \Throwable ) {}

		try
		{
			Log::log(
				sprintf(
					'SportsSouthImport queue postComplete: feed_id=%d chunks=%d total=%d created=%d updated=%d errored=%d duration=%ds naturally=%s',
					$feedId,
					$chunks,
					$total,
					$created,
					$updated,
					$errored,
					time() - (int) ( $data['started_at'] ?? time() ),
					$naturally ? '1' : '0'
				),
				'gdcatalog_queue'
			);
		}
		catch ( \Throwable ) {}
	}

	/**
	 * v1.0.126 (Phase 10): read the append-only seen-UPCs file into
	 * a deduped array<string, true>, matching the shape
	 * Importer::processDiscontinuationsForSeenUpcs expects (which is
	 * the same shape processNormalizedRecord's per-record
	 * $this->seenUpcs uses). Streaming line-by-line so a very
	 * large SS catalog (~58k) does not need the whole file in memory
	 * as an array before dedupe.
	 */
	protected static function readSeenUpcs( int $feedId ): array
	{
		$path = self::seenUpcsPath( $feedId );
		if ( !is_file( $path ) ) { return []; }
		$seen = [];
		$fh   = @fopen( $path, 'r' );
		if ( !$fh ) { return []; }
		try
		{
			while ( ( $line = fgets( $fh ) ) !== false )
			{
				$upc = trim( $line );
				if ( $upc !== '' ) { $seen[ $upc ] = true; }
			}
		}
		finally
		{
			@fclose( $fh );
		}
		return $seen;
	}
}

class SportsSouthImport extends _SportsSouthImport {}
