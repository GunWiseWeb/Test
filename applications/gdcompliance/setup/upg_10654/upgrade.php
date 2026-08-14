<?php
/**
 * @brief  GD Compliance — upgrade 1.6.54 (CORRECTED template seed — v1.6.53 broke globalTemplate).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.6.54 — CORRECTION OF 1.6.53
 *   v1.6.53 seeded core_theme_templates rows with 11 columns,
 *   including template_master_key='' and template_has_hookpoints=0.
 *   template_master_key='' has SPECIFIC meaning in IPS theme
 *   resolution — it flags the row as A MASTER TEMPLATE. Those
 *   inserted rows collided with the core theme's master hierarchy
 *   and crashed core/front/global/globalTemplate. Derrick manually
 *   DELETEd the 4 apps' rows to recover the front page.
 *
 *   The correct pattern is what gddealer's proven working seeds
 *   use: exactly 9 columns, no template_master_key, no
 *   template_has_hookpoints. Let IPS provide defaults for anything
 *   not set explicitly. \IPS\Db::i()->replace() is idiomatic.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Reads every applications/gdcompliance/dev/html/{location}/
 *      {group}/{name}.phtml, extracts <ips:template
 *      parameters="…"/> first line into template_data, stores
 *      the remaining body as template_content, and replace()s.
 *   2. Full datastore / template-store / opcache purge.
 *
 * NO schema change. NO data/theme.xml touched. Existing ruleset /
 * AWB / PICA / lang / notification / permission rows are NOT
 * touched — templates only.
 * Rule #79: upg_10653 removed, exactly one upg dir per app.
 */

namespace IPS\gdcompliance\setup\upg_10654;

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
		$app     = 'gdcompliance';
		$version = '1.6.54';
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
						try { \IPS\Log::log( 'upg_10654 tpl (' . $name . '): ' . $e->getMessage(), 'gdcompliance_upg_10654' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10654 tpl loop: ' . $e->getMessage(), 'gdcompliance_upg_10654' ); } catch ( \Throwable ) {}
			}
		}

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

		/* CRITICAL — rotate core_themes.set_cache_key so IPS regenerates
		   the on-disk /datastore/template_*.php compiled classes on the
		   next request. Without this, IPS trusts whatever compiled files
		   are already on disk and our fresh core_theme_templates rows
		   are effectively ignored. */
		try { \IPS\Db::i()->update( 'core_themes', [ 'set_cache_key' => md5( microtime() . mt_rand() ) ] ); } catch ( \Throwable ) {}
		try { \IPS\Theme::deleteCompiledTemplate(); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/theme_*' ) ?: [] as $x ) { @unlink( $x ); }
		try { \IPS\Theme::master()->recompileTemplates(); } catch ( \Throwable ) {}

		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
