<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.3 (consolidates 1.0.1 + 1.0.2 + 1.0.3).
 *
 * Per rule #79 this is the app's ONLY upg_* dir. Safe to run
 * against 1.0.0, 1.0.1, 1.0.2, or a re-install where cached
 * data got wiped. No schema changes in this version — only
 * lang reseed + cache clear.
 *
 * WHY v1.0.3 EXISTS:
 *   v1.0.2's ACP importer built form-action / AJAX-endpoint
 *   URLs with \IPS\Http\Url::internal() WITHOUT the 'admin'
 *   base as the second argument. In the ACP that URL matched
 *   a route that the front dispatcher 301-redirects to the
 *   admin dispatcher — and a 301 on a multipart POST tells
 *   the browser to retry as GET, which drops $_FILES. Result:
 *   uploads landed with empty $_FILES, fflUploadAct bailed on
 *   the "no file" branch, no session job was primed, and the
 *   import never started. gd_ffl stayed at 0 rows through
 *   multiple attempts even though the AJAX architecture from
 *   v1.0.2 was otherwise correct.
 *
 *   v1.0.3 fixes it by passing 'admin' as the second arg to
 *   every Url::internal() call in the ACP importer (mirrors
 *   the pattern used by working ACP forms in gddealer). Also
 *   adds explicit "Start ATF import" / "Load ZIP data" buttons
 *   that inspect uploads/gdffl/ for a pending file, so the
 *   import is startable even if the ACP session flag was lost
 *   across the upload → redirect round-trip.
 *
 * No new lang keys strictly required, but the ZIP-load button
 * label may render differently; reseed the full set to keep
 * the flow bullet-proof (rule #43/#44 shape).
 */

namespace IPS\gdffl\setup\upg_10003;

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
		 * LANG RESEED — full set for v1.0.0 + v1.0.1 + v1.0.2 (no
		 * new keys in v1.0.3). Per rules #43 / #44.
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
		 * EXTENSIONS.JSON SELF-HEAL — rule #16. Both Queue
		 * extensions must stay registered as an optional fallback.
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
		 * CACHE PURGE — v1.0.3 ships new import.php + import.js;
		 * the ACP importer page needs the new interface asset URLs
		 * so the datastore MUST re-resolve on the next request.
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
