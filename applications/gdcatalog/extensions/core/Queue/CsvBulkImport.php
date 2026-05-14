<?php
/**
 * @brief    Background Task - CSV Bulk Import
 * @since    v1.0.38
 *
 * Processes a CSV file in chunks of 100 rows per run() invocation.
 *
 * Workflow:
 *   1. products.php importCsv()/importCsvMap() saves uploaded CSV to a
 *      temp file, enqueues this task with { file_path, mapping, total_rows,
 *      feed_id, member_id }
 *   2. preQueueData validates and seeds counters
 *   3. run() opens the file, seeks to $offset rows past the header, reads
 *      100 rows, processes each
 *   4. Per row:
 *      - Apply mapping to convert CSV columns to canonical fields
 *      - Validate UPC; skip+log if missing
 *      - If UPC exists in catalog: write conflicts for differing fields
 *      - If UPC doesn't exist: create product with primary_source='manual_csv'
 *   5. Returns new offset
 *   6. postComplete: delete temp file, complete the ImportLog
 *
 * Per CLAUDE.md rule #2: dual class declarations required.
 */

namespace IPS\gdcatalog\extensions\core\Queue;

use IPS\Db;
use IPS\Extensions\QueueAbstract;
use IPS\gdcatalog\Catalog\Product;
use IPS\gdcatalog\Log\ImportLog;
use IPS\Log;
use IPS\Task\Queue\OutOfRangeException as QueueOutOfRangeException;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Background Task: CSV Bulk Import
 */
class _CsvBulkImport extends QueueAbstract
{
	/**
	 * Canonical fields admin can populate via CSV. Subset of gd_catalog
	 * columns that v1.0.31 add() allowed. Kept in sync with products.php
	 * editableFields intentionally.
	 */
	protected const CANONICAL_FIELDS = [
		'title', 'mpn', 'brand', 'manufacturer', 'importer', 'model',
		'gun_type', 'caliber', 'action_type', 'capacity', 'barrel_length',
		'overall_length', 'weight_oz', 'finish', 'safety_type',
		'stock_type', 'sight_type', 'receiver_type', 'frame_material',
		'image_url', 'msrp', 'description',
		'nfa_item', 'requires_ffl', 'is_ammo',
	];

	/**
	 * Numeric-typed fields - parsed via floatval / intval, not stored as
	 * raw string.
	 */
	protected const NUMERIC_FIELDS = [
		'capacity', 'barrel_length', 'overall_length', 'weight_oz', 'msrp',
		'rounds_per_box',
	];

	/**
	 * Boolean-typed fields - parsed via truthy/falsy interpretation.
	 */
	protected const BOOLEAN_FIELDS = [
		'nfa_item', 'requires_ffl', 'is_ammo',
	];

