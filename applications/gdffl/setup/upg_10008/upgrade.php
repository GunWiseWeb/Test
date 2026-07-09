<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.8.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained
 * against 1.0.0 → 1.0.7 (or a re-install).
 *
 * WHY v1.0.8 EXISTS:
 *   modules/front/finder/finder.php search() built the
 *   distance query with
 *     \IPS\Db::i()->preparedQuery( $sql, $binds )->get_result()
 *   Prod's mysqli extension has NO mysqlnd driver — and
 *   mysqli_stmt::get_result() REQUIRES mysqlnd. Every call
 *   returned FALSE with errno 2014 "Commands out of sync",
 *   the fetch loop never entered, and the finder JSON was
 *   {"count":0,"results":[]} for every ZIP even though the
 *   distance / bounding-box / type-filter logic was correct.
 *
 *   v1.0.8 rewrites search() to use IPS's own Db\Select
 *   cursor — \IPS\Db::i()->select( cols, table, whereParam )
 *   iterating with foreach — which does NOT depend on
 *   get_result() and works on this host. Approach:
 *     * SQL:   bounding-box + type filter only, no HAVING,
 *              no LIMIT/OFFSET binding. Buyer lat/lng are
 *              interpolated as float literals (7 decimals,
 *              zero injection surface); every other value
 *              stays a bound param.
 *     * PHP:   iterate the select() cursor, drop rows whose
 *              haversine distance > radius, sort ASC by
 *              distance, then array_slice($all, $off, $per)
 *              for the page. Bounding box keeps the row
 *              count tiny so PHP-side sort/paginate is
 *              cheap.
 *
 * No schema changes.
 */

namespace IPS\gdffl\setup\upg_10008;

use function defined;
use function function_exists;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* ------------------------------------------------------------
		 * LANG RESEED — full historical set. No new keys in v1.0.8.
		 * Rule #43 6-col shape, rule #44 per-row try/catch.
		 * ------------------------------------------------------------ */
		$strings = [
			'module__front_finder'        => 'FFL Finder',
			'gdffl_finder_title'          => 'Find an FFL near you',
			'gdffl_finder_lead'           => 'Enter your ZIP code to find licensed dealers who can receive a transfer for you. Distance is calculated from the ZIP centroid.',
			'gdffl_finder_zip'            => 'Your ZIP code',
			'gdffl_finder_radius'         => 'Within',
			'gdffl_finder_submit'         => 'Find FFLs',
			'gdffl_finder_types'          => 'License types',
			'gdffl_finder_all_types'      => 'Show all license types',
			'gdffl_finder_searching'      => 'Searching…',
			'gdffl_finder_no_results'     => 'No FFLs found within the selected radius. Try a wider search.',
			'gdffl_finder_zip_bad'        => 'Please enter a 5-digit ZIP code.',
			'gdffl_finder_zip_notfound'   => 'That ZIP code is not in our lookup. Try a nearby ZIP.',
			'gdffl_finder_error'          => 'Search failed — please try again in a moment.',
			'gdffl_finder_distance'       => 'mi',
			'gdffl_finder_no_phone'       => 'No phone on file',
			'gdffl_finder_load_more'      => 'Show more results',

			'gdffl_acp_zipgeo_upload'         => 'Upload real Census ZCTA CSV',
			'gdffl_acp_zipgeo_upload_submit'  => 'Upload ZIP centroid file',
			'gdffl_acp_zipgeo_load_hint'      => 'Loads whatever CSV is currently on disk (uploaded copy preferred, then bundled placeholder).',
			'gdffl_err_no_zip_file'           => 'No ZIP centroid file is on disk yet. Upload a real Census ZCTA CSV or drop one into applications/gdffl/data/zip_geo.csv first.',
			'gdffl_import_running_ffl'        => 'ATF FFL import running…',
			'gdffl_import_running_zip'        => 'ZIP centroid import running…',

			'gdffl_acp_import_file'           => 'ATF FFL CSV file',
			'gdffl_acp_zipgeo_file'           => 'Census ZCTA CSV file',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $strings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdffl',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		/* ------------------------------------------------------------
		 * EXTENSIONS.JSON SELF-HEAL — rule #16.
		 * ------------------------------------------------------------ */
		$expected = [
			'core' => [
				'Queue' => [
					'FflImport'    => 'IPS\\gdffl\\extensions\\core\\Queue\\FflImport',
					'ZipGeoImport' => 'IPS\\gdffl\\extensions\\core\\Queue\\ZipGeoImport',
				],
			],
		];
		$extFile = \IPS\ROOT_PATH . '/applications/gdffl/data/extensions.json';
		try
		{
			$current = @file_get_contents( $extFile );
			$decoded = $current ? json_decode( $current, TRUE ) : null;
			$missing = !is_array( $decoded )
				|| !isset( $decoded['core']['Queue']['FflImport'] )
				|| !isset( $decoded['core']['Queue']['ZipGeoImport'] );
			if ( $missing )
			{
				@file_put_contents(
					$extFile,
					json_encode( $expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
				);
			}
		}
		catch ( \Throwable ) {}

		/* ------------------------------------------------------------
		 * CACHE PURGE.
		 * ------------------------------------------------------------ */
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); }    catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
