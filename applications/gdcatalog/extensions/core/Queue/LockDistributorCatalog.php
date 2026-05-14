<?php
/**
 * @brief    Background Task - Lock Distributor Catalog
 * @since    v1.0.36
 *
 * Bulk-locks every populated field on every product where the specified
 * distributor is in distributor_sources. Processes in chunks of 500
 * products per run() invocation.
 *
 * Lock means: each field name is added to Product->locked_fields JSON
 * array. ConflictResolver.isLocked() checks this array - locked fields
 * cannot be overwritten by future imports; incoming changes route to
 * gd_feed_conflicts for admin review.
 *
 * Workflow:
 *   1. dashboard.php lockCatalog() controller enqueues this task with
 *      { feed_id, distributor }
 *   2. preQueueData validates and seeds counters
 *   3. run() processes 500 products per invocation, using offset = last
 *      gd_catalog.id processed
 *   4. When all products processed, throw QueueOutOfRangeException
 *   5. postComplete logs completion
 *
 * Per CLAUDE.md rule #2: dual class declarations required.
 */

namespace IPS\gdcatalog\extensions\core\Queue;

use IPS\Db;
use IPS\Extensions\QueueAbstract;
use IPS\gdcatalog\Catalog\Product;
use IPS\gdcatalog\Feed\Distributor;
use IPS\Log;
use IPS\Task\Queue\OutOfRangeException as QueueOutOfRangeException;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Background Task: Lock Distributor Catalog
 */
class _LockDistributorCatalog extends QueueAbstract
{
	/**
	 * Lockable fields - same canonical list as products.php editableFields
	 * (kept in sync intentionally). If a field on a product has a non-empty
	 * value, it gets added to Product->locked_fields.
	 */
	protected const LOCKABLE_FIELDS = [
		'title', 'mpn', 'brand', 'manufacturer', 'importer', 'model',
		'gun_type', 'caliber', 'action_type', 'capacity', 'barrel_length',
		'overall_length', 'weight_oz', 'finish', 'safety_type',
		'stock_type', 'sight_type', 'receiver_type', 'frame_material',
		'image_url', 'msrp', 'description',
	];

