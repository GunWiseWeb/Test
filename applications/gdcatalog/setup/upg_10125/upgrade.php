<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.125 (Phase 9: reconciliation + cleanup).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.125
 *   Phase 9 — small maintenance release. Makes the background
 *   import system self-healing and internally consistent. No
 *   importer architecture change; no adapter contract change; no
 *   schema change (stale detection uses existing updated_at and
 *   cursor_data JSON).
 *
 *   Code changes:
 *     - MOD  sources/Feed/ImportJob.php
 *            — added STALE_THRESHOLD_SECONDS constant (3600).
 *            — added isStale() — true when status=queued/running
 *              and updated_at older than the threshold. Never
 *              returns true for a terminal-status row.
 *            — added isResumable() — true when status=failed AND
 *              cursor.stage_ready AND staged file exists on disk
 *              AND cursor.offset > 0 AND (total===0 OR offset<total).
 *              Matches Phase 8 retryImport's resume decision.
 *            — added reconcile() — deterministic decision tree that
 *              brings ImportJob + Distributor + ImportLog + staged
 *              file into agreement for one row. No network, no
 *              product writes, no discontinuation. See the model's
 *              docblock for the full tree.
 *            — added finalizeLogAsCompleted / finalizeLogAsFailed
 *              helpers used by reconcile. Idempotent — only fire
 *              when the log isn't already terminal.
 *     - MOD  modules/admin/catalog/feeds.php
 *            — cancelImport() now delegates to reconcile() so the
 *              ImportLog reaches a terminal state (fail with the
 *              "Cancelled by administrator after N records" message
 *              — closest terminal representation ImportLog offers).
 *              Preserves Phase 8 semantics: feed no longer running,
 *              staged file deleted, discontinuation not called.
 *            — resetFeedStatus() now reconciles the full source
 *              state. Refuses to disrupt a healthy active job
 *              (directs admin to Cancel Import instead). Reconciles
 *              stale/abandoned jobs. Handles the no-job fallback
 *              path (legacy Distributor-only reset). Preserves the
 *              do=resetFeedStatus route + csrfCheck-on-POST.
 *     - NEW  tasks/ReconcileImportJobs.php
 *            — hourly scheduled task (PT1H). Two responsibilities:
 *              (1) reconcile stale active jobs — SELECT rows with
 *              status IN (queued,running) AND updated_at older than
 *              STALE_THRESHOLD_SECONDS, iterate + reconcile();
 *              (2) clean orphan staged files — glob
 *              uploads/gdcatalog_job_*.json and unlink files whose
 *              owning ImportJob is missing / completed / cancelled /
 *              failed-and-not-resumable. Resumable-failed staged
 *              files are always preserved. Task performs zero
 *              source-endpoint I/O — asserted by Phase 9 test.
 *     - MOD  data/tasks.json
 *            — registered ReconcileImportJobs at PT1H.
 *
 *   NO schema change. NO lang change. NO template content change.
 *   NO queue extension identity change. NO existing scheduled task
 *   identity change. NO AdminCP route change.
 *
 * PRESERVED UNCHANGED:
 *   - Importer public APIs.
 *   - SourceAdapterInterface, SportsSouthAdapter,
 *     StructuredFeedAdapter, SportsSouthClient, FieldMapper,
 *     CategoryMapper, ConflictResolver.
 *   - SportsSouthImport queue extension.
 *   - GenericImport queue extension body (postComplete still
 *     handles the terminal-status routing added in Phase 8;
 *     Phase 9 reconciliation runs from cancelImport /
 *     resetFeedStatus / task, so a live postComplete has
 *     nothing new to do — the two paths remain independent).
 *   - Test Source (do=testSource) + sampleRecords — still small
 *     synchronous read-only preview.
 *   - Every pre-Phase-9 AdminCP do= action URL.
 *   - gd_catalog + gd_import_jobs schema, raw_distributor_data
 *     storage format.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Registers the ReconcileImportJobs scheduled task in
 *      core_tasks (idempotent — INSERT IGNORE-style by checking
 *      row absence first). Existing installs pick up the hourly
 *      run automatically; fresh installs already register it via
 *      data/tasks.json.
 *   2. Re-seeds every dev/html/*.phtml into core_theme_templates
 *      (rule #52 self-containment) — no template body change this
 *      version, but the resync is harmless and keeps the upgrade
 *      dir self-contained per rule #79.
 *   3. Cache / datastore / opcache purge so the new task
 *      registration + updated code take effect on the next tick.
 *
 * Rule #79: upg_10124 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10125;

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
		$version = '1.0.125';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* -------- Register ReconcileImportJobs task (idempotent) -------- */
		try
		{
			$exists = 0;
			try
			{
				$exists = (int) \IPS\Db::i()->select(
					'COUNT(*)', 'core_tasks',
					[ 'app=? AND `key`=?', $app, 'ReconcileImportJobs' ]
				)->first();
			}
			catch ( \Throwable ) {}

			if ( $exists === 0 )
			{
				try
				{
					\IPS\Db::i()->insert( 'core_tasks', [
						'app'       => $app,
						'key'       => 'ReconcileImportJobs',
						'frequency' => 'PT1H',
						'enabled'   => 1,
						'next_run'  => time(),
					] );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'upg_10125 core_tasks insert: ' . $e->getMessage(), 'gdcatalog_upg_10125' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10125 core_tasks probe: ' . $e->getMessage(), 'gdcatalog_upg_10125' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10125 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10125' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10125 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10125' ); } catch ( \Throwable ) {}
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
