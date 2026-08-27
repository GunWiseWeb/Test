<?php
/**
 * @brief       Phase 12 Sports South Accumulator Cleanup Regression Tests
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Structural guards for gdcatalog v1.0.128 (Phase 12). Full DB /
 * filesystem-driven tests would need an IPS bootstrap; these
 * assertions verify the code shape a fresh checkout would install.
 *
 * Guarded facts:
 *   - ReconcileImportJobs task extended with a Sports South
 *     accumulator cleanup pass — walks
 *     uploads/gdcatalog_ss_seen_upcs_*.jsonl with a conservative
 *     ownership decision tree.
 *   - Malformed filenames are ignored, never deleted.
 *   - Feed-missing / auth_type-not-SS branches delete + clear the
 *     completion store flag.
 *   - Distributor::isRunning() short-circuits deletion (active queue
 *     owns the file).
 *   - Completion flag + recent file → keep (postComplete may be
 *     about to consume).
 *   - Age threshold reuses ImportJob::STALE_THRESHOLD_SECONDS for
 *     consistency with the generic-job cleanup.
 *   - Task remains network-free (no HTTP / FTP / OpenSearch / image
 *     probes).
 *   - Sports South ImportLog consolidation is DEFERRED — this
 *     phase does NOT touch runChunk / ImportLog::startRun call
 *     sites. Asserted structurally.
 *   - Phase 11 image cache path is untouched — key assertions
 *     re-run.
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

class Phase12CleanupTest extends TestCase
{
	protected function repoRoot(): string { return realpath( __DIR__ . '/../..' ); }
	protected function readFile( string $rel ): string
	{
		$path = $this->repoRoot() . '/' . ltrim( $rel, '/' );
		$this->assertFileExists( $path, "expected file at {$path}" );
		return (string) file_get_contents( $path );
	}

	/* ---------- ReconcileImportJobs extension ---------- */

	public function testTaskWalksSsAccumulatorPattern(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ReconcileImportJobs.php' );
		$this->assertStringContainsString( 'gdcatalog_ss_seen_upcs_*.jsonl', $src,
			'Task must glob uploads/gdcatalog_ss_seen_upcs_*.jsonl for SS accumulator cleanup.'
		);
		$this->assertMatchesRegularExpression(
			'#/\^gdcatalog_ss_seen_upcs_\(\\\\d\+\)\\\\\.jsonl\$/#',
			$src,
			'Filename regex must anchor + capture feed_id + reject malformed names.'
		);
	}

	public function testMalformedNameIsSkippedNotDeleted(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ReconcileImportJobs.php' );
		/* On preg_match miss we must `continue` (skip), not `unlink`. */
		$this->assertMatchesRegularExpression(
			"/gdcatalog_ss_seen_upcs_.+?preg_match.+?continue\\s*;/s",
			$src,
			'Malformed accumulator filenames must be skipped (continue), not deleted.'
		);
	}

	public function testFeedMissingDeletesFileAndClearsFlag(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ReconcileImportJobs.php' );
		/* The feed-missing branch must @unlink the file AND unset the
		 * lingering core_store completion flag. */
		$this->assertMatchesRegularExpression(
			"/if\\s*\\(\\s*\\\$feed\\s*===\\s*null\\s*\\).+?@unlink\\s*\\(\\s*\\\$path\\s*\\).+?ssStagesFreed\\+\\+.+?unset\\s*\\(\\s*\\\\IPS\\\\Data\\\\Store::i.+?gdcatalog_ss_completed_naturally_/s",
			$src,
			'Feed-missing branch must unlink the accumulator AND clear the store flag.'
		);
	}

	public function testAuthTypeChangedDeletesFile(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ReconcileImportJobs.php' );
		$this->assertMatchesRegularExpression(
			"/auth_type.+?!==\\s*'sportssouth'.+?@unlink\\s*\\(\\s*\\\$path\\s*\\).+?ssStagesFreed\\+\\+/s",
			$src,
			'When feed auth_type has changed away from sportssouth, the file must be deleted.'
		);
	}

	public function testIsRunningShortCircuitsDeletion(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ReconcileImportJobs.php' );
		$this->assertMatchesRegularExpression(
			"/\\\$feed->isRunning\\s*\\(\\s*\\)\\s*\\)\\s*\\{\\s*continue\\s*;/s",
			$src,
			'Distributor::isRunning must short-circuit — active queue owns the file.'
		);
	}

	public function testCompletionFlagPlusRecentPreservesFile(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ReconcileImportJobs.php' );
		/* if flagPresent && !isStale → continue (keep). */
		$this->assertMatchesRegularExpression(
			"/\\\$flagPresent\\s*&&\\s*!\\\$isStale\\s*\\)\\s*\\{\\s*continue\\s*;/s",
			$src,
			'Completion flag + recent file → keep (postComplete may be about to consume).'
		);
	}

	public function testStaleThresholdUsesModelConstant(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ReconcileImportJobs.php' );
		$this->assertStringContainsString( 'ImportJob::STALE_THRESHOLD_SECONDS', $src,
			'SS cleanup must reuse ImportJob::STALE_THRESHOLD_SECONDS for consistency.'
		);
	}

	public function testTaskPerformsNoNetworkNorProductWrites(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ReconcileImportJobs.php' );
		foreach ( [
			'fetchAndParse(',
			'sampleRecords(',
			'Http\\Url::external(',
			'->get()',
			'curl_exec(',
			'ftp_connect(',
			'Importer::run(',
			'Importer::runChunk(',
			'processNormalizedRecord(',
			'processRecord(',
			'ConflictLog::record(',
			'ConflictResolver::',
			'processDiscontinuations(',
			'OpenSearchIndexer::',
			'queueReindex(',
			'ImageDimensionCache::store(',
			'ImageDimensionCache::markFailed(',
			'FetchImageDimensions',
		] as $forbidden )
		{
			$this->assertStringNotContainsString(
				$forbidden,
				$src,
				"Task must not perform {$forbidden} — cleanup is local DB + filesystem only."
			);
		}
	}

	public function testCleanupCountsReported(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ReconcileImportJobs.php' );
		$this->assertStringContainsString( 'ssStagesFreed', $src,
			'Task must track a separate counter for cleaned SS accumulator files.'
		);
		$this->assertStringContainsString( 'abandoned SS accumulator file(s)', $src,
			'Task must include the SS cleanup count in its summary log line.'
		);
	}

	/* ---------- ImportLog audit — CONSOLIDATION DEFERRED ---------- */

	public function testSsChunkLogSitesUntouched(): void
	{
		/* Phase 12 explicitly DEFERS SS ImportLog consolidation.
		 * Verify the two log-creation sites the audit found remain
		 * exactly where they were: Importer::execute (sync run) and
		 * Importer::runChunk (per-chunk from the SS queue). */
		$src = $this->readFile( 'gdcatalog/sources/Feed/Importer.php' );
		$this->assertStringContainsString( 'ImportLog::startRun( (int) $this->feed->id, $this->feed->distributor )', $src,
			'Importer::execute must still create the sync ImportLog exactly as before.'
		);
		$this->assertStringContainsString( 'ImportLog::startRun( (int) $feed->id, $feed->distributor )', $src,
			'Importer::runChunk must still create the per-chunk ImportLog exactly as before (SS queue expects it).'
		);
	}

	public function testSportsSouthImportQueueBodyUntouched(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/SportsSouthImport.php' );
		/* Phase 10 semantics intact — accumulator + natural-completion
		 * flag + one-per-chunk ImportLog via runChunk. */
		$this->assertStringContainsString( 'ss_completed_naturally', $src,
			'SportsSouthImport Phase 10 natural-completion flag preserved.'
		);
		$this->assertStringContainsString( 'processDiscontinuationsForSeenUpcs(', $src,
			'SportsSouthImport Phase 10 discontinuation gate preserved.'
		);
		/* And importantly the SS queue does NOT call ImportLog::startRun
		 * itself — it relies on Importer::runChunk to create one per
		 * chunk. That's the pre-Phase-12 design we're deliberately
		 * leaving in place. */
		$this->assertStringNotContainsString( 'ImportLog::startRun', $src,
			'Phase 12 must NOT teach SportsSouthImport to create its own log.'
		);
	}

	/* ---------- Phase 11 regression ---------- */

	public function testPhase11ImageCacheStillWired(): void
	{
		/* Cache still exists + no HTTP in ConflictResolver. */
		$cache = $this->readFile( 'gdcatalog/sources/Feed/ImageDimensionCache.php' );
		$this->assertStringContainsString( 'public static function pixelsFor(', $cache );

		$cr = $this->readFile( 'gdcatalog/sources/Feed/ConflictResolver.php' );
		$this->assertTrue(
			preg_match( '/protected\s+function\s+resolveHighestRes\s*\([^)]*\)[^{]*\{(.+?)\n\s*(?:protected|public|private)\s+function\s+/s', $cr, $m ) === 1
		);
		$body = $m[1];
		foreach ( [ 'Http\\Url::external(', 'curl_exec(', 'getimagesize(' ] as $forbidden )
		{
			$this->assertStringNotContainsString( $forbidden, $body,
				"Phase 11 regression: resolveHighestRes must remain HTTP-free — found {$forbidden}." );
		}
		$this->assertStringContainsString( 'ImageDimensionCache::pixelsFor(', $body );
	}

	public function testFetchImageDimensionsWorkerStillRegistered(): void
	{
		$ext = json_decode( $this->readFile( 'gdcatalog/data/extensions.json' ), true );
		$this->assertSame(
			'IPS\\gdcatalog\\extensions\\core\\Queue\\FetchImageDimensions',
			$ext['core']['Queue']['FetchImageDimensions'] ?? null
		);
	}

	/* ---------- Importer public APIs intact ---------- */

	public function testImporterPublicApisIntact(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/Importer.php' );
		foreach ( [
			'/public\s+static\s+function\s+run\s*\(\s*Distributor\s+\$feed\s*\)\s*:\s*ImportLog/',
			'/public\s+static\s+function\s+runChunk\s*\(\s*Distributor\s+\$feed\s*,\s*array\s+\$rawRecords\s*\)\s*:\s*array/',
			'/protected\s+function\s+processRecord\s*\(\s*array\s+\$rawRecord\s*\)\s*:\s*void/',
			'/protected\s+function\s+processNormalizedRecord\s*\(\s*NormalizedRecord\s+\$\w+\s*\)\s*:\s*void/',
			'/public\s+static\s+function\s+sampleRecords\s*\(\s*Distributor\s+\$\w+\s*,\s*int\s+\$\w+\s*=\s*\d+\s*\)\s*:\s*array/',
			'/public\s+static\s+function\s+fetchAndParse\s*\(\s*Distributor\s+\$\w+\s*\)\s*:\s*array/',
			'/public\s+static\s+function\s+processDiscontinuationsForSeenUpcs\s*\(\s*Distributor\s+\$\w+\s*,\s*array\s+\$\w+\s*\)\s*:\s*void/',
		] as $rx )
		{
			$this->assertMatchesRegularExpression( $rx, $src, "public API missing: $rx" );
		}
	}
}
