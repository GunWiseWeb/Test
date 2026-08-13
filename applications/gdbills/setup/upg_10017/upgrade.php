<?php
/**
 * @brief  GD Bills — upgrade 1.0.17 (SITE-WIDE OUTAGE FIX: seed core_theme_templates).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.17
 *   Bill tracker page threw
 *   ErrorException: template_store_missing (0) with IN_DEV=false
 *   because core_theme_templates had ZERO gdbills rows despite
 *   3 valid .phtml files in dev/html/. Root cause: no seeding
 *   code existed anywhere in setup/. Dev mode masked it via a
 *   dev/html live-read fallback path, which is why the outage
 *   only surfaced once IN_DEV flipped false.
 *
 *   Companion changes shipped in this version:
 *     - Application.php gains installOther() (was missing —
 *       IPS's fresh-install runner had no hook, so setup/install/
 *       install.php never ran and lang/task/permission rows were
 *       never seeded either on any prior fresh install).
 *     - setup/install.php created at top level, requires the
 *       pre-existing setup/install/install.php (untouched — its
 *       lang/task/permission logic is preserved) and then runs
 *       the same template sync helper below.
 *
 *   Delete-then-insert keyed on (app, location, group, name,
 *   set_id=1) avoids duplicates without depending on any unique
 *   constraint. Rule #45 safe columns only — never
 *   template_user_edited / template_user_created / template_user_added.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Reads every applications/gdbills/dev/html/{location}/
 *      {group}/{name}.phtml, extracts <ips:template
 *      parameters="…"/> first line into template_data, stores
 *      the remaining body as template_content, and inserts fresh.
 *   2. Full datastore / template-store / opcache purge so the
 *      new rows are picked up on the very next request.
 *
 * NO schema change. NO data/theme.xml touched.
 * Rule #79: upg_10016 removed, exactly one upg dir per app.
 */

namespace IPS\gdbills\setup\upg_10017;

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
		$app     = 'gdbills';
		$version = '1.0.17';
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
						\IPS\Db::i()->delete( 'core_theme_templates', [
							'template_app=? AND template_location=? AND template_group=? AND template_name=? AND template_set_id=?',
							$app, $location, $group, $name, 1
						] );
					}
					catch ( \Throwable ) {}

					try
					{
						\IPS\Db::i()->insert( 'core_theme_templates', [
							'template_set_id'         => 1,
							'template_app'            => $app,
							'template_location'       => $location,
							'template_group'          => $group,
							'template_name'           => $name,
							'template_data'           => $params,
							'template_content'        => (string) $content,
							'template_updated'        => time(),
							'template_version'        => $version,
							'template_master_key'     => '',
							'template_has_hookpoints' => 0,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10017 tpl (' . $name . '): ' . $e->getMessage(), 'gdbills_upg_10017' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10017 tpl loop: ' . $e->getMessage(), 'gdbills_upg_10017' ); } catch ( \Throwable ) {}
			}
		}

		/* Cache / datastore / opcache purge. */
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
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
