<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.120 (Phase 4 refactor: generic StructuredFeedAdapter).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.120
 *   Phase 4 of the source-adapter refactor plan (audit 2026-08-25).
 *   Pure architectural change — no product-data behaviour is intended
 *   to change. No schema, lang, template, queue, task, or controller
 *   change.
 *
 *   Code changes:
 *     - NEW  sources/Feed/SourceAdapter/StructuredFeedAdapter.php
 *            — dual-class wrapper implementing SourceAdapterInterface.
 *            Constructor takes ?Distributor and ?FieldMapper.
 *            normalize(array) runs FieldMapper::mapRecord + castTypes
 *            once, returns NormalizedRecord with the mapped canonical
 *            + the raw parsed record verbatim + the Distributor feed.
 *            Zero Sports South-specific field knowledge (grep-clean
 *            for CATID / ITATR / BRDNO / PICREF / IMFGNO / ITBRDNO /
 *            MFGINO / SportsSouth in code).
 *     - MOD  sources/Feed/Importer.php
 *            — added `use IPS\gdcatalog\Feed\SourceAdapter\SourceAdapterInterface`
 *              and `... \StructuredFeedAdapter`.
 *            — added `?StructuredFeedAdapter $structuredFeedAdapter`
 *              lazy property + `getStructuredFeedAdapter()` getter
 *              (per-import-run instance, same lifetime rule as the SS
 *              adapter).
 *            — added `resolveAdapter(): SourceAdapterInterface` —
 *              small explicit auth_type switch (sportssouth → SS
 *              adapter, everything else → StructuredFeedAdapter).
 *              No plugin registry / factory.
 *            — added `processNormalizedRecord(NormalizedRecord)` —
 *              the shared generic-catalog tail (UPC extract, category
 *              resolve, refine, is_ammo, action_type cleanup,
 *              product create/update, compliance flag detection).
 *              Reads $mapped from the DTO's canonical and $rawRecord
 *              from getRaw(). The generic `_ATTR_*` merge (was inline
 *              in processRecord pre-Phase-4) now lives here so it runs
 *              exactly once regardless of entry path.
 *            — MOD `processRecord(array)` — now a thin wrapper: runs
 *              FieldMapper::mapRecord + castTypes once on the raw
 *              (preserving the SS legacy path, which is called from
 *              runChunk and from any subclass override), wraps in a
 *              NormalizedRecord, delegates the tail to
 *              processNormalizedRecord. No behaviour change from the
 *              caller's perspective — same signature, same errored/
 *              logged semantics.
 *            — MOD `execute()` per-record loop — dispatches by
 *              auth_type: sportssouth records go through processRecord
 *              (pre-enriched in fetchFeed via SS adapter),
 *              everything else goes through resolveAdapter()->normalize
 *              → processNormalizedRecord (one mapping pass, in the
 *              adapter).
 *     - MOD  sources/Feed/SourceAdapter/SourceAdapterInterface.php
 *            — docblock updated to describe the two adapter shapes
 *              introduced by Phase 4: SS returns empty canonical
 *              (defers mapping), Structured returns populated
 *              canonical (does mapping). Both converge on
 *              Importer::processNormalizedRecord.
 *
 *   No signature change on:
 *     - Importer::run(Distributor)
 *     - Importer::runChunk(Distributor, array)
 *     - Importer::processRecord (still `protected function processRecord( array ): void`)
 *     - SportsSouthAdapter::normalize (contract preserved: empty
 *       canonical + enriched raw; Sports South regression tests
 *       remain green byte-for-byte)
 *
 *   No queue class, task class, AdminCP route, schema, or
 *   raw_distributor_data storage shape moved. Existing Sports South
 *   feeds continue to fetch via SportsSouthClient, enrich via
 *   SportsSouthAdapter, and land in gd_catalog with identical values.
 *   Existing generic feeds (auth_type none/basic/apikey/ftp/manual_upload)
 *   now flow through StructuredFeedAdapter but the observable output —
 *   canonical fields, cast types, category resolution, product
 *   create/update, conflict resolution, compliance flags,
 *   discontinuation — is byte-equivalent.
 *
 * WHAT THIS UPGRADE DOES
 *   Re-runs the template resync + cache purge from upg_10119 (rule
 *   #79 self-containment) so any prior-version → 1.0.120 install
 *   lands at the same DB state.
 *
 * NO schema change. NO lang change. NO template content change.
 * NO queue registration change. NO task registration change. NO
 * AdminCP controller/route change. NO raw_distributor_data format
 * change.
 *
 * Rule #79: upg_10119 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10120;

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
		$version = '1.0.120';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* Template resync — preserved from upg_10119 so installs
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
						try { \IPS\Log::log( 'upg_10120 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10120' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10120 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10120' ); } catch ( \Throwable ) {}
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
