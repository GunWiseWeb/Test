<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.134 (Review Queue "Categories CSV" export).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHAT SHIPS IN 1.0.134
 *   Small feature: Review Queue now has a second download button —
 *   "Categories CSV" — that dumps the full gd_categories tree (id,
 *   name, slug, parent_id, parent_name, full_path) as a companion
 *   file for the Review Queue enrichment CSV. Workflow:
 *
 *     1. Filter Review Queue by source.
 *     2. Click "Export CSV" (rows to enrich).
 *     3. Click "Categories CSV" (valid category names).
 *     4. Attach BOTH files to the AI enrichment prompt with
 *        instructions like: "For the `category` column, use ONLY
 *        exact names from categories.csv — do not invent labels."
 *     5. Configure a manual-upload CSV source with field_mapping
 *        matching the enriched CSV's canonical columns and a
 *        category_mapping JSON that resolves category names to
 *        gd_categories.slug or id. Set medium priority (above
 *        dealer feeds, below wholesale like Sports South).
 *     6. Upload the enriched CSV, Run Import — existing admin_review
 *        rows are updated in place (Importer's update branch does
 *        NOT flip record_status, so rows stay in the Review Queue).
 *     7. Back in Review Queue, completeness bars are now higher,
 *        Promote to active.
 *
 *   Code changes:
 *     - MOD  modules/admin/catalog/reviewqueue.php
 *            — NEW protected exportCategoriesCsv() action. GET-only,
 *              read-only, no CSRF (rule #62). Streams the full
 *              gd_categories tree with a computed full_path
 *              breadcrumb so the AI can disambiguate leaf names
 *              that repeat across parents.
 *            — manage() now passes $exportCategoriesUrl to the
 *              template.
 *     - MOD  dev/html/admin/catalog/reviewQueue.phtml
 *            — NEW $exportCategoriesUrl parameter.
 *            — NEW "Categories CSV" button next to "Export CSV".
 *
 *   NO schema change. NO extension/task registration change. NO
 *   AdminCP menu change. NO importer/adapter/queue behaviour change.
 *
 * PRESERVED UNCHANGED:
 *   - No new DB column, no new table.
 *   - No new lang key required (button label is plain string in the
 *     template — matches existing style).
 *   - Round-trip re-import path unchanged from 1.0.133.
 *
 * WHAT THIS UPGRADE DOES (idempotent, safe to re-run)
 *   1. Idempotent 1.0.130 schema hoist (adds
 *      gd_distributor_feeds.mark_imports_as_review if absent).
 *   2. Seeds the four accumulated lang keys carried since 1.0.130 /
 *      1.0.132 in case a prior upgrade missed them.
 *   3. Re-seeds every dev/html/*.phtml — including the updated
 *      reviewQueue template with the Categories CSV button.
 *   4. Cache / datastore / opcache purge.
 *
 * Rule #79: upg_10133 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10134;

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
		$version = '1.0.134';
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
			try { \IPS\Log::log( 'upg_10134 addColumn: ' . $e->getMessage(), 'gdcatalog_upg_10134' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10134 lang (' . $key . '): ' . $e->getMessage(), 'gdcatalog_upg_10134' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10134 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10134' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10134 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10134' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10134 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10134' ); } catch ( \Throwable ) {}
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
