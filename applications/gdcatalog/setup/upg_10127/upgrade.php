<?php
/**
 * @brief  GD Master Catalog — upgrade 1.0.127 (Phase 11: image-dimension cache + deferred highest_res).
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.127
 *   Phase 11 — retires the last synchronous remote-HTTP call from
 *   the import path. ConflictResolver's highest_res rule now reads
 *   a persistent per-URL dimension cache; missing dimensions are
 *   deferred to a background worker (FetchImageDimensions queue
 *   extension). Deferred comparisons live on gd_feed_conflicts
 *   with resolution_note='awaiting_image_dimensions'; a successful
 *   dimension probe triggers ConflictResolver::reevaluateForUrl,
 *   which resolves the pending row with the same tie semantics
 *   the old sync path used and reindexes the product through the
 *   existing gd_reindex_queue path (no OpenSearch HTTP).
 *
 *   Code changes:
 *     - NEW  sources/Feed/ImageDimensionCache.php
 *            — persistent cache API: hashOf / pixelsFromHint /
 *              isValidHttpUrl / lookup / pixelsFor / enqueue /
 *              store / markFailed. pixelsFor is the ONLY import-
 *              time entry point; it hits URL-hint parse first
 *              (no I/O), then the local DB cache. Never performs
 *              HTTP.
 *            — freshness policy: STATUS_READY < 30 days = fresh;
 *              STATUS_FAILED < 7 days = do not retry;
 *              STATUS_PENDING < 1 hour = worker already in flight.
 *            — SSRF hygiene: only http/https allowed; localhost +
 *              RFC 1918 private ranges rejected.
 *     - NEW  extensions/core/Queue/FetchImageDimensions.php
 *            — one queue row = one product-image URL. Fetches
 *              with a 10-second timeout, runs getimagesize on the
 *              response body, stores width/height via
 *              ImageDimensionCache::store OR markFailed on any
 *              failure. On success, kicks off
 *              ConflictResolver::reevaluateForUrl so any pending
 *              image conflicts referencing this URL are resolved
 *              immediately.
 *     - MOD  sources/Feed/ConflictResolver.php
 *            — resolveHighestRes now uses
 *              ImageDimensionCache::pixelsFor (cache-only) for
 *              both current + incoming URLs. When both are known,
 *              runs the EXACT pre-Phase-11 comparison + tie
 *              semantics (only incoming > current swaps). When
 *              either is unknown, enqueues the missing lookups
 *              and writes a deferred gd_feed_conflicts row via
 *              writeDeferredImageConflict — the decision is
 *              preserved for later re-eval; no heuristic winner
 *              is picked, no image is silently discarded.
 *            — getImageResolution retires its HTTP fallback. Now
 *              returns ONLY the URL-hint parse (0 when absent) —
 *              kept for API compatibility with any external
 *              caller; the new lookup path lives entirely on
 *              ImageDimensionCache::pixelsFor.
 *            — NEW public static reevaluateForUrl(string $url):
 *              int. Queries gd_feed_conflicts for pending
 *              highest_res rows with
 *              resolution_note='awaiting_image_dimensions' that
 *              reference $url, and for each one runs the same
 *              comparison. Applies the winner to Product, logs
 *              through ConflictLog, and queues reindex through
 *              gd_reindex_queue. Rows still missing dimensions
 *              stay pending for a later re-eval.
 *     - MOD  data/schema.json
 *            — NEW table gd_image_dimensions (url_hash CHAR(64) PK,
 *              url TEXT, width INT UNSIGNED NULL, height INT
 *              UNSIGNED NULL, status VARCHAR(10) DEFAULT 'pending',
 *              checked_at INT UNSIGNED NULL, last_error TEXT NULL;
 *              indexed by status and checked_at).
 *     - MOD  data/extensions.json
 *            — registered FetchImageDimensions under core.Queue.
 *
 * PRESERVED UNCHANGED:
 *   - highest_res rule (choose the greater pixel resolution; tie
 *     keeps current). Only the timing and mechanics changed.
 *   - Every other ConflictResolver rule (highest_priority, admin
 *     override, locked, compliance, etc.).
 *   - adapters (SourceAdapterInterface, SportsSouthAdapter,
 *     StructuredFeedAdapter), FieldMapper, CategoryMapper,
 *     SportsSouthClient, Importer public APIs.
 *   - SportsSouthImport queue extension body (Phase 10 semantics
 *     intact).
 *   - GenericImport queue extension body (Phase 8+9 semantics
 *     intact — Phase 11 does NOT touch its run/postComplete).
 *   - ImportJob model, ReconcileImportJobs task.
 *   - AdminCP routes + all pre-Phase-11 do= actions.
 *   - raw_distributor_data storage format.
 *
 * WHAT THIS UPGRADE DOES
 *   1. Creates gd_image_dimensions if absent (idempotent —
 *      checkForTable + createTable, no destructive drops).
 *   2. Re-seeds every dev/html/*.phtml into core_theme_templates
 *      (rule #52 self-containment — no template body change this
 *      version but keeps the upg dir self-contained per rule #79).
 *   3. Rebuilds the extensions datastore so the new
 *      FetchImageDimensions extension registration is picked up.
 *   4. Cache / datastore / opcache purge so the updated
 *      ConflictResolver code takes effect on the next request.
 *
 * Rule #79: upg_10126 removed, exactly one upg dir per app.
 */

namespace IPS\gdcatalog\setup\upg_10127;

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
		$version = '1.0.127';
		$root    = \IPS\ROOT_PATH . '/applications/' . $app . '/dev/html';

		/* -------- Schema: gd_image_dimensions (idempotent) -------- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_image_dimensions' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_image_dimensions',
					'columns' => [
						[ 'name' => 'url_hash',   'type' => 'CHAR',    'length' => 64,  'allow_null' => false ],
						[ 'name' => 'url',        'type' => 'TEXT',    'length' => 0,   'allow_null' => false ],
						[ 'name' => 'width',      'type' => 'INT',     'length' => 10,  'allow_null' => true,  'default' => null, 'unsigned' => true ],
						[ 'name' => 'height',     'type' => 'INT',     'length' => 10,  'allow_null' => true,  'default' => null, 'unsigned' => true ],
						[ 'name' => 'status',     'type' => 'VARCHAR', 'length' => 10,  'allow_null' => false, 'default' => 'pending' ],
						[ 'name' => 'checked_at', 'type' => 'INT',     'length' => 10,  'allow_null' => true,  'default' => null, 'unsigned' => true ],
						[ 'name' => 'last_error', 'type' => 'TEXT',    'length' => 0,   'allow_null' => true,  'default' => null ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',     'columns' => [ 'url_hash' ] ],
						[ 'type' => 'key',     'name' => 'idx_status',  'columns' => [ 'status' ] ],
						[ 'type' => 'key',     'name' => 'idx_checked', 'columns' => [ 'checked_at' ] ],
					],
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10127 create gd_image_dimensions: ' . $e->getMessage(), 'gdcatalog_upg_10127' ); } catch ( \Throwable ) {}
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
						try { \IPS\Log::log( 'upg_10127 tpl (' . $name . '): ' . $e->getMessage(), 'gdcatalog_upg_10127' ); } catch ( \Throwable ) {}
					}
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10127 tpl loop: ' . $e->getMessage(), 'gdcatalog_upg_10127' ); } catch ( \Throwable ) {}
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
