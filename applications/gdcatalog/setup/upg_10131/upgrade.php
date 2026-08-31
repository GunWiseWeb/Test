<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.131 (\IPS\Task\Queue::queue → \IPS\Task::queue fix).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained: carries
 * ALL of 1.0.130's migrations too so a 1.0.129 → 1.0.131 direct
 * upgrade lands at the same state as if 1.0.130 had run first.
 *
 * WHAT SHIPS IN 1.0.131
 *   Single-line code fix: 8 call sites across 4 files used
 *   `\IPS\Task\Queue::queue()` (nonexistent class in IPS 5.0.18) —
 *   should be `\IPS\Task::queue()` (the static method the
 *   dashboard/products controllers already use). The wrong form
 *   ate every Run Import click on generic feeds with
 *   `Queue::queue() rejected: Class "IPS\Task\Queue" not found`.
 *
 *   Files patched:
 *     - modules/admin/catalog/dashboard.php (BackfillAttributes /
 *       ResolveBrands stray)
 *     - modules/admin/catalog/feeds.php (runImport + retryImport)
 *     - tasks/ImportFeeds.php (generic enqueue + BackfillAttributes
 *       + ResolveBrands strays)
 *     - sources/Feed/ImageDimensionCache.php (FetchImageDimensions
 *       enqueue)
 *
 *   The fix is pure PHP-file replacement — takes effect the moment
 *   the tar is extracted. No DB state change is required for the
 *   fix itself; the upgrade only carries forward the Review Queue
 *   DB migrations from 1.0.130 so a direct-jump upgrade
 *   (1.0.128 or 1.0.129 → 1.0.131) still lands correctly.
 *
 * WHAT THIS UPGRADE DOES (mostly 1.0.130 carried forward)
 *   1. Adds gd_distributor_feeds.mark_imports_as_review TINYINT(1)
 *      NOT NULL DEFAULT 0 UNSIGNED column (idempotent).
 *   2. Seeds the three 1.0.130 lang keys into core_sys_lang_words
 *      for every language row (rule #39).
 *   3. Re-seeds every dev/html/*.phtml into core_theme_templates
 *      including the Review Queue template (rule #52).
 *   4. Cache / datastore / opcache purge so the new controller,
 *      menu entry, Queue::queue fix, and column all take effect on
 *      the next request.
 *
 *   PRESERVED UNCHANGED: adapters, importer public APIs, queue
 *   extension identities, task identities, AdminCP routes, schema
 *   (only 1.0.130's already-shipped column is added if absent),
 *   raw_distributor_data.
 *
 * Rule #79: upg_10130 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10131;

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
		$version = '1.0.131';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* -------- 1.0.130 schema: mark_imports_as_review (idempotent) -------- */
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
			try { \IPS\Log::log( 'upg_10131 addColumn mark_imports_as_review: ' . $e->getMessage(), 'gdcatalog_upg_10131' ); } catch ( \Throwable ) {}
		}

		/* -------- 1.0.130 lang seed -------- */
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
						try { \IPS\Log::log( 'upg_10131 lang (' . $key . '): ' . $e->getMessage(), 'gdcatalog_upg_10131' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10131 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10131' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10131 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10131' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10131 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10131' ); } catch ( \Throwable ) {}
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
