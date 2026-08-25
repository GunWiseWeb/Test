<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.117 (Phase 1 refactor: NormalizedRecord DTO seam).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.117
 *   Phase 1 of the source-adapter refactor plan from the audit
 *   2026-08-25. This is a PURE ARCHITECTURAL SEAM change — no
 *   product-data behaviour is intended to change and no schema,
 *   lang, or template body is touched.
 *
 *   Two file changes:
 *     - NEW  sources/Feed/NormalizedRecord.php — source-neutral
 *       in-memory DTO with dual-class wrapper. Wraps a mapped
 *       canonical array + original raw source record + feed
 *       context. toArray() re-exposes the canonical array
 *       byte-for-byte.
 *     - MOD  sources/Feed/Importer.php:999 — inserts a two-line
 *       wrap-then-unwrap seam AFTER FieldMapper::mapRecord() +
 *       castTypes() + the _ATTR_* enrichment merge, BEFORE the
 *       generic-catalog pipeline (UPC extraction onward). The
 *       downstream code continues to consume $mapped as an
 *       array; the DTO is instantiated for every processed
 *       record so later phases can progressively refactor
 *       downstream code to consume the DTO directly.
 *
 *   Sports South behaviour is unchanged — enrichSportsSouthRecord,
 *   raw CATID lookup, ITATR* accessory attributes, and every
 *   SS-specific queue class remain exactly where they were.
 *
 * WHAT THIS UPGRADE DOES
 *   Re-runs the same template resync + cache purge that shipped
 *   in upg_10116, so a site installing straight from 1.0.115 or
 *   1.0.116 lands in the same DB state (rule #79 self-containment).
 *   Then bumps the version. No schema/lang/template body change.
 *
 * NO schema change. NO lang change. NO template content change.
 * Rule #79: upg_10116 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10117;

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
		$version = '1.0.117';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* Template resync — preserved from upg_10116 so installs
		   from any earlier version land at the same DB state per
		   rule #79 self-containment. */
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
						try { \IPS\Log::log( 'upg_10117 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10117' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10117 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10117' ); } catch ( \Throwable ) {}
			}
		}

		/* Cache / datastore / opcache purge + rotate set_cache_key. */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $x ) { @unlink( $x ); }
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
