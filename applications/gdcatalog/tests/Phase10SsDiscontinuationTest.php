<?php
/**
 * @brief       Phase 10 Sports South Full-Import Discontinuation Regression Tests
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Source-level guards for the gdcatalog v1.0.126 SportsSouthImport
 * discontinuation fix.
 *
 * Guarded facts:
 *   - SportsSouthImport::run accumulates seen UPCs to an append-only
 *     jsonl file BEFORE calling Importer::runChunk (so a chunk-processing
 *     throw does not create discontinuation false-positives).
 *   - The empty-response end branch sets both $data['ss_completed_naturally']
 *     AND a matching \IPS\Data\Store flag (belt & suspenders for the
 *     "$data resets after OutOfRangeException" case).
 *   - SportsSouthImport::postComplete runs
 *     Importer::processDiscontinuationsForSeenUpcs ONLY when a natural
 *     completion is detected. Failed/cancelled/aborted paths skip it.
 *   - Discontinuation rules, thresholds, and the 80% coverage guard are
 *     unchanged — the fix only plumbs seenUpcs through the existing
 *     public helper.
 *   - preQueueData purges any leftover seen-UPCs file + completion
 *     flag from a prior aborted run, so a fresh queue starts clean.
 *   - The chunk cursor + LastItem semantics are unchanged.
 *   - GenericImport is untouched (Phase 10 targets only the SS full-
 *     queued-import path).
 *   - Importer public APIs remain intact.
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

class Phase10SsDiscontinuationTest extends TestCase
{
	protected function repoRoot(): string
	{
		return realpath( __DIR__ . '/../..' );
	}

	protected function readFile( string $rel ): string
	{
		$path = $this->repoRoot() . '/' . ltrim( $rel, '/' );
		$this->assertFileExists( $path, "expected file at {$path}" );
		return (string) file_get_contents( $path );
	}

	/* ---------- SportsSouthImport shape ---------- */

	public function testSsImportHasSeenUpcsHelpers(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/SportsSouthImport.php' );
		$this->assertStringContainsString( 'protected static function seenUpcsPath(', $src,
			'SportsSouthImport must declare a seenUpcsPath() helper for the per-feed staged UPCs file.'
		);
		$this->assertStringContainsString( 'protected static function readSeenUpcs(', $src,
			'SportsSouthImport must declare a readSeenUpcs() helper for postComplete dedupe.'
		);
		$this->assertMatchesRegularExpression(
			"/gdcatalog_ss_seen_upcs_'\\s*\\.\\s*\\\$feedId\\s*\\.\\s*'\\.jsonl'/",
			$src,
			'Staged UPCs file must live in uploads/gdcatalog_ss_seen_upcs_{feed_id}.jsonl'
		);
	}

	public function testRunAccumulatesUpcsBeforeRunChunk(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/SportsSouthImport.php' );
		$this->assertTrue(
			preg_match( '/function\s+run\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\/\*\*/s', $src, $m ) === 1,
			'Could not extract run() body.'
		);
		$body = $m[1];

		/* Accumulation must precede runChunk so failed chunks still
		 * count their UPCs as observed in the source (correct
		 * discontinuation semantics — SS returned the product, we
		 * just failed to write it). */
		$upcAppendPos = strpos( $body, 'fwrite( $fh, $upc' );
		$runChunkPos  = strpos( $body, 'Importer::runChunk(' );
		$this->assertNotFalse( $upcAppendPos, 'run() must fwrite the normalized UPC to the accumulator.' );
		$this->assertNotFalse( $runChunkPos,  'run() must still call Importer::runChunk.' );
		$this->assertLessThan( $runChunkPos, $upcAppendPos,
			'UPC accumulation must occur BEFORE runChunk so failed chunks still count.'
		);

		/* Reuse the existing normalization pipeline — no second UPC
		 * parser. */
		$this->assertStringContainsString( 'FieldMapper( $feed->field_mapping )', $body,
			'run() must use FieldMapper for UPC extraction (reuse of existing normalization).'
		);
		$this->assertStringContainsString( 'UpcValidator::normalize(', $body,
			'run() must use UpcValidator::normalize (reuse of existing normalization).'
		);
	}

	public function testEmptyResponseSetsNaturalCompletionFlag(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/SportsSouthImport.php' );
		/* Both the mutable $data flag AND the store flag must be
		 * set on the empty-response end branch — the store copy
		 * survives the $data-reset-after-OutOfRangeException case. */
		$this->assertMatchesRegularExpression(
			"/empty\\s*\\(\\s*\\\$products\\s*\\).+?\\\$data\\['ss_completed_naturally'\\]\\s*=\\s*true.+?gdcatalog_ss_completed_naturally_'.+?throw new QueueOutOfRangeException/s",
			$src,
			'Empty-response branch must set both $data[ss_completed_naturally]=true AND the core_store flag BEFORE throwing.'
		);
	}

	public function testPostCompleteRunsDiscontinuationOnlyOnNaturalCompletion(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/SportsSouthImport.php' );
		$this->assertTrue(
			preg_match( '/function\s+postComplete\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\/\*\*/s', $src, $m ) === 1,
			'Could not extract postComplete() body.'
		);
		$body = $m[1];

		/* Must gate discontinuation on natural completion. */
		$this->assertMatchesRegularExpression(
			"/if\\s*\\(\\s*\\\$naturally\\s+&&\\s+\\\$feed\\s+!==\\s+null\\s*\\).+?processDiscontinuationsForSeenUpcs\\s*\\(/s",
			$body,
			'postComplete must gate processDiscontinuationsForSeenUpcs on natural completion AND a loaded feed.'
		);

		/* Must reuse the existing public helper — no ad-hoc discontinuation. */
		$this->assertStringContainsString( 'Importer::processDiscontinuationsForSeenUpcs(', $body,
			'postComplete must reuse Importer::processDiscontinuationsForSeenUpcs (no ad-hoc discontinuation).'
		);

		/* Must consult BOTH the $data flag and the store flag. */
		$this->assertStringContainsString( "\$data['ss_completed_naturally']", $body );
		$this->assertStringContainsString( 'gdcatalog_ss_completed_naturally_', $body );
	}

	public function testPostCompleteCleansUpFilesRegardlessOfOutcome(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/SportsSouthImport.php' );
		/* The seen-UPCs file must be deleted at end of postComplete
		 * whether we ran discontinuation or not — partial UPCs from
		 * an aborted run must not survive to skew a future run's
		 * coverage guard. */
		$this->assertMatchesRegularExpression(
			"/seenUpcsPath\\s*\\(\\s*\\\$feedId\\s*\\).+?is_file\\s*\\(\\s*\\\$upcsPath\\s*\\).+?unlink\\s*\\(\\s*\\\$upcsPath\\s*\\)/s",
			$src,
			'postComplete must delete the seen-UPCs file whether or not discontinuation ran.'
		);
	}

	public function testPreQueueDataPurgesLeftoverState(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/SportsSouthImport.php' );
		$this->assertTrue(
			preg_match( '/function\s+preQueueData\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\/\*\*/s', $src, $m ) === 1,
			'Could not extract preQueueData() body.'
		);
		$body = $m[1];
		$this->assertStringContainsString( 'seenUpcsPath(', $body,
			'preQueueData must purge any leftover seen-UPCs file.'
		);
		$this->assertMatchesRegularExpression(
			"/unset\\s*\\(\\s*\\\\IPS\\\\Data\\\\Store::i\\s*\\(\\s*\\)\\s*->\\{\\s*'gdcatalog_ss_completed_naturally_'/s",
			$body,
			'preQueueData must clear any leftover completion flag from core_store.'
		);
	}

	/* ---------- Existing behaviours preserved ---------- */

	public function testSsChunkCursorSemanticsUnchanged(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/SportsSouthImport.php' );
		/* dailyItemUpdate + LastItem paging must still be the queue's
		 * chunk mechanism — Phase 10 does NOT touch the cursor. */
		$this->assertStringContainsString( "dailyItemUpdate( '1/1/1990', \$offset )", $src );
		$this->assertStringContainsString( 'ITEMNO', $src,
			'Max ITEMNO cursor must remain the chunk offset semantics.'
		);
		$this->assertMatchesRegularExpression(
			'/\$maxItemno\s*<=\s*\$offset/',
			$src,
			'The "offset stuck" defensive abort must remain.'
		);
	}

	public function testDiscontinuationSemanticsRoutedThroughExistingHelper(): void
	{
		/* processDiscontinuationsForSeenUpcs is the Phase 7 helper —
		 * confirm it still exists on Importer and its body still
		 * delegates to the existing processDiscontinuations()
		 * (which owns the 80% guard, hard floor of 100, and miss
		 * threshold). Phase 10 must NOT reimplement any of that. */
		$imp = $this->readFile( 'gdcatalog/sources/Feed/Importer.php' );
		$this->assertMatchesRegularExpression(
			'/public\s+static\s+function\s+processDiscontinuationsForSeenUpcs\s*\(\s*Distributor\s+\$\w+\s*,\s*array\s+\$\w+\s*\)\s*:\s*void.+?\$importer->processDiscontinuations\s*\(\s*\)/s',
			$imp,
			'processDiscontinuationsForSeenUpcs must still delegate to the existing processDiscontinuations()'
		);
	}

	public function testGenericImportUntouched(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/GenericImport.php' );
		/* Phase 10 must NOT touch GenericImport. Confirm it still
		 * has its Phase 8 shape. */
		$this->assertStringContainsString( 'const MAX_BATCH_RETRIES', $src,
			'GenericImport MAX_BATCH_RETRIES constant must still exist.'
		);
		$this->assertStringContainsString( 'stage_ready', $src );
		$this->assertStringContainsString( 'sportssouth', $src,
			'GenericImport must still refuse SS feeds.'
		);
	}

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

	public function testExtensionIdentitiesUnchanged(): void
	{
		$ext = json_decode( $this->readFile( 'gdcatalog/data/extensions.json' ), true );
		$this->assertSame(
			'IPS\\gdcatalog\\extensions\\core\\Queue\\SportsSouthImport',
			$ext['core']['Queue']['SportsSouthImport'] ?? null,
			'SportsSouthImport extension identity must be unchanged.'
		);
		$this->assertSame(
			'IPS\\gdcatalog\\extensions\\core\\Queue\\GenericImport',
			$ext['core']['Queue']['GenericImport'] ?? null
		);
	}
}
