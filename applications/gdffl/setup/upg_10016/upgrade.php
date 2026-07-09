<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.16 (Stage 3 locator button + modal).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHY v1.0.16 EXISTS:
 *   v1.0.15 shipped a collapsible "Find an FFL to receive your
 *   transfer" panel that landed BELOW the price-comparison
 *   card on the product page. Buyer feedback: the below-offers
 *   panel added visual clutter to a page whose focal point
 *   should stay the price grid.
 *
 *   v1.0.16 replaces that pattern with a compact BUTTON on
 *   the price-comparison chart header (top-right, next to
 *   the sort control). Clicking it opens a MODAL that runs
 *   the same FFL search inline — same result cards, same
 *   distance-chip + visible-phone-number layout — so the
 *   buyer never leaves the product page and the offers grid
 *   stays uncluttered.
 *
 *   sources/Finder/Panel.php now exposes
 *     public static function renderButton( string $upc = '' ): string
 *   which gdsearch v1.0.84 calls from a triple-guarded try/catch
 *   (mirrors the gdreviews shared-render pattern).
 *
 *   The old Panel::render() method is kept as a no-op returning
 *   '' so a gdsearch install still on v1.0.83 (which called it)
 *   doesn't error while both apps are being upgraded in the
 *   same maintenance window.
 *
 * No schema, no lang changes.
 */

namespace IPS\gdffl\setup\upg_10016;

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

		/* Cache purge — Panel class body changed, plus finder.css
		   URL needs to re-resolve on the product page. */
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
