<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.123 (Phase 7: resumable background jobs).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.123
 *   Phase 7 — background / resumable import jobs for generic
 *   structured feeds (auth_type != 'sportssouth'). Sports South
 *   continues to use its existing SportsSouthImport queue extension
 *   with its own DailyItemUpdate LastItem pagination — untouched.
 *
 *   Code changes:
 *     - NEW  sources/Feed/ImportJob.php
 *            — ActiveRecord for gd_import_jobs. Lifecycle helpers:
 *              enqueueFor(), activeForFeed(), claim(),
 *              cursor()/saveCursor(), markCompleted/Failed/Cancelled.
 *              Concurrency: claim() uses atomic
 *              "UPDATE ... WHERE status='queued'" so two workers
 *              cannot enter the same job's run() batch.
 *     - NEW  extensions/core/Queue/GenericImport.php
 *            — IPS QueueAbstract-shaped background worker for
 *              generic feeds. preQueueData fetches + parses ONCE and
 *              stages parsed records to uploads/gdcatalog_job_{id}.json;
 *              run(&$data, $offset) processes bounded 500-record
 *              batches via Importer::runChunk (existing pipeline);
 *              postComplete finalises the gd_import_log row (ONE per
 *              logical job), runs discontinuation with the FULL
 *              accumulated seen-UPC set, marks the feed complete, and
 *              deletes the staged file. Refuses SS feeds up-front.
 *     - MOD  data/extensions.json
 *            — registered GenericImport under Queue.
 *     - MOD  sources/Feed/Importer.php
 *            — added public static fetchAndParse(Distributor) so the
 *              queue extension does not duplicate fetchFeed/parseFeed.
 *            — added public static
 *              processDiscontinuationsForSeenUpcs(Distributor, array)
 *              — same algorithm and 80%-coverage guard as
 *              processDiscontinuations, but takes seenUpcs from an
 *              argument so the queue's postComplete can supply the
 *              accumulated set across all batches. No thresholds or
 *              rules changed. All existing public APIs (run,
 *              runChunk, processRecord, processNormalizedRecord,
 *              sampleRecords) preserved verbatim.
 *     - MOD  modules/admin/catalog/feeds.php
 *            — runImport() now enqueues a GenericImport job and
 *              redirects immediately with "Import queued." No
 *              synchronous Importer::run() from the browser request.
 *            — NEW retryImport() action — CSRF-protected re-queue
 *              for a failed job.
 *            — NEW cancelImport() action — CSRF-protected cancel
 *              for a queued/running job (marks status=cancelled;
 *              next run() batch short-circuits).
 *            — manage() enriched each source with local job state
 *              (job_status, job_active, job_failed, job_progress,
 *              job_last_error, job_updated_at, retry_import_url,
 *              cancel_import_url). Reads only local DB; zero HTTP.
 *     - MOD  dev/html/admin/catalog/feedList.phtml
 *            — Cancel Import / Retry Import buttons gated on job
 *              state. New collapsed status row shows queued /
 *              running progress from the local cursor, and failed
 *              jobs surface the last error banner.
 *     - MOD  data/schema.json
 *            — added gd_import_jobs (id PK, feed_id, status,
 *              cursor_data MEDIUMTEXT, import_log_id, started_at,
 *              updated_at, completed_at, last_error, indexed by
 *              feed_id+status and status alone).
 *
 *   PRESERVED UNCHANGED:
 *     - SportsSouthImport queue extension (byte-equivalent).
 *     - Importer public APIs.
 *     - SourceAdapterInterface, SportsSouthAdapter,
 *       StructuredFeedAdapter, SportsSouthClient, FieldMapper,
 *       CategoryMapper, ConflictResolver — none touched.
 *     - Test Source (do=testSource) + sampleRecords — still a small
 *       synchronous read-only preview.
 *     - Every pre-Phase-7 AdminCP do= action URL.
 *     - gd_catalog schema, raw_distributor_data storage format.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Creates gd_import_jobs if absent (idempotent — checkForTable
 *      guard, no destructive drops).
 *   2. Re-seeds every dev/html/*.phtml into core_theme_templates so
 *      the Phase 7 feedList row additions land in production.
 *   3. Cache / datastore / opcache purge so the new queue extension
 *      registration is picked up and the code changes take effect.
 *
 * Rule #79: upg_10122 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10123;

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
		$version = '1.0.123';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* -------- Schema: gd_import_jobs (idempotent) -------- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_import_jobs' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_import_jobs',
					'columns' => [
						[ 'name' => 'id',            'type' => 'INT',        'length' => 10, 'allow_null' => false, 'auto_increment' => true, 'unsigned' => true ],
						[ 'name' => 'feed_id',       'type' => 'INT',        'length' => 10, 'allow_null' => false, 'unsigned' => true ],
						[ 'name' => 'status',        'type' => 'VARCHAR',    'length' => 20, 'allow_null' => false, 'default' => 'queued' ],
						[ 'name' => 'cursor_data',   'type' => 'MEDIUMTEXT', 'length' => 0,  'allow_null' => true,  'default' => null ],
						[ 'name' => 'import_log_id', 'type' => 'INT',        'length' => 10, 'allow_null' => true,  'default' => null, 'unsigned' => true ],
						[ 'name' => 'started_at',    'type' => 'INT',        'length' => 10, 'allow_null' => true,  'default' => null, 'unsigned' => true ],
						[ 'name' => 'updated_at',    'type' => 'INT',        'length' => 10, 'allow_null' => true,  'default' => null, 'unsigned' => true ],
						[ 'name' => 'completed_at',  'type' => 'INT',        'length' => 10, 'allow_null' => true,  'default' => null, 'unsigned' => true ],
						[ 'name' => 'last_error',    'type' => 'TEXT',       'length' => 0,  'allow_null' => true,  'default' => null ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',         'columns' => [ 'id' ] ],
						[ 'type' => 'key',     'name' => 'idx_feed_status', 'columns' => [ 'feed_id', 'status' ] ],
						[ 'type' => 'key',     'name' => 'idx_status',      'columns' => [ 'status' ] ],
					],
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10123 create gd_import_jobs: ' . $e->getMessage(), 'gdcatalog_upg_10123' ); } catch ( \Throwable ) {}
		}

		/* -------- Extensions registry rebuild (rule #16) -------- */
		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}

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
						try { \IPS\Log::log( 'upg_10123 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10123' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10123 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10123' ); } catch ( \Throwable ) {}
			}
		}

		/* -------- Cache / datastore / opcache purge (rule #40) -------- */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                                catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $x ) { @unlink( $x ); }
		try { unset( \IPS\Data\Store::i()->themes ); }             catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
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
