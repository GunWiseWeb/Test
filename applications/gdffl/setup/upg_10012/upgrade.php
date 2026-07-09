<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.12.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHY v1.0.12 EXISTS:
 *   interface/finder.css targeted the ZIP + radius controls
 *   with attribute selectors that had SPACES inside the
 *   brackets:
 *     .gr5 .gdffl-row input[ type=text ] { ... }
 *   Per the CSS spec, spaces inside `[ ]` make the selector
 *   invalid, so every browser silently drops the whole rule.
 *   Result: the ZIP input rendered with zero border, zero
 *   background, zero padding — invisible to the user.
 *
 *   Same bug hit the dark-mode override:
 *     .gr5 .gdffl-wrap:not( [ data-theme="light" ] ) { ... }
 *   which also silently dropped.
 *
 *   v1.0.12 rewrites finder.css using class selectors
 *   (.gdffl-input) plus bare `input` / `select` inside
 *   .gdffl-row as a defensive fallback — no attribute
 *   selectors, so nothing can silently break these rules
 *   again. Redesigns the search panel around the fix so
 *   the ZIP input reads unmistakably as a box (labeled,
 *   placeholder, 1.5px border, 46px min height, focus ring)
 *   and the whole search area sits in a bordered card so
 *   it looks like a defined search zone.
 *
 *   Also adjusts modules/front/finder/finder.php to wrap
 *   the ZIP + radius controls in .gdffl-field divs with a
 *   .gdffl-field-label above each (matches the new CSS),
 *   and adds a placeholder + autocomplete="postal-code" +
 *   .gdffl-input class on the ZIP input.
 *
 * No schema, no lang changes.
 */

namespace IPS\gdffl\setup\upg_10012;

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
		/* Cache purge — CSS file map + interface_files + themes
		   MUST re-resolve so the corrected finder.css is served
		   under a new versioned URL on the next hit. */
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