	/**
	 * Parse data before queuing. Validates feed exists. Initializes counters.
	 *
	 * @param array $data Must contain 'feed_id' and 'distributor'.
	 * @return array|null
	 */
	public function preQueueData( array $data ): ?array
	{
		$feedId = (int) ( $data['feed_id'] ?? 0 );
		$distributor = (string) ( $data['distributor'] ?? '' );

		if ( $feedId <= 0 || $distributor === '' )
		{
			try { Log::log( 'LockDistributorCatalog queue: missing feed_id or distributor in queue data', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		try
		{
			$feed = Distributor::load( $feedId );
		}
		catch ( \OutOfRangeException )
		{
			try { Log::log( 'LockDistributorCatalog queue: feed_id=' . $feedId . ' not found', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		/* Count products to lock - this becomes the queue's total for progress. */
		try
		{
			$total = (int) Db::i()->select(
				'COUNT(*)',
				'gd_catalog',
				[ 'FIND_IN_SET(?, distributor_sources)', $distributor ]
			)->first();
		}
		catch ( \Throwable $e )
		{
			try { Log::log( 'LockDistributorCatalog queue preQueueData count failed: ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		if ( $total === 0 )
		{
			try { Log::log( 'LockDistributorCatalog queue: feed_id=' . $feedId . ' distributor=' . $distributor . ' has 0 products to lock - nothing to do', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		$data['feed_id']           = $feedId;
		$data['distributor']       = $distributor;
		$data['total_products']    = $total;
		$data['products_locked']   = 0;
		$data['fields_locked']     = 0;
		$data['chunks_processed']  = 0;
		$data['started_at']        = time();

		try { Log::log( sprintf( 'LockDistributorCatalog queue: enqueued for feed_id=%d distributor=%s (%d products to lock)', $feedId, $distributor, $total ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}

		return $data;
	}

	/**
	 * Run one chunk of the background task.
	 *
	 * $offset is the last gd_catalog.id processed. Each chunk processes 500
	 * products where id > $offset AND FIND_IN_SET(distributor, distributor_sources).
	 *
	 * @param array $data   Mutable - we increment counters.
	 * @param int   $offset Last processed gd_catalog.id.
	 * @return int  New offset = max id from this chunk.
	 * @throws QueueOutOfRangeException When all products processed.
	 */
	public function run( array &$data, int $offset ): int
	{
		$feedId      = (int) ( $data['feed_id'] ?? 0 );
		$distributor = (string) ( $data['distributor'] ?? '' );

		if ( $feedId <= 0 || $distributor === '' )
		{
			try { Log::log( 'LockDistributorCatalog queue run: missing feed_id or distributor', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		$chunkSize       = 500;
		$productsLocked  = 0;
		$fieldsLocked    = 0;
		$maxId           = $offset;

		try
		{
			$rows = Db::i()->select(
				'*',
				'gd_catalog',
				[
					[ 'id > ?', $offset ],
					[ 'FIND_IN_SET(?, distributor_sources)', $distributor ],
				],
				'id ASC',
				$chunkSize
			);

			foreach ( $rows as $row )
			{
				$maxId = max( $maxId, (int) $row['id'] );

				try
				{
					$product = Product::constructFromData( $row );

					$localFieldsLocked = 0;
					foreach ( static::LOCKABLE_FIELDS as $field )
					{
						$value = $product->$field ?? null;
						if ( $value !== null && $value !== '' )
						{
							if ( !$product->isFieldLocked( $field ) )
							{
								$product->lockField( $field );
								$localFieldsLocked++;
							}
						}
					}

					if ( $localFieldsLocked > 0 )
					{
						$product->save();
						$fieldsLocked += $localFieldsLocked;
					}

					$productsLocked++;
				}
				catch ( \Throwable $e )
				{
					try { Log::log( sprintf( 'LockDistributorCatalog queue: failed to lock product id=%d: %s', (int) $row['id'], $e->getMessage() ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { Log::log( 'LockDistributorCatalog queue run failed: ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		$data['products_locked']  = (int) ( $data['products_locked'] ?? 0 ) + $productsLocked;
		$data['fields_locked']    = (int) ( $data['fields_locked'] ?? 0 ) + $fieldsLocked;
		$data['chunks_processed'] = (int) ( $data['chunks_processed'] ?? 0 ) + 1;

		/* If we processed 0 products this chunk, we're done. */
		if ( $productsLocked === 0 )
		{
			try { Log::log( sprintf( 'LockDistributorCatalog queue: reached end. feed_id=%d total_locked=%d fields=%d chunks=%d', $feedId, $data['products_locked'], $data['fields_locked'], $data['chunks_processed'] ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		return $maxId;
	}

	/**
	 * Get progress for the AdminCP background processes UI.
	 *
	 * @param mixed $data
	 * @param int   $offset
	 * @return array{text: string, complete: float}
	 */
	public function getProgress( mixed $data, int $offset ): array
	{
		$total           = (int) ( $data['total_products'] ?? 0 );
		$productsLocked  = (int) ( $data['products_locked'] ?? 0 );
		$fieldsLocked    = (int) ( $data['fields_locked'] ?? 0 );
		$distributor     = (string) ( $data['distributor'] ?? '?' );

		$complete = 0.0;
		if ( $total > 0 )
		{
			$complete = min( 99, round( $productsLocked / $total * 100, 1 ) );
		}

		$text = sprintf(
			'Locking %s catalog: %d / %d products locked, %d fields locked total',
			$distributor,
			$productsLocked,
			$total,
			$fieldsLocked
		);

		return [ 'text' => $text, 'complete' => $complete ];
	}

	/**
	 * Called after the task completes. Logs final stats.
	 *
	 * @param array $data       Final $data after the last run().
	 * @param bool  $processed  True if any chunks ran.
	 * @return void
	 */
	public function postComplete( array $data, bool $processed = TRUE ): void
	{
		if ( !$processed )
		{
			try { Log::log( 'LockDistributorCatalog postComplete: task did not run (preQueueData returned null)', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return;
		}

		$feedId         = (int) ( $data['feed_id'] ?? 0 );
		$distributor    = (string) ( $data['distributor'] ?? '?' );
		$productsLocked = (int) ( $data['products_locked'] ?? 0 );
		$fieldsLocked   = (int) ( $data['fields_locked'] ?? 0 );
		$chunks         = (int) ( $data['chunks_processed'] ?? 0 );
		$startedAt      = (int) ( $data['started_at'] ?? 0 );
		$duration       = $startedAt > 0 ? ( time() - $startedAt ) : 0;

		try { Log::log( sprintf( 'LockDistributorCatalog postComplete: feed_id=%d distributor=%s products=%d fields=%d chunks=%d duration=%ds', $feedId, $distributor, $productsLocked, $fieldsLocked, $chunks, $duration ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
	}
}

class LockDistributorCatalog extends _LockDistributorCatalog {}
