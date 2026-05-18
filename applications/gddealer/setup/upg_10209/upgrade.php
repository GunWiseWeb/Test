<?php
namespace IPS\gddealer\setup\upg_10209;

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
		/* v1.0.209 — Re-run templates_10072.php so the synced overview
		 * body lands in core_theme_templates. Pure overlay re-execution;
		 * the file itself updates BOTH overview and feedSettings, which
		 * is intended (we want both at their latest overlay bodies).
		 *
		 * NOTE TO FUTURE: templates_10072.php is the canonical source
		 * for the overview template body. Future overview edits go
		 * THERE, not into install.php's $gddealerTemplates array — or
		 * if into install.php, they must be mirrored to this file. */

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10072.php';
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10209 templates_10072 require failed: ' . $e->getMessage(), 'gddealer_upg_10209' ); }
			catch ( \Throwable ) {}
		}

		/* Cache busts — rule #40 */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }       catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
