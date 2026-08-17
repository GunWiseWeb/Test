<?php
/**
 * @brief  GD Dealer Manager — upgrade 1.0.340 (re-sync ALL templates from dev/html/ so overview + sidebar icons + 8 other missing templates land).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.340
 *   Prod diagnosis showed:
 *
 *     - gddealer has 49 rows in core_theme_templates but 59
 *       .phtml files in dev/html/ — 10 templates NEVER got
 *       seeded. Includes `overview` which is why the Dealer
 *       Overview page throws template_store_missing.
 *     - dealerSidebar row is 55 bytes shorter than the dev/html
 *       file, so the sidebar renders a stale version missing
 *       icons for newer nav items (Setup Wizard, Feed Validator,
 *       Flagged UPCs, Deals, Coupons, Edit Profile).
 *
 *   Same class of "dev/html has the current template body but DB
 *   was never re-seeded" issue fixed for gdloadout in v1.0.78 and
 *   gddeals in v1.0.63/64. Fix: walk every .phtml under dev/html/
 *   and \IPS\Db::i()->replace() the row into core_theme_templates
 *   using the same 9-column pattern (matches gddealer's own
 *   overlay files at setup/templates_*.php — no
 *   template_master_key, no template_has_hookpoints).
 *
 *   Rule #33 (standing session): NEVER call
 *   CanonicalTemplates::ensure() — that method is forbidden this
 *   session. This upgrade DOES NOT call it. It uses raw
 *   \IPS\Db::i()->replace() to seed rows directly, same mechanism
 *   the overlay files themselves use. Runs AFTER the existing 128
 *   overlay require chain (in install.php) so dev/html is the
 *   source of truth on any fresh install as well.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Walk applications/gddealer/dev/html/{location}/{group}/
 *      {name}.phtml — extract the <ips:template parameters="..."/>
 *      first line into template_data, strip that line, and
 *      replace() into core_theme_templates with the current body.
 *   2. Full datastore / template-store / opcache purge + rotate
 *      set_cache_key so compiled classes rebuild.
 *
 * NO schema change. NO lang change. NO CSS change (dealer.css is
 * separate and already registered via the working legacy pipeline).
 *
 * Rule #79: upg_10339 removed, exactly one upg dir per app.
 */

namespace IPS\gddealer\setup\upg_10340;

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
		$app     = 'gddealer';
		$version = '1.0.340';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		if ( is_dir( $root ) )
		{
			try
			{
				$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
				foreach ( $it as $f )
				{
					if ( !$f->isFile() || strtolower( $f->getExtension() ) !== 'phtml' ) { continue; }
					$rel = trim( str_replace( $root, '', $f->getPathname() ), "/\\" );
					$parts = preg_split( '#[/\\\\]#', $rel );
					if ( count( $parts ) < 3 ) { continue; }
					$location = (string) $parts[0];
					$group    = (string) $parts[1];
					$name     = pathinfo( (string) end( $parts ), PATHINFO_FILENAME );
					$raw      = (string) @file_get_contents( $f->getPathname() );
					if ( $raw === '' ) { continue; }
					$params = '';
					if ( preg_match( '#<ips:template\s+parameters="([^"]*)"\s*/>#', $raw, $m ) )
					{
						$params = (string) $m[1];
					}
					$content = preg_replace( '#^\s*<ips:template[^>]*/>\s*\r?\n?#', '', $raw, 1 );

					try
					{
						\IPS\Db::i()->replace( 'core_theme_templates', [
							'template_set_id'   => 1,
							'template_app'      => $app,
							'template_location' => $location,
							'template_group'    => $group,
							'template_name'     => $name,
							'template_data'     => $params,
							'template_updated'  => time(),
							'template_version'  => $version,
							'template_content'  => (string) $content,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10340 tpl (' . $name . '): ' . $e->getMessage(), 'gddealer_upg_10340' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10340 tpl loop: ' . $e->getMessage(), 'gddealer_upg_10340' ); } catch ( \Throwable ) {}
			}
		}

		/* Cache / datastore / opcache purge + rotate set_cache_key. */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $x ) { @unlink( $x ); }
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Db::i()->update( 'core_themes', [ 'set_cache_key' => md5( microtime() . mt_rand() ) ] ); } catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/theme_*' ) ?: [] as $x ) { @unlink( $x ); }
		try { \IPS\Theme::master()->recompileTemplates(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
