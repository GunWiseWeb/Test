<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.2 (consolidates 1.0.1 + 1.0.2).
 *
 * Per rule #79 the app carries exactly ONE upg_* dir at a time.
 * This step is self-contained — safe to run against 1.0.0, 1.0.1,
 * or a re-install where cached data got wiped:
 *
 *   1. Reseed the full lang set (v1.0.0 + v1.0.1 + v1.0.2 keys).
 *      New in v1.0.2:
 *        * gdffl_acp_zipgeo_upload           — ACP button label
 *        * gdffl_acp_zipgeo_upload_submit
 *        * gdffl_acp_zipgeo_load_hint
 *        * gdffl_err_no_zip_file
 *        * gdffl_import_running_ffl / _zip
 *      Reseeds v1.0.1's finder keys too, so an install stuck at
 *      1.0.0 still lands cleanly on 1.0.2.
 *   2. Self-heal data/extensions.json (rule #16) — IPS has been
 *      observed to overwrite it from a stale datastore cache; we
 *      rewrite it from the known-good literal here.
 *   3. Cache-purge — modules_front / modules_admin / furl /
 *      applications / extensions / settings / interface_files /
 *      Data\Store::clearAll + Data\Cache::clearAll + opcache_reset.
 *
 * NOTHING in this upgrade writes to another app's tables. The
 * ATF CSV / ZIP centroid imports are user-driven from the ACP
 * (modules/admin/manage/import.php) so this step never processes
 * data rows itself.
 *
 * WHY v1.0.2 EXISTS (context for future maintainers):
 *   v1.0.0 shipped queue-based imports that never fired on low-
 *   traffic sites; the ATF CSV "queued" and gd_ffl stayed at 0.
 *   v1.0.2 rewrites the ACP importer as an AJAX batch loop
 *   driven by the browser — no queue dependency, immediate
 *   progress display, no single-request timeout. The old
 *   extensions/core/Queue/{FflImport,ZipGeoImport}.php files
 *   remain shipped as optional fallbacks (the scheduler picks
 *   them up if it ever does run) but the AJAX path is primary.
 */

namespace IPS\gdffl\setup\upg_10002;

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
		 * LANG RESEED — full set for v1.0.0 + v1.0.1 + v1.0.2.
		 * Per rule #43 (IPS 5.0.18 6-column schema) and rule #44
		 * (per-row try/catch so one bad row doesn't poison the loop).
		 * ------------------------------------------------------------ */
		$strings = [
			/* v1.0.1 — public finder page + JSON endpoint (reseeded
			   here so a 1.0.0 → 1.0.2 jump still lands them). */
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
		 * EXTENSIONS.JSON SELF-HEAL — rule #16. The two Queue
		 * extensions (FflImport, ZipGeoImport) MUST stay registered
		 * even though the AJAX path is now primary — the scheduler
		 * still runs them and admins can still queue jobs from
		 * older code paths.
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
		 * CACHE PURGE — must clear so IPS re-parses modules_front /
		 * furl / extensions / interface files on the next request.
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
