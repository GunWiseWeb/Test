<?php
/**
 * @brief  GD FFL Finder — row-normalization helpers.
 *
 * Kept as a static helper class rather than an ActiveRecord to
 * avoid the ActiveRecord contract dance during the chunked import
 * (which writes plain arrays via Db::insert()). Stage 2 can wrap
 * the row in an Item if the front-end wants IPS's Content shell.
 */

namespace IPS\gdffl\Ffl;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Ffl
{
	/**
	 * Join the six LIC_* CSV columns into a canonical
	 *   R-DD-CCC-TT-XX-SSSSS
	 * license number. Silently trims each column and defaults
	 * to '' when missing so a partial ATF row still produces a
	 * parseable string.
	 */
	public static function buildLicNumber( array $row ): string
	{
		return implode( '-', [
			trim( (string) ( $row['LIC_REGN']   ?? '' ) ),
			trim( (string) ( $row['LIC_DIST']   ?? '' ) ),
			trim( (string) ( $row['LIC_CNTY']   ?? '' ) ),
			trim( (string) ( $row['LIC_TYPE']   ?? '' ) ),
			trim( (string) ( $row['LIC_XPRDTE'] ?? '' ) ),
			trim( (string) ( $row['LIC_SEQN']   ?? '' ) ),
		] );
	}

	/**
	 * Normalize an ATF PREMISE_ZIP_CODE (5-digit or ZIP+4) down
	 * to the leading 5 digits. Returns '' when the input can't
	 * be reduced to 5 digits so the caller can decide whether
	 * to skip the row or NULL-out the geo.
	 */
	public static function normalizeZip( string $raw ): string
	{
		$digits = preg_replace( '/[^0-9]/', '', $raw );
		if ( strlen( $digits ) < 5 ) { return ''; }
		return substr( $digits, 0, 5 );
	}

	/**
	 * Detect the CSV delimiter from a header line. The ATF file
	 * ships TAB-delimited; older releases were comma-delimited.
	 * Returns "\t" or "," — never anything else.
	 */
	public static function detectDelimiter( string $headerLine ): string
	{
		$tabs   = substr_count( $headerLine, "\t" );
		$commas = substr_count( $headerLine, ',' );
		return $tabs >= $commas ? "\t" : ',';
	}

	/**
	 * Convert a raw ATF row (array indexed by column name) into
	 * the gd_ffl row-shape ready for Db::insert(). Guarded: any
	 * missing / unparseable field falls back to '' or NULL so the
	 * batch keeps flowing on bad input.
	 *
	 *   $zipLatLng — optional cached [zip => [lat, lng]] map so
	 *                the caller can look up centroids without a
	 *                per-row query. Pass an empty array to skip
	 *                the geo populate; the row is still stored
	 *                with lat/lng NULL.
	 */
	public static function toDbRow( array $row, array $zipLatLng = [], int $now = 0 ): array
	{
		$premiseZip = self::normalizeZip( (string) ( $row['PREMISE_ZIP_CODE'] ?? '' ) );
		$business   = trim( (string) ( $row['BUSINESS_NAME'] ?? '' ) );
		$license    = trim( (string) ( $row['LICENSE_NAME']  ?? '' ) );
		if ( $business === '' ) { $business = $license; }

		$lat = null; $lng = null;
		if ( $premiseZip !== '' && isset( $zipLatLng[ $premiseZip ] ) )
		{
			$lat = $zipLatLng[ $premiseZip ][0] ?? null;
			$lng = $zipLatLng[ $premiseZip ][1] ?? null;
		}

		return [
			'lic_number'     => self::buildLicNumber( $row ),
			'lic_type'       => trim( (string) ( $row['LIC_TYPE'] ?? '' ) ),
			'license_name'   => $license   !== '' ? $license   : null,
			'business_name'  => $business  !== '' ? $business  : null,
			'premise_street' => trim( (string) ( $row['PREMISE_STREET'] ?? '' ) ) ?: null,
			'premise_city'   => trim( (string) ( $row['PREMISE_CITY']   ?? '' ) ) ?: null,
			'premise_state'  => strtoupper( trim( (string) ( $row['PREMISE_STATE'] ?? '' ) ) ) ?: null,
			'premise_zip'    => $premiseZip !== '' ? $premiseZip : null,
			'voice_phone'    => trim( (string) ( $row['VOICE_PHONE'] ?? '' ) ) ?: null,
			'lat'            => $lat,
			'lng'            => $lng,
			'imported_at'    => $now > 0 ? $now : time(),
		];
	}
}

class Ffl extends _Ffl {}
