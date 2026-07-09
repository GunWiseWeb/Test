<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.4.
 *
 * Rule #79 — exactly ONE upg_* dir per app. This upgrade is
 * self-contained: safe to run against 1.0.0, 1.0.1, 1.0.2,
 * or 1.0.3 (or a re-install where cached data got wiped).
 *
 * WHY v1.0.4 EXISTS:
 *   The ACP importer's upload form has been failing under
 *   IPS 5.0.18's ACP dispatcher since v1.0.0 because the
 *   hand-built <form action="…&do=fflUploadAct"> URL got 301-
 *   redirected by the admin dispatcher. A 301 on multipart
 *   POST tells the browser to retry as GET, which drops
 *   $_FILES → the upload never reaches the handler → gd_ffl
 *   stays at 0. Adding the 'admin' base to Url::internal in
 *   v1.0.3 didn't fix it because the CANONICAL working ACP
 *   form idiom is NOT "hand-build the form action + URL", it
 *   is \IPS\Helpers\Form: IPS renders the form against the
 *   CURRENT page URL, injects the required session key + form
 *   key + CSRF, and the framework normalizes the POST route
 *   so no redirect happens.
 *
 *   v1.0.4 rewrites modules/admin/manage/import.php to mirror
 *   applications/gdbills/modules/admin/bills/import.php (also
 *   applications/gdcatalog/modules/admin/catalog/feeds.php),
 *   both of which use \IPS\Helpers\Form + \IPS\Helpers\Form\
 *   Upload and demonstrably work in this same ACP. The ATF-
 *   specific parsing (Ffl::toDbRow, header-name mapping,
 *   fgetcsv, batch AJAX loop) is unchanged. Only the initial
 *   upload mechanism is swapped to the framework-blessed form.
 *
 *   Two new lang keys are added for the Upload field labels:
 *     gdffl_acp_import_file  — "ATF FFL CSV file"
 *     gdffl_acp_zipgeo_file  — "Census ZCTA CSV file"
 *
 * No schema changes in this version.
 */

namespace IPS\gdffl\setup\upg_10004;

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
		 * LANG RESEED — full set for v1.0.0 + v1.0.1 + v1.0.2 +
		 * v1.0.4 (rule #43 6-column shape, rule #44 per-row catch).
		 * ------------------------------------------------------------ */
		$strings = [
			/* v1.0.1 — public finder page. */
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

			/* v1.0.2 — AJAX-driven ACP importer + ZIP admin upload. */
			'gdffl_acp_zipgeo_upload'         => 'Upload real Census ZCTA CSV',
			'gdffl_acp_zipgeo_upload_submit'  => 'Upload ZIP centroid file',
			'gdffl_acp_zipgeo_load_hint'      => 'Loads whatever CSV is currently on disk (uploaded copy preferred, then bundled placeholder).',
			'gdffl_err_no_zip_file'           => 'No ZIP centroid file is on disk yet. Upload a real Census ZCTA CSV or drop one into applications/gdffl/data/zip_geo.csv first.',
			'gdffl_import_running_ffl'        => 'ATF FFL import running…',
			'gdffl_import_running_zip'        => 'ZIP centroid import running…',

			/* v1.0.4 — \IPS\Helpers\Form\Upload field labels. */
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
		 * CACHE PURGE — new import.php + updated lang mean the
		 * datastore MUST re-resolve on the next request.
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
