<?php
/**
 * @brief  GD FFL Finder — upgrade 1.0.11.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHY v1.0.11 EXISTS:
 *   modules/front/finder/finder.php enqueued the front CSS with
 *     \IPS\Output::i()->css( 'finder.css', 'gdffl', 'interface' )
 *   That method does NOT exist on \IPS\Output — the call threw
 *     Call to undefined method IPS\Output::css()
 *   which the surrounding empty
 *     catch ( \Throwable ) {}
 *   silently swallowed. Result: finder.css never made it onto
 *   the page. The v1.0.10 redesign shipped fine but was
 *   invisible, so the finder rendered as raw text.
 *
 *   The correct helper is
 *     \IPS\Theme::i()->css( 'finder.css', 'gdffl', 'interface' )
 *   which is what gdbills / gdloadout use, and matches what
 *   modules/admin/manage/import.php already uses for import.css.
 *   The JS enqueue (Output::js) IS a real method and was fine.
 *
 *   Both enqueues now log any future exception to core_log
 *   category=gdffl instead of swallowing silently — so if
 *   something else in this path breaks later, it shows up.
 *
 * No schema, no lang, no template changes.
 */

namespace IPS\gdffl\setup\upg_10011;

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
		/* Cache purge — IPS caches the resolved CSS file map by
		   theme/app; we MUST bust it so the corrected enqueue
		   actually resolves finder.css on the next hit. */
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
