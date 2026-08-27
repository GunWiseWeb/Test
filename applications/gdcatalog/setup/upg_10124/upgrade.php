<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.124 (Phase 8: job correctness + full async).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.124
 *   Phase 8 — correctness / execution hardening on the Phase 7 job
 *   architecture. Pure behavioural fix; no importer architecture
 *   change; no adapter contract change; no product-data semantics
 *   change; no schema change.
 *
 *   Code changes:
 *     - MOD  sources/Feed/ImportJob.php
 *            — enqueueFor() is now atomic (INSERT ... SELECT WHERE
 *              NOT EXISTS via preparedQuery + affected_rows check).
 *              Two concurrent Run Import clicks can no longer both
 *              create active queued rows for the same feed.
 *            — added reopen() for retry-with-resume; atomic
 *              conditional UPDATE on status=failed → queued.
 *            — added deleteStagedFile() so cancelImport +
 *              fresh-retry paths can remove
 *              uploads/gdcatalog_job_*.json deterministically.
 *            — cursor JSON now carries stage_ready,
 *              batch_retry_count, batch_last_error,
 *              total_records — all initialised on enqueue.
 *     - MOD  extensions/core/Queue/GenericImport.php
 *            — preQueueData() no longer performs any fetch, parse,
 *              stage-write, or markRunning. IPS runs preQueueData
 *              SYNCHRONOUSLY inside Queue::queue(); leaving the
 *              fetch there (Phase 7) stalled the browser click.
 *              Fetch/parse/stage moved to the FIRST run() batch,
 *              gated on cursor.stage_ready=false. That batch also
 *              calls Distributor::markRunning so the source list
 *              only shows "running" once actual work has begun.
 *            — bounded batch retry: on a thrown runChunk(),
 *              GenericImport::run keeps the SAME offset,
 *              increments cursor.batch_retry_count, and records
 *              cursor.batch_last_error. After MAX_BATCH_RETRIES=3
 *              consecutive failures at the same offset, the job is
 *              marked failed with an explicit "Batch at offset N
 *              failed 3 times" message. Records are NEVER silently
 *              skipped.
 *            — batch success resets batch_retry_count to 0.
 *            — postComplete now respects the terminal job status:
 *              failed → ImportLog::fail (not complete), feed
 *              marked failed; cancelled → ImportLog left
 *              untouched, feed resetRunningStatus; completed →
 *              same as pre-Phase-8. Discontinuation runs ONLY on
 *              the completed path (asserted by Phase 8 tests).
 *            — staged file lifecycle:
 *                completed  → deleted
 *                cancelled  → deleted (also from cancelImport, so
 *                             this is idempotent belt & suspenders)
 *                failed     → KEPT so retry-with-resume can use it
 *                             (fresh retry deletes it explicitly).
 *            — preQueueData reuses an existing import_log_id when
 *              a job is being reopened, so one logical import
 *              maps to one gd_import_log row across batch retries
 *              and resumes.
 *     - MOD  modules/admin/catalog/feeds.php
 *            — cancelImport(): also resets Distributor
 *              last_run_status via resetRunningStatus() and deletes
 *              the staged file. Admin no longer has to click Reset
 *              Status after a Cancel.
 *            — retryImport(): chooses resume vs fresh. Resume when
 *              the failed job has a staged file present + offset >
 *              0 (batch-processing failure). Fresh otherwise
 *              (fetch/config failure before staging). Message
 *              string reflects the chosen mode.
 *            — runImport(): still enqueues + redirects. No new
 *              logic here — the async win comes from the queue
 *              extension moving fetch to first-run.
 *     - MOD  tasks/ImportFeeds.php
 *            — scheduled task now enqueues GenericImport for
 *              generic feeds (auth_type != 'sportssouth') instead
 *              of calling Importer::run() synchronously. Sports
 *              South scheduling still uses Importer::run() (SS
 *              handling remains out of Phase 8 scope). Skips
 *              feeds that already have an active job — never
 *              double-queues.
 *
 *   NO schema change. NO lang change. NO template content change.
 *   NO queue extension identity change (GenericImport keeps its
 *   registered name). NO scheduled task identity change (ImportFeeds
 *   keeps its registered name). NO AdminCP route change.
 *
 * PRESERVED UNCHANGED:
 *   - Importer public APIs.
 *   - SourceAdapterInterface, SportsSouthAdapter,
 *     StructuredFeedAdapter, SportsSouthClient, FieldMapper,
 *     CategoryMapper, ConflictResolver.
 *   - SportsSouthImport queue extension.
 *   - Test Source (do=testSource) + sampleRecords — still small
 *     synchronous read-only preview; asserted by Phase 8 tests.
 *   - Every pre-Phase-8 AdminCP do= action URL.
 *   - gd_catalog schema, gd_import_jobs schema (unchanged;
 *     cursor_data JSON simply carries new keys), raw_distributor_data.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Re-seeds every dev/html/*.phtml into core_theme_templates
 *      (rule #52 self-containment).
 *   2. Cache / datastore / opcache purge so the new queue extension
 *      behaviour + updated code take effect on the next request.
 *   3. Does NOT create or drop any table — schema is unchanged.
 *
 * Rule #79: upg_10123 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10124;

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
		$version = '1.0.124';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

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
						try { \IPS\Log::log( 'upg_10124 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10124' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10124 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10124' ); } catch ( \Throwable ) {}
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
