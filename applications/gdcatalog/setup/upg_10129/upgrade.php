<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.129 (fetchFeed HTTP status coercion fix).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.129
 *   Single-defect fix. `Importer::fetchFeed` compared
 *   `$response->httpResponseCode !== 200` with strict type equality
 *   against an integer literal. IPS's Http\Response returns the code
 *   as a STRING ("200"), so the comparison ALWAYS matched (i.e.
 *   "200" !== 200 evaluates true) and every successful generic HTTP
 *   feed fetch was rejected with the misleading message
 *   "Feed fetch failed: HTTP 200". Caught the first time an admin
 *   tried Test Source on a real HTTP endpoint post-Phase-12.
 *
 *   SportsSouthClient and FetchImageDimensions both already cast to
 *   (int) before comparing. This upgrade brings Importer::fetchFeed
 *   in line with that established pattern.
 *
 *   Code changes:
 *     - MOD  sources/Feed/Importer.php
 *            — `(int) $response->httpResponseCode !== 200` (was
 *              `$response->httpResponseCode !== 200`). Error
 *              message also casts before concatenation.
 *
 *   NO other change. Adapters, queue extensions, tasks,
 *   ImportJob, ImageDimensionCache, ConflictResolver, schema,
 *   extensions/tasks registrations, AdminCP routes, templates —
 *   all untouched.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Re-seeds every dev/html/*.phtml into core_theme_templates
 *      (rule #52 self-containment — no template body change this
 *      version but keeps the upg dir self-contained per rule #79).
 *   2. Cache / datastore / opcache purge so the updated
 *      Importer::fetchFeed body takes effect on the next request.
 *
 * Rule #79: upg_10128 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10129;

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
		$app     = 'gdcatalog';
		$version = '1.0.129';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* -------- Template resync (rule #52 + #79) -------- */
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
						try { \IPS\Log::log( 'upg_10129 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10129' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10129 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10129' ); } catch ( \Throwable ) {}
			}
		}

		/* -------- Cache / datastore / opcache purge (rule #40) -------- */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $x ) { @unlink( $x ); }
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
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
