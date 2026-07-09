<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.14.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHY v1.0.14 EXISTS:
 *   Two small user-visible tweaks:
 *     1. Finder intro (public /ffl-finder page):
 *          BEFORE: "Enter your ZIP code to find licensed
 *                   dealers who can receive a transfer for
 *                   you. Distance is calculated from the
 *                   ZIP centroid."
 *          AFTER:  "Enter your ZIP code to find nearby
 *                   dealers who can receive your transfer."
 *        Drops the "ZIP centroid" jargon and shortens.
 *     2. Result cards now show the actual phone NUMBER as
 *        visible clickable text — real <a href="tel:...">
 *        so desktop reads/copies it and mobile taps to dial.
 *        The old icon-only "Call" button is replaced with a
 *        .gdffl-phone pill wrapping "<phone-icon> (XXX)
 *        XXX-XXXX".
 *
 *   ACP-facing labels that used the word "centroid"
 *   (settings desc, ZCTA-import buttons, queue captions)
 *   have been reworded to "location" so nothing user- or
 *   admin-facing exposes the term. Rule #43 / #44 shape for
 *   the reseed: per-lang, per-key try/catch.
 *
 * No schema, no template changes.
 */

namespace IPS\gdffl\setup\upg_10014;

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
		/* Reseed every string whose value changed in v1.0.14.
		   Rule #43 — 6-column shape only (no word_default_version).
		   Rule #44 — per-row try/catch so one row can't poison
		   the loop. */
		$updated = [
			/* Public front-facing — the actual reason for this
			   version. */
			'gdffl_finder_lead'            => 'Enter your ZIP code to find nearby dealers who can receive your transfer.',

			/* ACP-facing — "centroid" swapped for "location" so
			   no user- or admin-visible string leaks the term. */
			'gdffl_default_radius_desc'    => 'Miles from the buyer\'s ZIP location used by the Stage 2 lookup.',
			'gdffl_acp_zipgeo_title'       => 'ZIP location data',
			'gdffl_acp_zipgeo_load'        => 'Load bundled ZIP location CSV',
			'gdffl_acp_zipgeo_intro'       => 'Loads the bundled US Census ZCTA public-domain ZIP→lat/lng CSV into gd_zip_geo. Chunked; safe to re-run.',
			'gdffl_acp_zipgeo_queued'      => 'ZIP location load queued — %s rows to process.',
			'gdffl_queue_zipgeo'           => 'Loading ZIP locations: %s of %s',
			'gdffl_acp_zipgeo_upload_submit' => 'Upload ZIP location file',
			'gdffl_err_no_zip_file'        => 'No ZIP location file is on disk yet. Upload a real Census ZCTA CSV or drop one into applications/gdffl/data/zip_geo.csv first.',
			'gdffl_import_running_zip'     => 'ZIP location import running…',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $updated as $key => $val )
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

		/* Extensions.json self-heal (rule #16). */
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

		/* Cache purge — lang words + CSS/JS versioned URLs both
		   need to re-resolve on the next request. */
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->interface_files ); }    catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
