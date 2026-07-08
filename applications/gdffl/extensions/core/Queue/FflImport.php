<?php
/**
 * @brief  GD FFL Finder — chunked ATF FFL CSV import.
 *
 * Runs under IPS's core queue so the 77k-row ATF full-FFL file is
 * processed in ~2000-row batches; no single web request has to
 * chew through the whole file. The queue runner calls
 *   preQueueData()  once     — returns the total row count
 *   run()           N times  — advances offset by rowsPerCycle
 *   getProgress()   on view  — { text, complete }
 *   postComplete()  once     — cleanup + stamp settings
 *
 * $data shape (as posted by the ACP import controller):
 *   {
 *     file:      absolute path to the uploaded CSV in the work dir
 *     delimiter: "\t" | ","          (already resolved from setting)
 *     mode:      "replace" | "merge"
 *     total:     int (populated in preQueueData)
 *     header:    array of column names (populated in preQueueData)
 *     skipped:   int (running tally)
 *   }
 *
 * gd_ffl is the only table this queue writes to. gd_zip_geo is
 * SELECT-only inside the row-shape helper.
 */

namespace IPS\gdffl\extensions\core\Queue;

use IPS\gdffl\Ffl\Ffl;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _FflImport
{
	public int $rowsPerCycle = 2000;

	/**
	 * Count the data rows and snapshot the header on first pass.
	 * On "replace" mode we TRUNCATE gd_ffl once here so the queue
	 * body only ever INSERTs.
	 */
	public function preQueueData( &$data )
	{
		$path = (string) ( $data['file'] ?? '' );
		if ( $path === '' || !is_readable( $path ) )
		{
			return null;
		}

		$fh = @fopen( $path, 'r' );
		if ( !$fh )
		{
			return null;
		}

		$delim  = (string) ( $data['delimiter'] ?? '' );
		$header = null;
		$count  = 0;

		while ( ( $raw = fgets( $fh ) ) !== false )
		{
			if ( $header === null )
			{
				if ( $delim === '' || $delim === 'auto' )
				{
					$delim = Ffl::detectDelimiter( $raw );
					$data['delimiter'] = $delim;
				}
				$header = str_getcsv( trim( $raw, "\r\n" ), $delim );
				continue;
			}
			if ( trim( $raw ) === '' ) { continue; }
			$count++;
		}
		fclose( $fh );

		$data['header']  = $header ?: [];
		$data['skipped'] = 0;
		$data['total']   = $count;

		if ( ( $data['mode'] ?? 'replace' ) === 'replace' )
		{
			try { \IPS\Db::i()->delete( 'gd_ffl' ); } catch ( \Throwable ) {}
		}

		return $count;
	}

	/**
	 * Read one batch of rows starting at $offset, decorate + insert
	 * each, and return the new offset (or null when done). Per-row
	 * try/catch so one bad line doesn't abort the batch.
	 */
	public function run( &$data, $offset )
	{
		$path = (string) ( $data['file'] ?? '' );
		if ( $path === '' || !is_readable( $path ) ) { return null; }

		$delim  = (string) ( $data['delimiter'] ?? "\t" );
		$header = (array)  ( $data['header']    ?? [] );
		$mode   = (string) ( $data['mode']      ?? 'replace' );
		if ( empty( $header ) ) { return null; }

		$fh = @fopen( $path, 'r' );
		if ( !$fh ) { return null; }

		/* Skip the header + everything up to $offset — cheap enough
		   since fgets() runs at read speed and we don't rebuild the
		   line index. */
		fgets( $fh );
		$skip = (int) $offset;
		while ( $skip > 0 && fgets( $fh ) !== false ) { $skip--; }

		$now      = time();
		$batchEnd = $offset + $this->rowsPerCycle;
		$processed = 0;
		$skipped  = (int) ( $data['skipped'] ?? 0 );

		while ( $offset + $processed < $batchEnd )
		{
			$raw = fgets( $fh );
			if ( $raw === false ) { break; }
			if ( trim( $raw ) === '' ) { continue; }

			$fields = str_getcsv( trim( $raw, "\r\n" ), $delim );
			$row    = [];
			foreach ( $header as $i => $col )
			{
				$row[ (string) $col ] = $fields[ $i ] ?? '';
			}

			try
			{
				$zipLatLng = $this->zipLookup( (string) ( $row['PREMISE_ZIP_CODE'] ?? '' ) );
				$dbRow     = Ffl::toDbRow( $row, $zipLatLng, $now );

				if ( $mode === 'merge' )
				{
					\IPS\Db::i()->insert( 'gd_ffl', $dbRow, TRUE );
				}
				else
				{
					\IPS\Db::i()->insert( 'gd_ffl', $dbRow );
				}
			}
			catch ( \Throwable ) { $skipped++; }

			$processed++;
		}
		fclose( $fh );

		$data['skipped'] = $skipped;

		if ( $processed === 0 )
		{
			return null;
		}
		return $offset + $processed;
	}

	/**
	 * Look up a single ZIP's centroid. Wrapped in try/catch so a
	 * missing gd_zip_geo (queue running before ZIP data loads)
	 * degrades to NULL geo — the row still imports.
	 */
	private function zipLookup( string $rawZip ): array
	{
		$zip = Ffl::normalizeZip( $rawZip );
		if ( $zip === '' ) { return []; }
		try
		{
			$row = \IPS\Db::i()->select( 'lat, lng', 'gd_zip_geo', [ 'zip=?', $zip ] )->first();
			return [ $zip => [ (float) $row['lat'], (float) $row['lng'] ] ];
		}
		catch ( \Throwable )
		{
			return [];
		}
	}

	public function getProgress( $data, $offset )
	{
		$total = (int) ( $data['total'] ?? 0 );
		return [
			'text'     => sprintf( 'Importing ATF FFL rows: %d of %d', $offset, $total ),
			'complete' => $total > 0 ? ( $offset * 100 / $total ) : 100,
		];
	}

	public function postComplete( $data )
	{
		try
		{
			\IPS\Settings::i()->changeValues( [
				'gdffl_last_import_at'      => time(),
				'gdffl_last_import_rows'    => (int) ( $data['total']   ?? 0 ),
				'gdffl_last_import_skipped' => (int) ( $data['skipped'] ?? 0 ),
			] );
		}
		catch ( \Throwable ) {}

		$path = (string) ( $data['file'] ?? '' );
		if ( $path !== '' && is_file( $path ) )
		{
			@unlink( $path );
		}
	}
}

class FflImport extends _FflImport {}
