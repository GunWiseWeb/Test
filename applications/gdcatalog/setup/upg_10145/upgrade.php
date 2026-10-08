<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.145
 *         OpenSearch: self-heal admin_review / discontinued doc leaks.
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHAT SHIPS IN 1.0.145
 *   Admin reported: Energizer E95BP4 (UPC 039800136887) was showing
 *   in the front-end catalog grid, and clicking it hit 2GDS/2
 *   "could not locate the item" because the product-detail route
 *   correctly filters record_status='active' but the row is now
 *   admin_review (v1.0.142's retro-classify flipped it on account
 *   of an invalid UPC-A checksum).
 *
 *   Root cause: OpenSearchIndexer::indexProduct() and
 *   processQueue() blindly re-indexed every product passed in,
 *   using whatever record_status the row currently had. Combined
 *   with the fact that v1.0.142's retro-classify did a direct Db
 *   update WITHOUT queueing a reindex, the OpenSearch doc for
 *   that UPC still carried record_status='active' from its
 *   original indexing. Searcher's `record_status=active` filter
 *   let it through as a search result. Click → product controller
 *   rejected it → 2GDS/2.
 *
 *   Code fix (self-healing, both directions):
 *     - OpenSearchIndexer::indexProduct() — if record_status is
 *       anything other than 'active' (admin_review, discontinued,
 *       archived), delete the doc from the index instead of
 *       pushing a stale PUT. active ← non-active transition
 *       re-indexes fresh as before.
 *     - OpenSearchIndexer::processQueue() — same guard inside the
 *       bulk loop. Any UPC flipped to non-active and queued (by
 *       any path) ends up DELETEd from the index on the next
 *       worker run.
 *
 *   Guarantees any future flip to admin_review / discontinued
 *   cleans up its OpenSearch doc even when the status change
 *   happens via a path that bypasses Product::save() (direct Db
 *   update, upgrade-time backfill, admin SQL fix).
 *
 *   Data fix (one-time, this upgrade):
 *     - Re-queue every gd_catalog row where record_status != 'active'
 *       into gd_reindex_queue (idempotent — INSERT IGNORE pattern via
 *       EXISTS clause). The scheduled OpenSearch worker picks them
 *       up on its next tick and DELETEs each stale doc from the
 *       index. After the next worker cycle, no admin_review /
 *       discontinued UPCs remain in the front-end search index.
 *
 *   NO schema change. NO new extension / lang key.
 *
 * WHAT THIS UPGRADE DOES (idempotent, safe to re-run)
 *   1. Idempotent 1.0.130 schema hoist (mark_imports_as_review).
 *   2. Idempotent 1.0.142 audit-column hoist on gd_catalog.
 *   3. Re-queue stale non-active rows into gd_reindex_queue.
 *   4. Seeds the four accumulated lang keys.
 *   5. Re-seeds every dev/html/*.phtml.
 *   6. Cache / datastore / opcache purge.
 *
 * Rule #79: upg_10144 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10145;

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
		$version = '1.0.145';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* -------- 1.0.130 schema hoist (idempotent) -------- */
		try
		{
			if ( \IPS\Db::i()->checkForTable( 'gd_distributor_feeds' )
				&& !\IPS\Db::i()->checkForColumn( 'gd_distributor_feeds', 'mark_imports_as_review' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_distributor_feeds', [
					'name'       => 'mark_imports_as_review',
					'type'       => 'TINYINT',
					'length'     => 1,
					'allow_null' => false,
					'default'    => 0,
					'unsigned'   => true,
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10145 addColumn mark_imports_as_review: ' . $e->getMessage(), 'gdcatalog_upg_10145' ); } catch ( \Throwable ) {}
		}

		/* -------- v1.0.142 audit columns on gd_catalog (idempotent) -------- */
		try
		{
			if ( \IPS\Db::i()->checkForTable( 'gd_catalog' ) )
			{
				$auditCols = [
					'upc_audit_status'      => [ 'type' => 'VARCHAR', 'length' => 64 ],
					'upc_audit_notes'       => [ 'type' => 'TEXT',    'length' => 0  ],
					'suggested_correct_upc' => [ 'type' => 'VARCHAR', 'length' => 32 ],
					'verified_mpn'          => [ 'type' => 'VARCHAR', 'length' => 64 ],
					'upc_audit_source'      => [ 'type' => 'TEXT',    'length' => 0  ],
				];
				foreach ( $auditCols as $colName => $meta )
				{
					try
					{
						if ( !\IPS\Db::i()->checkForColumn( 'gd_catalog', $colName ) )
						{
							\IPS\Db::i()->addColumn( 'gd_catalog', [
								'name'       => $colName,
								'type'       => $meta['type'],
								'length'     => $meta['length'],
								'allow_null' => true,
								'default'    => null,
							] );
						}
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10145 addColumn ' . $colName . ': ' . $e->getMessage(), 'gdcatalog_upg_10145' ); } catch ( \Throwable ) {}
					}
				}
				try
				{
					if ( !\IPS\Db::i()->checkForIndex( 'gd_catalog', 'idx_upc_audit_status' ) )
					{
						\IPS\Db::i()->addIndex( 'gd_catalog', [
							'type'    => 'key',
							'name'    => 'idx_upc_audit_status',
							'columns' => [ 'upc_audit_status' ],
							'length'  => [ 32 ],
						] );
					}
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'upg_10145 addIndex idx_upc_audit_status: ' . $e->getMessage(), 'gdcatalog_upg_10145' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10145 audit column bootstrap: ' . $e->getMessage(), 'gdcatalog_upg_10145' ); } catch ( \Throwable ) {}
		}

		/* -------- One-time: re-queue every stale non-active row -------- */
		try
		{
			if ( \IPS\Db::i()->checkForTable( 'gd_catalog' )
				&& \IPS\Db::i()->checkForTable( 'gd_reindex_queue' ) )
			{
				$queued = 0;
				$now = date( 'Y-m-d H:i:s' );
				/* Only pick rows not already queued, and only those
				 * whose record_status would make them stale in the
				 * search index. Use REPLACE INTO for safety but
				 * batched in Php loop for clarity + per-row catch. */
				$rs = \IPS\Db::i()->select(
					'c.upc',
					[ 'gd_catalog', 'c' ],
					[ "c.record_status != ? AND NOT EXISTS ( SELECT 1 FROM " . \IPS\Db::i()->prefix . "gd_reindex_queue q WHERE q.upc = c.upc )", 'active' ]
				);
				foreach ( $rs as $row )
				{
					try
					{
						\IPS\Db::i()->replace( 'gd_reindex_queue', [
							'upc'       => (string) $row['upc'],
							'queued_at' => $now,
						] );
						$queued++;
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10145 requeue upc=' . $row['upc'] . ': ' . $e->getMessage(), 'gdcatalog_upg_10145' ); } catch ( \Throwable ) {}
					}
				}
				try { \IPS\Log::log( 'upg_10145 OpenSearch stale-doc cleanup: queued ' . $queued . ' non-active UPCs for next worker tick', 'gdcatalog_upg_10145' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10145 requeue outer: ' . $e->getMessage(), 'gdcatalog_upg_10145' ); } catch ( \Throwable ) {}
		}

		/* -------- Lang seed (accumulated from 1.0.130 + 1.0.132) -------- */
		$newStrings = [
			'gdcatalog_feed_mark_imports_as_review'      => 'Send new products to Review Queue',
			'gdcatalog_feed_mark_imports_as_review_desc' => "When ON, products this source creates are held with record_status='admin_review' and hidden from the front-end until an admin promotes them via the Review Queue admin page. Existing catalog products updated by this source are unaffected. Use for low-quality dealer/backfill feeds.",
			'menu__gdcatalog_catalog_reviewqueue'        => 'Review Queue',
			'menu__gdcatalog_catalog_categorize'         => 'Categorize',
		];
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => $app,
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( 'upg_10145 lang (' . $key . '): ' . $e->getMessage(), 'gdcatalog_upg_10145' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10145 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10145' ); } catch ( \Throwable ) {}
		}

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
						try { \IPS\Log::log( 'upg_10145 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10145' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10145 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10145' ); } catch ( \Throwable ) {}
			}
		}

		/* -------- Cache / datastore / opcache purge (rule #40) -------- */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'acpmenu%' OR store_key LIKE 'menu_%' OR store_key LIKE 'lang_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $x ) { @unlink( $x ); }
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/acpmenu_*' ) ?: [] as $x ) { @unlink( $x ); }
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/lang_*' ) ?: [] as $x ) { @unlink( $x ); }
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpMenu ); }            catch ( \Throwable ) {}
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
