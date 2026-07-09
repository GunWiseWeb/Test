<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.15 (Stage 3 embed).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHY v1.0.15 EXISTS:
 *   Adds the cross-app product-page panel. gdffl now exposes
 *     \IPS\gdffl\Finder\Panel::render( string $upc = '' ): string
 *   which returns a collapsible "Find an FFL to receive your
 *   transfer" panel. gdsearch's product page calls it from a
 *   triple-guarded try/catch (mirrors the gdreviews shared-render
 *   pattern), so a missing / broken gdffl can never break the
 *   product page.
 *
 *   Panel behaviour:
 *     * Collapsed by default (<details>/<summary>).
 *     * ZIP + radius + Search form; POSTs to gdffl's own do=search
 *       endpoint, renders the same distance-chip + phone-link
 *       cards as the standalone page.
 *     * ZIP is remembered across pages under localStorage key
 *       `gdffl_zip` — same key the standalone finder uses, so a
 *       buyer who searched on one product finds the ZIP
 *       pre-filled on the next.
 *     * "Open full FFL finder" link back to /ffl-finder.
 *     * Always shown (no firearm heuristic — ammo and other
 *       transfer-required items benefit too).
 *
 *   Also adds localStorage persistence to interface/finder.js
 *   so the standalone page reads/writes the same key.
 *
 * No schema, no lang changes.
 */

namespace IPS\gdffl\setup\upg_10015;

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

		/* Cache purge — new sources/Finder/Panel.php + updated
		   interface/finder.js mean the class autoloader map + the
		   interface_files map both need to re-resolve. */
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
