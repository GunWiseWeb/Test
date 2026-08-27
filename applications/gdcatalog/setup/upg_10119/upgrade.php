<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.119 (Phase 3 refactor: processRecord source-neutral).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.119
 *   Phase 3 of the source-adapter refactor plan (audit 2026-08-25).
 *   Pure architectural extraction — no product-data behaviour is
 *   intended to change. No schema, lang, template, queue, task, or
 *   controller change.
 *
 *   Code changes:
 *     - MOD  sources/Feed/SourceAdapter/SportsSouthAdapter.php
 *            — constructor now takes an optional
 *              `?CategoryMapper $categoryMapper` (second arg) — Importer
 *              passes its per-run instance so the adapter can (a) apply
 *              the raw CATID → CategoryMapper::resolve override on
 *              `_CATEGORY_ID` and (b) drive canonical category_id →
 *              topSlug for accessory-slot interpretation.
 *            — ACCESSORY_ATTR_MAP protected const (moved verbatim from
 *              Importer::ACCESSORY_ATTR_MAP).
 *            — private topSlugForCategoryId() + $topSlugByCatId cache
 *              (moved verbatim from Importer).
 *            — private accessoryAttrs() (verbatim body from
 *              Importer::accessoryAttrsFor, keyed off SS-only ITATR* raw
 *              slots and the special-cased 'optics' pattern check).
 *            — enrich() extended (before final return) with:
 *              (1) CATID > 0 → categoryMapper->resolve override on
 *                  `_CATEGORY_ID` (unconditional overwrite, matching
 *                  pre-Phase-3 line 674 of Importer::processRecord).
 *              (2) canonical cat id → topSlug → accessory-slot values
 *                  written as `_ATTR_<col>` sentinels on the raw
 *                  record so the generic `_ATTR_*` merge in
 *                  processRecord picks them up automatically.
 *              Guarded "first match wins" on the `_ATTR_<col>` write
 *              matches the ATTR_LABEL_MAP path's discipline and is a
 *              no-op in practice since output columns don't overlap.
 *     - MOD  sources/Feed/Importer.php
 *            — removed public const ACCESSORY_ATTR_MAP (moved to
 *              adapter; zero external callers).
 *            — removed public static accessoryAttrsFor() (moved to
 *              adapter as private accessoryAttrs; zero external
 *              callers).
 *            — removed public topSlugForCategoryId() and its cache
 *              property (moved to adapter as private; zero external
 *              callers — grep confirmed).
 *            — getSportsSouthAdapter() now passes $this->categoryMapper
 *              as the adapter's second constructor arg so the moved
 *              category / accessory logic has access to the exact same
 *              per-feed CategoryMapper instance that used to live in
 *              processRecord.
 *            — processRecord() no longer reads raw $rawRecord['CATID']
 *              (removed the "SS CATID → canonical" override block —
 *              adapter now writes the final `_CATEGORY_ID` sentinel).
 *            — processRecord() no longer calls
 *              topSlugForCategoryId / accessoryAttrsFor (removed the
 *              two-line accessory-attr merge — adapter now writes
 *              `_ATTR_<col>` sentinels the existing generic
 *              `_ATTR_*` merge picks up).
 *            — processRecord() bug fix (audit defect #1): the
 *              refineCategoryByTitle call was passing `$sTitle` — a
 *              variable only defined inside enrichSportsSouthRecord
 *              (a different method's scope). On PHP 8 that fataled
 *              for every non-SS feed in dev mode and silently coerced
 *              to `''` in production. Replaced with
 *              `(string) ( $mapped['title'] ?? '' )`, which is what
 *              refineCategoryByTitle's title heuristics actually want
 *              to inspect regardless of source.
 *
 *   Importer's public signatures — run(Distributor),
 *   runChunk(Distributor, array), enrichSportsSouthRecord (still a
 *   protected delegating wrapper), processRecord (still protected) —
 *   are all unchanged. No queue class, task class, AdminCP route,
 *   schema, or raw_distributor_data storage shape moved. Sports South
 *   observable output is byte-equivalent to 1.0.118 for both firearm
 *   and accessory records.
 *
 * WHAT THIS UPGRADE DOES
 *   Re-runs the template resync + cache purge from upg_10118 (rule
 *   #79 self-containment) so any prior-version → 1.0.119 install
 *   lands at the same DB state.
 *
 * NO schema change. NO lang change. NO template content change.
 * NO queue registration change. NO task registration change. NO
 * AdminCP controller/route change. NO raw_distributor_data format
 * change.
 *
 * Rule #79: upg_10118 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10119;

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
		$version = '1.0.119';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* Template resync — preserved from upg_10118 so installs
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
						try { \IPS\Log::log( 'upg_10119 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10119' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10119 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10119' ); } catch ( \Throwable ) {}
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