	/**
	 * Parse data before queuing. Validates the temp file exists, feed_id is
	 * valid, and mapping is a non-empty array.
	 *
	 * @param array $data Must contain 'file_path', 'mapping', 'total_rows',
	 *                    'feed_id', 'member_id'.
	 * @return array|null
	 */
	public function preQueueData( array $data ): ?array
	{
		$filePath  = (string) ( $data['file_path'] ?? '' );
		$feedId    = (int) ( $data['feed_id'] ?? 0 );
		$totalRows = (int) ( $data['total_rows'] ?? 0 );
		$mapping   = $data['mapping'] ?? [];

		if ( $filePath === '' || !is_file( $filePath ) || !is_readable( $filePath ) )
		{
			try { Log::log( 'CsvBulkImport queue: missing or unreadable file ' . $filePath, 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		if ( $feedId <= 0 )
		{
			try { Log::log( 'CsvBulkImport queue: invalid feed_id ' . $feedId, 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		if ( !is_array( $mapping ) || empty( $mapping ) )
		{
			try { Log::log( 'CsvBulkImport queue: empty or invalid mapping', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		/* Mapping must map at least one CSV column to "upc" - that's the
		 * required field. Reject otherwise. */
		if ( !in_array( 'upc', $mapping, true ) )
		{
			try { Log::log( 'CsvBulkImport queue: no UPC column in mapping', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		/* Start an ImportLog row so per-row conflicts can reference its id. */
		try
		{
			$log = ImportLog::startRun( $feedId, 'manual_csv' );
			$data['import_id'] = (int) $log->id;
		}
		catch ( \Throwable $e )
		{
			try { Log::log( 'CsvBulkImport queue: ImportLog::startRun failed: ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return null;
		}

		$data['rows_processed'] = 0;
		$data['rows_created']   = 0;
		$data['rows_skipped']   = 0;
		$data['rows_conflict']  = 0;
		$data['rows_errored']   = 0;
		$data['started_at']     = time();

		try { Log::log( sprintf( 'CsvBulkImport queue: enqueued file=%s rows=%d feed_id=%d import_id=%d', basename( $filePath ), $totalRows, $feedId, $data['import_id'] ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}

		return $data;
	}

	/**
	 * Run one chunk of the background task.
	 *
	 * $offset = number of data rows (excluding header) already processed.
	 * Each chunk processes up to 100 rows starting at offset.
	 *
	 * @param array $data   Mutable - we increment counters.
	 * @param int   $offset Number of data rows already processed.
	 * @return int  New offset = $offset + rows processed this chunk.
	 * @throws QueueOutOfRangeException When all rows processed.
	 */
	public function run( array &$data, int $offset ): int
	{
		$filePath = (string) ( $data['file_path'] ?? '' );
		$mapping  = (array) ( $data['mapping'] ?? [] );
		$importId = (int) ( $data['import_id'] ?? 0 );

		if ( !is_file( $filePath ) || !is_readable( $filePath ) )
		{
			try { Log::log( 'CsvBulkImport queue run: file gone ' . $filePath, 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		$chunkSize = 100;
		$handle    = fopen( $filePath, 'r' );
		if ( $handle === false )
		{
			try { Log::log( 'CsvBulkImport queue run: fopen failed for ' . $filePath, 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		/* Read+discard header row */
		$header = fgetcsv( $handle );
		if ( $header === false )
		{
			fclose( $handle );
			try { Log::log( 'CsvBulkImport queue run: header read failed', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			throw new QueueOutOfRangeException;
		}

		/* Skip $offset data rows */
		$skipped = 0;
		while ( $skipped < $offset )
		{
			$line = fgetcsv( $handle );
			if ( $line === false )
			{
				fclose( $handle );
				throw new QueueOutOfRangeException;
			}
			$skipped++;
		}

		$processed = 0;
		$created   = 0;
		$skippedRows = 0;
		$conflicted  = 0;
		$errored     = 0;

		while ( $processed < $chunkSize )
		{
			$row = fgetcsv( $handle );
			if ( $row === false )
			{
				break;
			}

			try
			{
				$rowResult = $this->processRow( $row, $header, $mapping, $importId, (int) ( $data['feed_id'] ?? 0 ) );

				switch ( $rowResult )
				{
					case 'created':
						$created++;
						break;
					case 'conflict':
						$conflicted++;
						break;
					case 'skipped':
						$skippedRows++;
						break;
					default:
						$errored++;
				}
			}
			catch ( \Throwable $e )
			{
				$errored++;
				try { Log::log( 'CsvBulkImport row error at offset=' . ( $offset + $processed ) . ': ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			}

			$processed++;
		}

		fclose( $handle );

		$data['rows_processed'] = (int) ( $data['rows_processed'] ?? 0 ) + $processed;
		$data['rows_created']   = (int) ( $data['rows_created']   ?? 0 ) + $created;
		$data['rows_skipped']   = (int) ( $data['rows_skipped']   ?? 0 ) + $skippedRows;
		$data['rows_conflict']  = (int) ( $data['rows_conflict']  ?? 0 ) + $conflicted;
		$data['rows_errored']   = (int) ( $data['rows_errored']   ?? 0 ) + $errored;

		if ( $processed === 0 )
		{
			throw new QueueOutOfRangeException;
		}

		return $offset + $processed;
	}

	/**
	 * Process a single CSV row.
	 *
	 * @return string  'created' | 'conflict' | 'skipped' | 'errored'
	 */
	protected function processRow( array $row, array $header, array $mapping, int $importId, int $feedId ): string
	{
		/* Build canonical record from CSV row using mapping. */
		$canonical = [];
		foreach ( $header as $colIdx => $colName )
		{
			$normalizedHeader = strtolower( trim( (string) $colName ) );
			$canonicalField   = $mapping[ $normalizedHeader ] ?? null;

			if ( $canonicalField === null || $canonicalField === '' || $canonicalField === '__ignore__' )
			{
				continue;
			}

			if ( !isset( $row[ $colIdx ] ) )
			{
				continue;
			}

			$rawValue = trim( (string) $row[ $colIdx ] );

			if ( $rawValue === '' )
			{
				continue;
			}

			$canonical[ $canonicalField ] = $this->coerceValue( $canonicalField, $rawValue );
		}

		/* UPC is required */
		$upc = (string) ( $canonical['upc'] ?? '' );
		$upc = preg_replace( '/[^0-9]/', '', $upc );

		if ( $upc === '' )
		{
			try { Log::log( 'CsvBulkImport: row skipped (missing/invalid UPC)', 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return 'skipped';
		}

		unset( $canonical['upc'] );

		/* Does product exist? */
		try
		{
			$existing = Product::load( $upc );
		}
		catch ( \OutOfRangeException )
		{
			$existing = null;
		}

		if ( $existing === null )
		{
			return $this->createProduct( $upc, $canonical );
		}

		return $this->createConflicts( $existing, $canonical, $importId, $feedId );
	}

	/**
	 * Coerce a CSV string value to the right PHP type for its canonical field.
	 */
	protected function coerceValue( string $field, string $rawValue ): mixed
	{
		if ( in_array( $field, static::BOOLEAN_FIELDS, true ) )
		{
			$lower = strtolower( $rawValue );
			return in_array( $lower, [ '1', 'yes', 'true', 'y', 't' ], true ) ? 1 : 0;
		}

		if ( in_array( $field, static::NUMERIC_FIELDS, true ) )
		{
			$clean = preg_replace( '/[^0-9.\-]/', '', $rawValue );
			return ( $clean === '' || $clean === '.' || $clean === '-' ) ? null : (float) $clean;
		}

		return $rawValue;
	}

	/**
	 * Create a new product with the given canonical fields. NOT auto-locked
	 * per Q3 design decision - admin can run Lock All later if wanted.
	 */
	protected function createProduct( string $upc, array $canonical ): string
	{
		try
		{
			$product = new Product;
			$product->upc = $upc;

			foreach ( $canonical as $field => $value )
			{
				if ( $value === null || $value === '' )
				{
					continue;
				}
				$product->$field = $value;
			}

			$product->primary_source      = 'manual_csv';
			$product->distributor_sources = 'manual_csv';
			$product->record_status       = $product->record_status ?? 'active';
			$product->last_updated        = date( 'Y-m-d H:i:s' );

			$product->save();
			return 'created';
		}
		catch ( \Throwable $e )
		{
			try { Log::log( sprintf( 'CsvBulkImport createProduct(%s) failed: %s', $upc, $e->getMessage() ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			return 'errored';
		}
	}

	/**
	 * Write conflicts for fields where incoming differs from current. Returns
	 * 'conflict' if any conflicts written, 'skipped' if no fields differ.
	 */
	protected function createConflicts( Product $existing, array $canonical, int $importId, int $feedId ): string
	{
		$autoResolveHours = (int) \IPS\Settings::i()->gdcatalog_auto_resolve_hours ?: 48;
		$autoResolveAt    = date( 'Y-m-d H:i:s', time() + ( $autoResolveHours * 3600 ) );
		$detectedAt       = date( 'Y-m-d H:i:s' );

		$conflictsWritten = 0;

		foreach ( $canonical as $field => $incomingValue )
		{
			$currentValue = $existing->$field ?? null;

			/* Normalize for comparison - cast both to string */
			$cur = (string) ( $currentValue ?? '' );
			$inc = (string) ( $incomingValue ?? '' );

			if ( $cur === $inc )
			{
				continue;
			}

			try
			{
				\IPS\Db::i()->insert( 'gd_feed_conflicts', [
					'upc'             => $existing->upc,
					'listing_id'      => null,
					'distributor_id'  => $feedId,
					'field_name'      => $field,
					'current_value'   => $cur,
					'incoming_value'  => $inc,
					'import_id'       => $importId,
					'detected_at'     => $detectedAt,
					'status'          => 'pending',
					'auto_resolve_at' => $autoResolveAt,
					'resolved_by'     => null,
					'resolved_at'     => null,
					'resolution_note' => null,
				] );
				$conflictsWritten++;
			}
			catch ( \Throwable $e )
			{
				try { Log::log( sprintf( 'CsvBulkImport conflict insert failed for upc=%s field=%s: %s', $existing->upc, $field, $e->getMessage() ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			}
		}

		return $conflictsWritten > 0 ? 'conflict' : 'skipped';
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
		$totalRows = (int) ( $data['total_rows']     ?? 0 );
		$processed = (int) ( $data['rows_processed'] ?? 0 );
		$created   = (int) ( $data['rows_created']   ?? 0 );
		$conflict  = (int) ( $data['rows_conflict']  ?? 0 );
		$skipped   = (int) ( $data['rows_skipped']   ?? 0 );
		$errored   = (int) ( $data['rows_errored']   ?? 0 );

		$complete = 0.0;
		if ( $totalRows > 0 )
		{
			$complete = min( 99, round( $processed / $totalRows * 100, 1 ) );
		}

		$text = sprintf(
			'CSV import: %d / %d rows. Created=%d, Conflicts=%d, Skipped=%d, Errored=%d',
			$processed, $totalRows, $created, $conflict, $skipped, $errored
		);

		return [ 'text' => $text, 'complete' => $complete ];
	}

	/**
	 * Called after task completes. Deletes the temp CSV file, completes the
	 * ImportLog row.
	 */
	public function postComplete( array $data, bool $processed = TRUE ): void
	{
		$filePath = (string) ( $data['file_path'] ?? '' );
		$importId = (int) ( $data['import_id'] ?? 0 );

		/* Delete temp file regardless of outcome. */
		if ( $filePath !== '' && is_file( $filePath ) )
		{
			@unlink( $filePath );
		}

		/* Complete the ImportLog row if we have one. */
		if ( $importId > 0 )
		{
			try
			{
				$log = ImportLog::load( $importId );
				$log->complete( [
					'total'   => (int) ( $data['rows_processed'] ?? 0 ),
					'created' => (int) ( $data['rows_created']   ?? 0 ),
					'updated' => 0,
					'skipped' => (int) ( $data['rows_skipped']   ?? 0 ),
					'errored' => (int) ( $data['rows_errored']   ?? 0 ),
					'conflicts' => (int) ( $data['rows_conflict'] ?? 0 ),
				] );
			}
			catch ( \Throwable $e )
			{
				try { Log::log( 'CsvBulkImport postComplete: ImportLog complete failed: ' . $e->getMessage(), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
			}
		}

		$startedAt = (int) ( $data['started_at'] ?? 0 );
		$duration  = $startedAt > 0 ? ( time() - $startedAt ) : 0;

		try { Log::log( sprintf( 'CsvBulkImport postComplete: processed=%d created=%d conflicts=%d skipped=%d errored=%d duration=%ds', (int) ( $data['rows_processed'] ?? 0 ), (int) ( $data['rows_created'] ?? 0 ), (int) ( $data['rows_conflict'] ?? 0 ), (int) ( $data['rows_skipped'] ?? 0 ), (int) ( $data['rows_errored'] ?? 0 ), $duration ), 'gdcatalog_queue' ); } catch ( \Throwable ) {}
	}
}

class CsvBulkImport extends _CsvBulkImport {}
