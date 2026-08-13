<?php
/**
 * @brief  GD Deals — upgrade 1.0.61 (SITE-WIDE OUTAGE FIX: seed core_theme_templates).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.61
 *   Front-page and every gddeals page threw
 *   ErrorException: template_store_missing (0) with IN_DEV=false
 *   because core_theme_templates had ZERO gddeals rows despite
 *   8 valid .phtml files in dev/html/. Root cause: no seeding
 *   code existed in setup/install.php or any prior upg_*. Dev
 *   mode masked it through a dev/html live-read fallback path,
 *   which is why the outage only surfaced when Derrick flipped
 *   IN_DEV to false.
 *
 *   Fix pattern (identified in the site-wide investigation): read
 *   each app's own dev/html tree at install/upgrade time and
 *   insert canonical rows into core_theme_templates. No IPS 5.0.18
 *   native "sync dev/html → prod" call exists in this stack
 *   (theme.xml is rule-#4-forbidden, CanonicalTemplates::ensure()
 *   is standing-session-forbidden, so we roll our own reader).
 *   Same helper is now embedded in setup/install.php so fresh
 *   installs stop shipping broken.
 *
 *   Delete-then-insert keyed on (app, location, group, name,
 *   set_id=1) avoids duplicates and doesn't require any unique
 *   constraint that may or may not be present. Rule #45 columns
 *   only — never template_user_edited / template_user_created /
 *   template_user_added.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Reads every applications/gddeals/dev/html/{location}/
 *      {group}/{name}.phtml, extracts the <ips:template
 *      parameters="…"/> first line into template_data, stores
 *      the remaining body as template_content, and inserts fresh.
 *   2. Full datastore / template-store / opcache purge so the
 *      new rows are picked up on the very next request.
 *
 * NO schema change. NO data/theme.xml touched.
 * Rule #79: upg_10060 removed, exactly one upg dir per app.
 */

namespace IPS\gddeals\setup\upg_10061;

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
		$app     = 'gddeals';
		$version = '1.0.61';
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
						try { \IPS\Log::log( 'upg_10061 tpl (' . $name . '): ' . $e->getMessage(), 'gddeals_upg_10061' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10061 tpl loop: ' . $e->getMessage(), 'gddeals_upg_10061' ); } catch ( \Throwable ) {}
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
