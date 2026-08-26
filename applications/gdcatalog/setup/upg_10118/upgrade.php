<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.118 (Phase 2 refactor: SourceAdapterInterface + SportsSouthAdapter).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.118
 *   Phase 2 of the source-adapter refactor plan (audit 2026-08-25).
 *   Pure architectural extraction — no product-data behaviour is
 *   intended to change. No schema, lang, template, queue, task, or
 *   controller change.
 *
 *   Three code changes:
 *     - NEW  sources/Feed/SourceAdapter/SourceAdapterInterface.php
 *            — one-method contract (normalize(array): NormalizedRecord).
 *            Deliberately kept minimal so Phase 3+ can grow it when a
 *            second adapter arrives (supports/getSourceKey are the
 *            obvious next additions).
 *     - NEW  sources/Feed/SourceAdapter/SportsSouthAdapter.php
 *            — dual-class wrapper implementing the interface. Owns
 *            the enrichment body moved verbatim from
 *            Importer::enrichSportsSouthRecord(), the four lazy-loaded
 *            lookup properties, and the SPORTS_SOUTH_ATTR_LABEL_MAP
 *            constant. Does NOT own SportsSouthClient (HTTP/XML),
 *            SportsSouthAttributeMap (external), TitleParser,
 *            accessoryAttrsFor / ACCESSORY_ATTR_MAP (still called from
 *            generic processRecord — Phase 3+ scope), or
 *            refineCategoryByTitle.
 *     - MOD  sources/Feed/Importer.php
 *            — removed the four SS-lookup properties and
 *            SPORTS_SOUTH_ATTR_LABEL_MAP (moved to adapter).
 *            Added `?SportsSouthAdapter $sportsSouthAdapter = null`
 *            property + `getSportsSouthAdapter()` lazy getter (one
 *            adapter per import run, matching pre-refactor lookup-
 *            cache lifetime). enrichSportsSouthRecord() is now a
 *            thin delegating wrapper — retained (still `protected`)
 *            so subclasses that override it and both existing
 *            internal callers (fetchFeed:425 + runChunk:320)
 *            continue to work unchanged.
 *
 *   One incidental Phase 1 fix that Phase 2 required:
 *     - MOD  sources/Feed/NormalizedRecord.php
 *            — fromMapped() return type changed from `self` to
 *            `static`. `self` resolved to `_NormalizedRecord`
 *            (the underscore class the method is declared on),
 *            which broke Phase 2 callers that declare a
 *            `\IPS\gdcatalog\Feed\NormalizedRecord` return type.
 *            Late-static-binding resolves to the concrete alias
 *            class, matching the dual-class wrapper contract
 *            (CLAUDE.md rule #1). Same class of bug caught in
 *            IPS core Theme.php earlier this session.
 *
 *   Sports South behaviour is unchanged — enrichSportsSouthRecord's
 *   observable output (the enriched raw record it returns) is
 *   byte-equivalent to the pre-refactor version. Adapter and Phase 1
 *   regression tests both pass.
 *
 * WHAT THIS UPGRADE DOES
 *   Re-runs the template resync + cache purge from upg_10117 (rule
 *   #79 self-containment) so any prior-version → 1.0.118 install
 *   lands at the same DB state.
 *
 * NO schema change. NO lang change. NO template content change.
 * NO queue registration change. NO task registration change. NO
 * AdminCP controller/route change. NO raw_distributor_data format
 * change.
 *
 * Rule #79: upg_10117 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10118;

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
		$version = '1.0.118';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* Template resync — preserved from upg_10117 so installs
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
						try { \IPS\Log::log( 'upg_10118 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10118' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10118 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10118' ); } catch ( \Throwable ) {}
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
