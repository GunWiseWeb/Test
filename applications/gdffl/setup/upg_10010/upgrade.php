<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.10.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHY v1.0.10 EXISTS:
 *   Cosmetic-only update to the public finder page. The search
 *   query, endpoint, and per-row data (v1.0.9's paren fix) are
 *   preserved verbatim. Only interface/finder.css and interface/
 *   finder.js change, plus a small PHP tweak in
 *   modules/front/finder/finder.php to (a) add `.gr5` to the
 *   wrapper so the new CSS scope applies, and (b) add a
 *   #gdfflCount div for the "N FFLs within X miles of ZIP"
 *   header.
 *
 *   Redesign summary:
 *     * FFL cards get structured markup — bold business name +
 *       muted address + actions row (type pill on the left,
 *       distance pill on the right of the name, and a real
 *       <a class="gdffl-call" href="tel:..."> button — no more
 *       raw markdown-ish text).
 *     * Search form now stacks cleanly on mobile, tap targets
 *       ≥ 40px, focus ring on ZIP / radius selectors.
 *     * Loading, empty, and error states are all styled.
 *     * Dark mode via prefers-color-scheme.
 *   All styles scoped under `.gr5 .gdffl-wrap` — nothing global.
 *
 * No schema changes.
 */

namespace IPS\gdffl\setup\upg_10010;

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
		/* Full lang reseed — no new keys in v1.0.10. */
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

		/* Extensions self-heal (rule #16). */
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

		/* Cache purge — interface_files MUST clear so the new
		   finder.css / finder.js versioned URLs propagate on the
		   next request. */
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
