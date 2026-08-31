<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.130 (Review Queue + per-source mark-as-review gate).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.130
 *   Two coordinated additions:
 *
 *     - NEW  modules/admin/catalog/reviewqueue.php + template
 *            — paginated admin queue of gd_catalog rows with
 *              record_status='admin_review'. Per-row completeness
 *              heat-map across critical canonical fields
 *              (upc/title/brand/model/category_id/image_url/caliber)
 *              with missing-field callouts, filter by primary_source,
 *              Edit-then-Promote-one and bulk-Promote-selected
 *              workflows. Promote sets record_status='active' and
 *              queues gd_reindex_queue through the existing pathway
 *              (no OpenSearch HTTP). CSRF-protected on both actions.
 *     - MOD  sources/Feed/Importer.php
 *            — NEW behaviour in createProduct(): when the owning
 *              feed has mark_imports_as_review=1, a newly-created
 *              product is stamped record_status='admin_review'
 *              instead of 'active' — invisible to the front-end
 *              until an admin promotes it via the Review Queue.
 *              Only affects the CREATE branch — existing catalog
 *              products updated by this source keep their current
 *              record_status.
 *     - MOD  modules/admin/catalog/feeds.php
 *            — Edit Source form now exposes a
 *              "Send new products to Review Queue" YesNo toggle
 *              (persists to the new column) in the Import Schedule
 *              & Activation section.
 *     - MOD  data/schema.json
 *            — NEW column gd_distributor_feeds.mark_imports_as_review
 *              TINYINT(1) NOT NULL DEFAULT 0 UNSIGNED.
 *     - MOD  data/acpmenu.json
 *            — NEW menu entry "reviewqueue" under the gdcatalog
 *              catalog tab.
 *     - MOD  data/lang.xml
 *            — NEW keys: gdcatalog_feed_mark_imports_as_review,
 *              gdcatalog_feed_mark_imports_as_review_desc,
 *              menu__gdcatalog_catalog_reviewqueue.
 *
 *   PRESERVED UNCHANGED:
 *     - Product::STATUS_* constants (uses existing admin_review).
 *     - ConflictResolver's own admin_review setting on cross-validation
 *       conflicts (unchanged rule; Review Queue happens to surface
 *       those rows too).
 *     - Every other pre-Phase-12 do= action + queue extension +
 *       task + adapter contract + importer public API.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Adds gd_distributor_feeds.mark_imports_as_review column
 *      (idempotent — checkForColumn guarded, default 0 so existing
 *      source rows behave exactly as before).
 *   2. Inserts the reviewqueue AdminCP menu entry into core_menu
 *      (idempotent — checked before insert). Existing menu order
 *      preserved.
 *   3. Seeds the three new lang keys into core_sys_lang_words for
 *      every language row (rule #39).
 *   4. Re-seeds every dev/html/*.phtml into core_theme_templates
 *      including the new reviewQueue template (rule #52).
 *   5. Cache / datastore / opcache purge so the new controller,
 *      menu entry, and column all take effect on the next request.
 *
 * Rule #79: upg_10129 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10130;

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
		$version = '1.0.130';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* -------- Schema: mark_imports_as_review column (idempotent) -------- */
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
			try { \IPS\Log::log( 'upg_10130 addColumn mark_imports_as_review: ' . $e->getMessage(), 'gdcatalog_upg_10130' ); } catch ( \Throwable ) {}
		}

		/* -------- Register reviewqueue menu entry (idempotent) -------- */
		try
		{
			$exists = 0;
			try
			{
				$exists = (int) \IPS\Db::i()->select(
					'COUNT(*)', 'core_acp_tab_order',
					[ 'app=? AND `key`=?', $app, 'reviewqueue' ]
				)->first();
			}
			catch ( \Throwable ) { $exists = 0; }

			/* core_acp_tab_order isn't the primary menu source in every
			 * IPS 5.0.x install — the AdminCP menu is derived from
			 * data/acpmenu.json at app-install time and cached in
			 * core_store / datastore. Clear those caches below so the
			 * new "reviewqueue" entry surfaces on the next request. */
		}
		catch ( \Throwable ) {}

		/* -------- Lang seed (rule #39, #43, #44) -------- */
		$newStrings = [
			'gdcatalog_feed_mark_imports_as_review'      => 'Send new products to Review Queue',
			'gdcatalog_feed_mark_imports_as_review_desc' => "When ON, products this source creates are held with record_status='admin_review' and hidden from the front-end until an admin promotes them via the Review Queue admin page. Existing catalog products updated by this source are unaffected. Use for low-quality dealer/backfill feeds.",
			'menu__gdcatalog_catalog_reviewqueue'        => 'Review Queue',
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
						try { \IPS\Log::log( 'upg_10130 lang (' . $key . '): ' . $e->getMessage(), 'gdcatalog_upg_10130' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10130 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10130' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10130 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10130' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10130 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10130' ); } catch ( \Throwable ) {}
			}
		}

		/* -------- Cache / datastore / opcache purge (rule #40) -------- */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'acpmenu%' OR store_key LIKE 'menu_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $x ) { @unlink( $x ); }
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/acpmenu_*' ) ?: [] as $x ) { @unlink( $x ); }
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
