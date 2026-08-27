<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.128 (Phase 12: SS accumulator cleanup + ImportLog audit).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.128
 *   Phase 12 — small maintenance release. Extends the existing
 *   ReconcileImportJobs scheduled task with an abandoned
 *   Sports South seen-UPCs accumulator cleanup pass. No new task,
 *   no new schema, no importer / adapter / queue-identity change.
 *   ImportLog consolidation for Sports South full imports is
 *   DEFERRED — the audit found runChunk creates one log per chunk
 *   via a call site that Phase 12 would need to leave in place to
 *   preserve every other caller's contract.
 *
 *   Code changes:
 *     - MOD  tasks/ReconcileImportJobs.php
 *            — added `use IPS\gdcatalog\Feed\Distributor`.
 *            — added a third loop: walk
 *              uploads/gdcatalog_ss_seen_upcs_*.jsonl and
 *              conservatively remove abandoned files. Ownership
 *              decision tree per file:
 *                malformed filename       → skip (never delete)
 *                feed row missing         → delete + clear store
 *                                           completion flag
 *                feed auth_type != SS     → delete + clear flag
 *                Distributor::isRunning() → keep (queue owns file)
 *                completion flag present
 *                  + file recent          → keep (postComplete may
 *                                                  be about to run)
 *                file age > STALE
 *                  _THRESHOLD_SECONDS     → delete + clear flag
 *                file recent (else)       → keep (probably active)
 *              Threshold reuses ImportJob::STALE_THRESHOLD_SECONDS
 *              (3600s) for consistency with the generic-job
 *              cleanup already in the same task. Task summary log
 *              now includes the "cleaned N abandoned SS
 *              accumulator file(s)" counter. Zero source-endpoint
 *              HTTP, zero image HTTP, zero OpenSearch, zero
 *              product writes — asserted by Phase 12 test.
 *
 *   NO change to:
 *     - Sports South ImportLog per-chunk design (DEFERRED — see
 *       Phase 12 report for the audit that concluded runChunk must
 *       keep creating its own log to preserve every non-SS
 *       caller's semantics).
 *     - SportsSouthImport queue extension body (Phase 10 semantics
 *       intact; the accumulator file writes + completion-flag
 *       setting all live there and are unaltered).
 *     - Importer public APIs (run, runChunk, processRecord,
 *       processNormalizedRecord, sampleRecords, fetchAndParse,
 *       processDiscontinuationsForSeenUpcs — all unchanged).
 *     - Adapters, FieldMapper, CategoryMapper, SportsSouthClient.
 *     - GenericImport queue extension.
 *     - ImageDimensionCache / FetchImageDimensions (Phase 11
 *       intact — re-asserted by Phase 12 test).
 *     - ImportJob model, ImportLog model.
 *     - AdminCP routes + pre-Phase-12 do= actions.
 *     - Schema, extensions.json registrations, tasks.json
 *       registrations, raw_distributor_data.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Re-seeds every dev/html/*.phtml into core_theme_templates
 *      (rule #52 self-containment — no template body change this
 *      version but keeps the upg dir self-contained per rule #79).
 *   2. Cache / datastore / opcache purge so the updated task
 *      code takes effect on the next tick.
 *
 * Rule #79: upg_10127 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10128;

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
		$version = '1.0.128';
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
						try { \IPS\Log::log( 'upg_10128 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10128' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10128 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10128' ); } catch ( \Throwable ) {}
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
