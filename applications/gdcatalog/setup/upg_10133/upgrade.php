<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.133 (Review Queue CSV export).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 *
 * WHAT SHIPS IN 1.0.133
 *   Small feature: Review Queue can now export the current filter's
 *   rows as a CSV. Column order matches FieldMapper::VALID_FIELDS
 *   plus four informational trailing columns (primary_source,
 *   record_status, completeness_pct, missing_fields) that the
 *   round-trip re-import path can safely ignore because they fall
 *   outside VALID_FIELDS. The intended workflow:
 *
 *     1. Filter Review Queue by source → click Export CSV.
 *     2. Hand the CSV to an AI enrichment step (external).
 *     3. Configure a manual-upload CSV source in gdcatalog with
 *        field_mapping mapping the enriched CSV's canonical
 *        columns 1:1, medium priority (above dealer feeds, below
 *        wholesale like Sports South).
 *     4. Upload the enriched CSV to that source, Run Import.
 *     5. Existing admin_review products get updated with enriched
 *        fields (record_status stays admin_review — Importer's
 *        update branch does not flip status).
 *     6. Return to Review Queue, completeness bars are now higher,
 *        Promote to active.
 *
 *   Code changes:
 *     - MOD  modules/admin/catalog/reviewqueue.php
 *            — NEW protected const CSV_EXPORT_COLUMNS covering all
 *              of FieldMapper::VALID_FIELDS + informational trailers.
 *            — NEW protected exportCsv() action. GET-only,
 *              read-only, no CSRF (per CLAUDE.md rule #62). Streams
 *              CSV via Output::sendOutput with a filename that
 *              includes the source filter + timestamp.
 *            — manage() now passes an exportCsvUrl parameter through
 *              to the template (includes the current source filter
 *              in the URL so the download honours what the admin
 *              is currently viewing).
 *     - MOD  dev/html/admin/catalog/reviewQueue.phtml
 *            — NEW $exportCsvUrl parameter.
 *            — NEW "Export CSV" button in the header row, next to
 *              the source filter dropdown.
 *
 *   NO schema change. NO extension/task registration change. NO
 *   AdminCP menu change. NO importer/adapter/queue behaviour change.
 *
 * PRESERVED UNCHANGED:
 *   - CSV export is one new GET route (do=exportCsv on the existing
 *     reviewqueue controller). Every other pre-1.0.133 do= action
 *     kept.
 *   - No new DB column, no new table.
 *   - No new lang key required (the Export button label is a plain
 *     string in the template — matches the existing template style).
 *   - Round-trip re-import uses the EXISTING manual-upload CSV
 *     source type + StructuredFeedAdapter + GenericImport queue.
 *     No new import mechanism.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Idempotent 1.0.130 schema hoist (adds
 *      gd_distributor_feeds.mark_imports_as_review if absent).
 *   2. Seeds the four accumulated lang keys.
 *   3. Re-seeds every dev/html/*.phtml — including the updated
 *      reviewQueue template with the Export button.
 *   4. Cache / datastore / opcache purge.
 *
 * Rule #79: upg_10132 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10133;

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
		$version = '1.0.133';
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
			try { \IPS\Log::log( 'upg_10133 addColumn: ' . $e->getMessage(), 'gdcatalog_upg_10133' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10133 lang (' . $key . '): ' . $e->getMessage(), 'gdcatalog_upg_10133' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10133 lang loop: ' . $e->getMessage(), 'gdcatalog_upg_10133' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10133 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10133' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10133 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10133' ); } catch ( \Throwable ) {}
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
