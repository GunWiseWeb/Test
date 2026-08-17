<?php
/**
 * @brief  GD Loadout — upgrade 1.0.78 (re-sync all templates from dev/html/ so beta notice + all recent template edits actually land in prod).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.78
 *   The "This application is in beta" info box on the Loadouts hub
 *   stopped rendering. Diagnosis showed dev/html/front/loadouts/
 *   hub.phtml contains the <div class="gr-beta-notice"> block
 *   (guarded by \IPS\Settings::i()->gdloadout_beta_notice_enabled),
 *   but core_theme_templates' row for gdloadout/front/loadouts/hub
 *   was a STALE pre-beta version — the setting was enabled and
 *   populated on prod, the template just didn't have the block.
 *
 *   Same class of bug is likely lurking for any other gdloadout
 *   template that was edited in dev/html/ since the last DB seed.
 *   Fix: re-sync EVERY .phtml under dev/html/ into
 *   core_theme_templates using the same idempotent 9-column
 *   replace() pattern proven in gddeals v1.0.63 (matches
 *   gddealer's setup/install.php:5230 canonical shape — no
 *   template_master_key, no template_has_hookpoints, let IPS
 *   provide defaults).
 *
 *   Same helper is also embedded in setup/install.php so fresh
 *   installs stay in sync.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Walk applications/gdloadout/dev/html/{location}/{group}/
 *      {name}.phtml. For each file, extract the parameters attr
 *      from the first-line <ips:template>, strip that line, and
 *      \IPS\Db::i()->replace() the row in core_theme_templates.
 *   2. Full datastore / template-store / opcache purge + rotate
 *      set_cache_key so IPS regenerates its compiled classes on
 *      the next request.
 *
 * NO schema change. NO lang change. NO CSS change (gdloadout CSS
 * lives at applications/gdloadout/interface/loadouts.css and is
 * served raw — no compile pipeline involvement).
 *
 * Rule #79: upg_10077 removed, exactly one upg dir per app.
 */

namespace IPS\gdloadout\setup\upg_10078;

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
		$app     = 'gdloadout';
		$version = '1.0.78';
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
						try { \IPS\Log::log( 'upg_10078 tpl (' . $name . '): ' . $e->getMessage(), 'gdloadout_upg_10078' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10078 tpl loop: ' . $e->getMessage(), 'gdloadout_upg_10078' ); } catch ( \Throwable ) {}
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
