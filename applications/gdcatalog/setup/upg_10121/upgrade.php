<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.121 (Phase 5: schema & runtime hardening).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.121
 *   Phase 5 hardening (audit 2026-08-25). Fixes five defects without
 *   changing importer architecture or product-data semantics:
 *
 *   Part 1 — SS lookup schema
 *     Adds fresh-install + upgrade-safe schema for the two Sports
 *     South lookup tables that were referenced by code but missing
 *     from data/schema.json (verified by grep in Phase 5):
 *       - gd_sportssouth_categories (catid PK, catdes, last_synced,
 *         raw_data). Populated by feeds.php::processCategoryLookup.
 *         Read by SportsSouthAdapter::enrich() for categoryLookup +
 *         categoryAttrs.
 *       - gd_sportssouth_category_map (sportssouth_catid PK,
 *         gd_category_id + idx_gd_category_id). Read by
 *         SportsSouthAdapter::enrich() to inject _CATEGORY_ID before
 *         CategoryMapper::resolve overrides it.
 *     Also fills in two columns the prior gd_sportssouth_brands
 *     schema was missing (last_synced, raw_data) — feeds.php was
 *     writing both, so fresh installs post-upgrade will accept those
 *     writes without silent MySQL errors.
 *     All schema work is idempotent — checkForTable / checkForColumn
 *     before create/add, no destructive drops. Existing populated
 *     tables (whether installed manually by admin or by any historical
 *     upgrade rotated out per rule #79) are preserved unchanged.
 *
 *   Part 2 — dashboard OpenSearch sync-probe removed
 *     modules/admin/catalog/dashboard.php::manage() no longer calls
 *     OpenSearchIndexer::i()->indexExists() or ->getStats() during
 *     ACP page render. Per CLAUDE.md rule #8, the dashboard must
 *     load even when OpenSearch is unavailable. rebuildIndex /
 *     processQueue admin actions still contact OpenSearch as
 *     designed. Zero HTTP requests reach the cluster during normal
 *     dashboard rendering.
 *
 *   Part 3 — categorize.php Product namespace
 *     modules/admin/catalog/categorize.php:177 previously called
 *     \IPS\gdcatalog\Product::load() — nonexistent class. The
 *     surrounding catch(\Throwable) was silently swallowing the
 *     resulting Error, so every follow-up reindex from admin
 *     categorize actions was a no-op. Fixed to
 *     \IPS\gdcatalog\Catalog\Product::load().
 *
 *   Part 4 — ConflictResolver isLocked() catch broadened
 *     sources/Feed/ConflictResolver.php::isLocked() previously
 *     caught \UnderflowException only (IPS "row not found"). A
 *     genuine DB error (\IPS\Db\Exception / \Error from undefined
 *     method) would propagate uncaught and abort the caller's whole
 *     resolveConflicts pass. Broadened to catch(\Throwable) per
 *     CLAUDE.md rule #35, treating every failure the same as "no
 *     lock found" — the field remains eligible for feed writes,
 *     matching the safest default when lock state is unknown.
 *
 *   Part 5 — image resolution deferral: NOT IMPLEMENTED THIS PHASE.
 *     Preserving highest_res comparison semantics without any silent
 *     "incoming always wins" / "current always wins" behaviour
 *     change requires a persisted image-dimensions cache table +
 *     a background dimensions-fetch queue — a broader data-model
 *     change the Phase 5 prompt explicitly permits deferring. The
 *     synchronous HTTP GET in ConflictResolver::getImageResolution
 *     is untouched by this upgrade. Reported explicitly in the
 *     Phase 5 final report so it is not lost.
 *
 * NO Importer architecture change. NO SS adapter change. NO
 * StructuredFeedAdapter change. NO Importer public API change. NO
 * AdminCP route change. NO queue extension identity change. NO task
 * identity change. NO raw_distributor_data format change. NO product
 * matching / UPC / ConflictResolver-rule change.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Idempotent schema hardening (Part 1) — creates missing SS
 *      lookup tables and adds missing columns on gd_sportssouth_brands.
 *   2. Template resync (rule #79 self-containment) so any prior
 *      version → 1.0.121 install lands at the same DB state.
 *   3. Cache purge / opcache reset so the code changes take effect
 *      on the very next request.
 *
 * Rule #79: upg_10120 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10121;

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
		$version = '1.0.121';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* ---------------- Part 1 — SS lookup schema hardening ---------------- */

		/* gd_sportssouth_categories — created only if absent. Existing
		 * rows (admin-seeded or lookup-refresh-populated) preserved. */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_sportssouth_categories' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_sportssouth_categories',
					'columns' => [
						[ 'name' => 'catid',       'type' => 'INT',        'length' => 11,  'allow_null' => false, 'unsigned' => true ],
						[ 'name' => 'catdes',      'type' => 'VARCHAR',    'length' => 255, 'allow_null' => true,  'default' => null ],
						[ 'name' => 'last_synced', 'type' => 'INT',        'length' => 10,  'allow_null' => true,  'default' => null, 'unsigned' => true ],
						[ 'name' => 'raw_data',    'type' => 'MEDIUMTEXT', 'length' => 0,   'allow_null' => true,  'default' => null ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY', 'columns' => [ 'catid' ] ],
					],
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10121 create gd_sportssouth_categories: ' . $e->getMessage(), 'gdcatalog_upg_10121' ); } catch ( \Throwable ) {}
		}

		/* gd_sportssouth_category_map — created only if absent. Existing
		 * mappings (admin-authored SS-CATID → canonical-id) preserved. */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_sportssouth_category_map' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_sportssouth_category_map',
					'columns' => [
						[ 'name' => 'sportssouth_catid', 'type' => 'INT', 'length' => 11, 'allow_null' => false, 'unsigned' => true ],
						[ 'name' => 'gd_category_id',    'type' => 'INT', 'length' => 11, 'allow_null' => false, 'unsigned' => true ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',            'columns' => [ 'sportssouth_catid' ] ],
						[ 'type' => 'key',     'name' => 'idx_gd_category_id', 'columns' => [ 'gd_category_id' ] ],
					],
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10121 create gd_sportssouth_category_map: ' . $e->getMessage(), 'gdcatalog_upg_10121' ); } catch ( \Throwable ) {}
		}

		/* gd_sportssouth_brands — table already existed pre-Phase-5,
		 * but the schema was missing two columns that feeds.php writes
		 * (last_synced, raw_data). Add them if absent. Existing brand
		 * rows preserved; the new columns default NULL and only get
		 * populated on the next refreshLookups run. */
		try
		{
			if ( \IPS\Db::i()->checkForTable( 'gd_sportssouth_brands' ) )
			{
				if ( !\IPS\Db::i()->checkForColumn( 'gd_sportssouth_brands', 'last_synced' ) )
				{
					\IPS\Db::i()->addColumn( 'gd_sportssouth_brands', [
						'name'       => 'last_synced',
						'type'       => 'INT',
						'length'     => 10,
						'allow_null' => true,
						'default'    => null,
						'unsigned'   => true,
					] );
				}
				if ( !\IPS\Db::i()->checkForColumn( 'gd_sportssouth_brands', 'raw_data' ) )
				{
					\IPS\Db::i()->addColumn( 'gd_sportssouth_brands', [
						'name'       => 'raw_data',
						'type'       => 'MEDIUMTEXT',
						'length'     => 0,
						'allow_null' => true,
						'default'    => null,
					] );
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10121 gd_sportssouth_brands columns: ' . $e->getMessage(), 'gdcatalog_upg_10121' ); } catch ( \Throwable ) {}
		}

		/* ---------------- Template resync (rule #79 self-containment) ---------------- */

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
						try { \IPS\Log::log( 'upg_10121 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10121' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10121 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10121' ); } catch ( \Throwable ) {}
			}
		}

		/* ---------------- Cache / datastore / opcache purge ---------------- */
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
