<?php
/**
 * @brief  GD FFL Finder — chunked ZIP centroid loader.
 *
 * Reads the bundled US Census ZCTA public-domain CSV into
 * gd_zip_geo in ~5000-row batches so a full-country file
 * (~33-42k rows) loads without a per-page-request timeout.
 *
 * $data shape:
 *   {
 *     file: absolute path to the bundled zip_geo.csv (defaults
 *           to applications/gdffl/data/zip_geo.csv),
 *     total: int (populated in preQueueData)
 *   }
 *
 * gd_zip_geo is the only table this queue writes. Comment lines
 * (leading '#') and the header row are skipped.
 */

namespace IPS\gdffl\extensions\core\Queue;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ZipGeoImport
{
	public int $rowsPerCycle = 5000;

	public function preQueueData( &$data )
	{
		$path = (string) ( $data['file'] ?? '' );
		if ( $path === '' )
		{
			$path = \IPS\ROOT_PATH . '/applications/gdffl/data/zip_geo.csv';
			$data['file'] = $path;
		}
		if ( !is_readable( $path ) ) { return null; }

		$fh = @fopen( $path, 'r' );
		if ( !$fh ) { return null; }

		$count = 0;
		while ( ( $raw = fgets( $fh ) ) !== false )
		{
			$line = ltrim( $raw );
			if ( $line === '' || $line[0] === '#' ) { continue; }
			/* Header row (starts with "zip") — skip. */
			if ( strncasecmp( $line, 'zip', 3 ) === 0 && substr_count( $line, ',' ) >= 2 ) { continue; }
			$count++;
		}
		fclose( $fh );

		$data['total'] = $count;
		return $count;
	}

	public function run( &$data, $offset )
	{
		$path = (string) ( $data['file'] ?? '' );
		if ( $path === '' || !is_readable( $path ) ) { return null; }

		$fh = @fopen( $path, 'r' );
		if ( !$fh ) { return null; }

		$dataRowIndex = -1;
		$batchEnd     = $offset + $this->rowsPerCycle;
		$processed    = 0;

		while ( ( $raw = fgets( $fh ) ) !== false )
		{
			$line = ltrim( $raw );
			if ( $line === '' || $line[0] === '#' ) { continue; }
			if ( strncasecmp( $line, 'zip', 3 ) === 0 && substr_count( $line, ',' ) >= 2 ) { continue; }

			$dataRowIndex++;
			if ( $dataRowIndex < $offset ) { continue; }
			if ( $offset + $processed >= $batchEnd ) { break; }

			$fields = str_getcsv( trim( $line, "\r\n" ) );
			$zip   = preg_replace( '/[^0-9]/', '', (string) ( $fields[0] ?? '' ) );
			if ( strlen( $zip ) < 5 ) { $processed++; continue; }
			$zip   = substr( $zip, 0, 5 );
			$lat   = isset( $fields[1] ) ? (float) $fields[1] : 0.0;
			$lng   = isset( $fields[2] ) ? (float) $fields[2] : 0.0;
			$city  = isset( $fields[3] ) ? trim( (string) $fields[3] ) : null;
			$state = isset( $fields[4] ) ? strtoupper( trim( (string) $fields[4] ) ) : null;

			try
			{
				\IPS\Db::i()->replace( 'gd_zip_geo', [
					'zip'   => $zip,
					'lat'   => $lat,
					'lng'   => $lng,
					'city'  => ( $city !== null && $city !== '' ) ? $city : null,
					'state' => ( $state !== null && $state !== '' ) ? $state : null,
				] );
			}
			catch ( \Throwable ) {}

			$processed++;
		}
		fclose( $fh );

		if ( $processed === 0 ) { return null; }
		return $offset + $processed;
	}

	public function getProgress( $data, $offset )
	{
		$total = (int) ( $data['total'] ?? 0 );
		return [
			'text'     => sprintf( 'Loading ZIP centroids: %d of %d', $offset, $total ),
			'complete' => $total > 0 ? ( $offset * 100 / $total ) : 100,
		];
	}

	public function postComplete( $data ) { /* no-op */ }
}

class ZipGeoImport extends _ZipGeoImport {}
