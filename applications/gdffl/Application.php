<?php
/**
 * @brief  GD FFL Finder — application root (Stage 1 of 3).
 *
 * An in-site FFL finder so buyers on gunrack.deals can locate a
 * transfer-capable FFL near their ZIP without leaving the site.
 *
 * STAGE 1 (this ship) — data foundation:
 *   * App skeleton + gd_ffl + gd_zip_geo tables.
 *   * Bundled US Census ZCTA ZIP→lat/lng centroid CSV loaded
 *     into gd_zip_geo (public domain; no per-address geocoding
 *     API required).
 *   * ACP tool to upload the ATF full-FFL CSV and CHUNK-import
 *     it via IPS's core queue so 77k rows never run in one web
 *     request.
 * STAGE 2 — front-facing lookup (ZIP + radius + type filter).
 * STAGE 3 — integrate into the product / buying flow.
 *
 * HARD SAFETY — this app owns gd_ffl and gd_zip_geo and nothing
 * else. Every read against another app's tables (should any
 * exist in later stages) is SELECT-only. Nothing from gdcatalog
 * / gddealer / gdcompliance is touched.
 */

namespace IPS\gdffl;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Application extends \IPS\Application
{
	public function get__icon(): string
	{
		return 'location-dot';
	}
}

class Application extends _Application {}
