<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.126 (Phase 10: SS full-import discontinuation).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.126
 *   Phase 10 — one-defect fix: the Sports South queued full-import
 *   path (SportsSouthImport queue extension) now performs the same
 *   logical unseen-product discontinuation evaluation the audit
 *   expects after a complete source import. Pre-Phase-10 SS full
 *   imports never called processDiscontinuations, leaving stale
 *   products indefinitely active for the Sports South distributor.
 *
 *   Code changes:
 *     - MOD  extensions/core/Queue/SportsSouthImport.php
 *            — preQueueData: initialise `$data['ss_completed_naturally']
 *              = false` and purge any leftover
 *              `uploads/gdcatalog_ss_seen_upcs_{feed_id}.jsonl` +
 *              core_store completion flag from a previous aborted run.
 *              A fresh queue must start with a clean accumulator so
 *              stale UPCs cannot skew the coverage guard.
 *            — run(): after each non-empty chunk fetch, extract the
 *              chunk's raw UPCs via FieldMapper + UpcValidator (the
 *              exact same normalization pipeline
 *              processNormalizedRecord uses per record — no second
 *              UPC parser), and APPEND them to
 *              uploads/gdcatalog_ss_seen_upcs_{feed_id}.jsonl BEFORE
 *              calling Importer::runChunk. Appending before runChunk
 *              means a chunk-processing throw still counts the UPCs
 *              as "observed in source" (correct discontinuation
 *              semantics — SS returned the product; we just failed
 *              to write it).
 *            — run(): the empty-response end-of-catalog branch sets
 *              BOTH `$data['ss_completed_naturally'] = true` AND
 *              `\IPS\Data\Store::i()->gdcatalog_ss_completed_naturally_{feed_id}
 *              = 1` before throwing QueueOutOfRangeException. The
 *              store copy survives the "$data resets after
 *              OutOfRangeException" case that pre-Phase-7 required
 *              the feed_id recovery hack for.
 *            — postComplete: after markCompleted, gate the new
 *              Importer::processDiscontinuationsForSeenUpcs call on
 *              natural completion (either $data flag OR store flag
 *              truthy). Failed / cancelled / aborted queue runs
 *              never set the flag → discontinuation is skipped.
 *              The seen-UPCs file is deleted at end of postComplete
 *              in ALL cases so a partial set cannot survive to
 *              skew a future run.
 *            — added seenUpcsPath() + readSeenUpcs() helpers for
 *              the per-feed staged UPCs file. The reader streams
 *              line-by-line so a full ~58k-entry catalog does not
 *              need the whole file in memory as an array before
 *              dedupe.
 *
 *   NO change to:
 *     - Discontinuation algorithm — Importer::processDiscontinuations
 *       (which is what processDiscontinuationsForSeenUpcs delegates
 *       to) still owns the 80% coverage guard, hard floor of 100,
 *       and the miss-counter threshold. This fix only plumbs the
 *       accumulated seenUpcs set through.
 *     - SportsSouthClient API calls (dailyItemUpdate signature +
 *       LastItem paging preserved verbatim).
 *     - Chunk size (unchanged — still 1000 rows).
 *     - LastItem continuation cursor (still max ITEMNO across chunk).
 *     - SportsSouthAdapter, StructuredFeedAdapter,
 *       SourceAdapterInterface.
 *     - GenericImport queue extension.
 *     - ImportJob model.
 *     - Importer public APIs (run, runChunk, processRecord,
 *       processNormalizedRecord, sampleRecords, fetchAndParse,
 *       processDiscontinuationsForSeenUpcs).
 *     - The scheduled ImportFeeds task's SS branch — Phase 9's
 *       "Sports South scheduled path unchanged" contract is
 *       preserved.
 *     - AdminCP routes and pre-Phase-10 do= actions.
 *     - Queue / task extension identities.
 *     - gd_catalog + gd_import_jobs schema, raw_distributor_data.
 *
 *   ImportLog relationship: pre-Phase-10 SS full-imports created ONE
 *   ImportLog per runChunk call (because Importer::runChunk itself
 *   invokes ImportLog::startRun/complete internally). Phase 10 does
 *   NOT change that — a full SS import still produces ~58
 *   ImportLog rows, one per chunk. Only the discontinuation
 *   invocation moved.
 *
 * WHAT THIS UPGRADE DOES
 *   Small maintenance release. No schema, template, lang, or
 *   registration change is required.
 *
 *   1. Re-seeds every dev/html/*.phtml into core_theme_templates
 *      (rule #52 self-containment).
 *   2. Cache / datastore / opcache purge so the updated queue
 *      extension code takes effect on the next request.
 *
 * Rule #79: upg_10125 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10126;

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
		$version = '1.0.126';
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
						try { \IPS\Log::log( 'upg_10126 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10126' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10126 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10126' ); } catch ( \Throwable ) {}
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
