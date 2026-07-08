<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.1 (Stage 2: public finder).
 *
 * WHAT SHIPS IN 1.0.1:
 *
 *   * New front module `finder` (data/modules.json) with a
 *     controller at `modules/front/finder/finder.php`.
 *   * FURL entries `finder_root` (friendly "") and
 *     `finder_search` (friendly "search") under the existing
 *     "ffl-finder" topLevel. No collision with any IPS core
 *     path (login / profile / members / search / settings).
 *   * ZIP + radius + type-filter search endpoint (do=search)
 *     that runs a bounding-box prefilter followed by a haversine
 *     ACOS() distance calculation over gd_ffl, returns JSON.
 *   * Standalone finder page (do=default) with a form, results
 *     container, and load-more button; guest-viewable.
 *   * Static interface/ assets: finder.css and finder.js
 *     (rule #47 — served directly, not template-processed;
 *     no inline $-vars, no template collision).
 *   * 17 new lang keys mirrored in dev/lang.php and data/lang.xml
 *     and re-seeded for existing installs per rules #43/#44.
 *
 *   READ-ONLY — the finder reads gd_ffl (with lat/lng populated
 *   at Stage-1 import) and gd_zip_geo. No writes anywhere; no
 *   other app's tables touched.
 *
 * Cache purge + FURL datastore clear so IPS picks up the new
 * front module + route on first request.
 */

namespace IPS\gdffl\setup\upg_10001;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$v101 = [
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
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $v101 as $key => $val )
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

		/* FURL datastore MUST clear so IPS re-parses furl.json and
		   picks up the new finder_root + finder_search pages. */
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
