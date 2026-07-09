<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.13.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHY v1.0.13 EXISTS:
 *   Approved-mockup redesign of the public finder page.
 *   Cosmetic only — the search endpoint / query / per-row
 *   data are unchanged. What changes:
 *     * Navy header band (#0f2740) with pin icon (#5dcaa5)
 *       + title + sub-line.
 *     * Search panel with explicit palette (not IPS vars) —
 *       obvious ZIP box (1.5 px border, white bg, search
 *       icon inside, 44 px min height, focus glow) + green
 *       Search button (#0f6e56).
 *     * Filter chips replace the old checkbox-grid list —
 *       small pills, active = filled green, inactive =
 *       outlined. A "Show all types" chip toggles the whole
 *       filter off.
 *     * Result cards: 52 × 52 distance chip on the left
 *       (teal fill when < 5 mi from the search ZIP, else
 *       neutral), business name + address (with a pin icon)
 *       + license-type tag in the middle, a real
 *         <a class="gdffl-call" href="tel:...">
 *       Call button on the right.
 *
 *   Preserves every prior fix — Theme::i()->css() enqueue
 *   (v1.0.11), haversine 4-close (v1.0.9), Db\Select cursor
 *   (v1.0.8), IPS Form uploader (v1.0.4), AJAX batch imports
 *   (v1.0.2).
 *
 * No schema, no lang changes.
 */

namespace IPS\gdffl\setup\upg_10013;

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
		/* Cache purge — the CSS + JS file map must re-resolve so
		   the new versioned finder.css / finder.js URLs propagate
		   on the next request. */
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

		return TRUE;
	}
}
class upgrade extends _upgrade {}
