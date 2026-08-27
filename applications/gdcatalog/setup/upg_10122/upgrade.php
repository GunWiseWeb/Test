<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.122 (Phase 6: AdminCP source UX).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.122
 *   Phase 6 — AdminCP structured-source configuration and test/preview UX.
 *   Pure UI + capability-flag + one new controller-action layer. Zero
 *   change to the importer, adapters, queues, tasks, or product-data
 *   semantics.
 *
 *   Code changes:
 *     - MOD  sources/Feed/Importer.php
 *            — added `public static sampleRecords(Distributor, int)` —
 *              the ONLY new Importer API. Runs the existing fetchFeed
 *              + parseFeed pipeline and returns the first N raw
 *              records. Writes nothing (no ImportLog, no product
 *              create/update, no ConflictResolver, no OpenSearch, no
 *              discontinuation, no markRunning). Used by the new
 *              testSource admin action so the controller does not
 *              duplicate fetch/parser logic. All existing Importer
 *              public APIs (run, runChunk, processRecord,
 *              processNormalizedRecord, resolveAdapter) unchanged.
 *     - MOD  modules/admin/catalog/feeds.php
 *            — manage() enriches each source with capability flags
 *              (is_sportssouth, is_manual_upload, is_running,
 *              can_test_source, can_refresh_lookups, can_run,
 *              type_label) and URLs (test_source_url, run_url,
 *              refresh_lookups_url) so the template renders
 *              source-type-appropriate actions instead of showing
 *              every button for every source. Every pre-Phase-6
 *              action URL preserved verbatim; the list template
 *              picks which subset is applicable via the new flags.
 *            — edit() reorganised into logical section headers
 *              (Source Identity / Data Format / Connection /
 *              Import Schedule & Activation / Field Mapping /
 *              Category Mapping / Conflict Detection). The IPS
 *              Select `toggles` array is the mechanism IPS's own
 *              admin form JS uses to show/hide auth_type-dependent
 *              fields — no custom JS needed here.
 *            — edit() now explicitly validates: required feed URL
 *              for URL-based auth_types, required credentials for
 *              basic/apikey, JSON validity for auth_credentials,
 *              field_mapping and category_mapping. Errors block
 *              save (previously invalid JSON was silently dropped
 *              to null, hiding admin typos).
 *            — NEW `testSource()` action — non-destructive preview
 *              for generic structured feeds. Pulls first 5 records
 *              via Importer::sampleRecords, runs each through
 *              StructuredFeedAdapter::normalize, renders raw +
 *              canonical side-by-side. Zero writes. SS feeds
 *              continue to use the existing testConnection action.
 *              manual_upload feeds without an uploaded file get a
 *              clear "upload a file first" error, not a fetch
 *              attempt.
 *            — NEW `runImport()` action — canonical CSRF-protected
 *              "Run Import Now" for any non-SS, non-manual_upload
 *              source. Wraps Importer::run() with success/error
 *              redirect messaging. SS + manual_upload continue on
 *              their existing action names (queue-driven /
 *              runManualFeed respectively).
 *     - MOD  dev/html/admin/catalog/feedList.phtml
 *            — full rewrite with capability-flag-gated button
 *              groups (Primary / Advanced columns). Presentation
 *              uses "Source" terminology. Sports South-only actions
 *              hidden on generic sources and vice versa. All
 *              existing URLs referenced by pre-Phase-6 template
 *              rendered in the same anchor tags, so any admin
 *              bookmarks or URL-typed navigation still work.
 *     - NEW  dev/html/admin/catalog/testSourcePreview.phtml
 *            — read-only raw / canonical side-by-side preview for
 *              the testSource action. Iterates a controller-built
 *              array; no DB, no HTTP, no closures. Non-scalar raw
 *              values are json-encoded inside an
 *              {expression="htmlspecialchars(...)"} wrap.
 *     - MOD  setup/install.php
 *            — after the existing $gdcatalogTemplates delete/insert
 *              pass, adds a dev/html/ REPLACE-loop so fresh installs
 *              get every .phtml file currently in the repo (both the
 *              new testSourcePreview AND the updated feedList body).
 *              Prevents install/upgrade drift on the templates the
 *              array does not carry (rule #52).
 *     - MOD  data/lang.xml
 *            — new section header keys: gdcatalog_feed_section_identity,
 *              _section_data_format, _section_connection,
 *              _section_schedule. Existing lang keys unchanged.
 *
 *   No importer architecture change. No adapter contract change. No
 *   SportsSouthClient / SportsSouthAdapter / StructuredFeedAdapter /
 *   FieldMapper / CategoryMapper change. No queue extension change.
 *   No scheduled task change. No AdminCP route change (every
 *   pre-Phase-6 do= action still exists at the same URL). No
 *   database schema change. No raw_distributor_data storage change.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Re-seeds every dev/html/*.phtml into core_theme_templates so
 *      the updated feedList body and the new testSourcePreview body
 *      land in production (rule #52).
 *   2. Seeds the four new lang keys into core_sys_lang_words for
 *      every lang_id (rule #39).
 *   3. Cache / datastore / opcache purge so the code + templates
 *      take effect on the very next request (rule #40).
 *
 * Rule #79: upg_10121 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10122;

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
		$version = '1.0.122';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* -------- Template resync (rule #52 + #79 self-containment) -------- */
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
						try { \IPS\Log::log( 'upg_10122 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10122' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10122 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10122' ); } catch ( \Throwable ) {}
			}
		}

		/* -------- Lang seed for Phase 6 section headers (rule #39, #43, #44) -------- */
		$newStrings = [
			'gdcatalog_feed_section_identity'    => 'Source Identity',
			'gdcatalog_feed_section_data_format' => 'Data Format',
			'gdcatalog_feed_section_connection'  => 'Connection',
			'gdcatalog_feed_section_schedule'    => 'Import Schedule & Activation',
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
						try { \IPS\Log::log( 'upg_10122 lang (' . $key . '): ' . $e->getMessage(), 'gdcatalog_upg_10122' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10122 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10122' ); } catch ( \Throwable ) {}
		}

		/* -------- Cache / datastore / opcache purge (rule #40) -------- */
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
