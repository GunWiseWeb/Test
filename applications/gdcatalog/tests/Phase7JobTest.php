<?php
/**
 * @brief       Phase 7 Background Job Regression Tests
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Source-level guards for the resumable-background-import work in
 * gdcatalog v1.0.123. Full end-to-end batch execution requires an
 * IPS bootstrap + a mock DB; these tests act as guardrails on the
 * code shape a fresh checkout would install.
 *
 * Guarded facts:
 *   - Importer keeps all Phase 6 public APIs and adds
 *     fetchAndParse + processDiscontinuationsForSeenUpcs.
 *   - ImportJob exposes STATUS_* constants + activeForFeed +
 *     enqueueFor + claim.
 *   - GenericImport queue extension is registered under
 *     data/extensions.json's Queue block.
 *   - GenericImport::run body uses bounded slicing + accumulates
 *     seen UPCs on the cursor + delegates catalog work to
 *     Importer::runChunk (no product-persistence duplication).
 *   - GenericImport::preQueueData refuses auth_type='sportssouth'.
 *   - GenericImport::postComplete calls
 *     processDiscontinuationsForSeenUpcs — proving discontinuation
 *     only runs after the full job (not per batch).
 *   - feeds.php runImport now enqueues a job (Queue::queue) instead
 *     of calling Importer::run synchronously.
 *   - feeds.php exposes retryImport + cancelImport actions and the
 *     manage() capability-flag block carries job status fields.
 *   - Schema declares gd_import_jobs with the columns the queue
 *     extension reads/writes.
 *   - upg_10123/upgrade.php creates gd_import_jobs idempotently.
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

class Phase7JobTest extends TestCase
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

	/* ---------- Importer ---------- */

	public function testImporterPreservesPhase6Apis(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/Importer.php' );
		foreach ( [
			'/public\s+static\s+function\s+run\s*\(\s*Distributor\s+\$feed\s*\)\s*:\s*ImportLog/',
			'/public\s+static\s+function\s+runChunk\s*\(\s*Distributor\s+\$feed\s*,\s*array\s+\$rawRecords\s*\)\s*:\s*array/',
			'/protected\s+function\s+processRecord\s*\(\s*array\s+\$rawRecord\s*\)\s*:\s*void/',
			'/protected\s+function\s+processNormalizedRecord\s*\(\s*NormalizedRecord\s+\$\w+\s*\)\s*:\s*void/',
			'/public\s+static\s+function\s+sampleRecords\s*\(\s*Distributor\s+\$\w+\s*,\s*int\s+\$\w+\s*=\s*\d+\s*\)\s*:\s*array/',
		] as $rx )
		{
			$this->assertMatchesRegularExpression( $rx, $src, "public API missing: $rx" );
		}
	}

	public function testImporterAddsPhase7Helpers(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/Importer.php' );
		$this->assertMatchesRegularExpression(
			'/public\s+static\s+function\s+fetchAndParse\s*\(\s*Distributor\s+\$\w+\s*\)\s*:\s*array/',
			$src,
			'Importer::fetchAndParse(Distributor): array must exist.'
		);
		$this->assertMatchesRegularExpression(
			'/public\s+static\s+function\s+processDiscontinuationsForSeenUpcs\s*\(\s*Distributor\s+\$\w+\s*,\s*array\s+\$\w+\s*\)\s*:\s*void/',
			$src,
			'Importer::processDiscontinuationsForSeenUpcs(Distributor, array): void must exist.'
		);
	}

	/* ---------- ImportJob ---------- */

	public function testImportJobExposesLifecycle(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImportJob.php' );
		foreach ( [
			"public const STATUS_QUEUED    = 'queued'",
			"public const STATUS_RUNNING   = 'running'",
			"public const STATUS_COMPLETED = 'completed'",
			"public const STATUS_FAILED    = 'failed'",
			"public const STATUS_CANCELLED = 'cancelled'",
			'public static function activeForFeed(',
			'public static function enqueueFor(',
			'public function claim(',
			'public function cursor(',
			'public function saveCursor(',
			'public function markCompleted(',
			'public function markFailed(',
			'public function markCancelled(',
		] as $needle )
		{
			$this->assertStringContainsString( $needle, $src, "ImportJob missing: $needle" );
		}

		/* Rule #7 static property shape */
		$this->assertStringContainsString( "public static ?string \$databaseTable    = 'gd_import_jobs'", $src );
		$this->assertStringContainsString( "public static string  \$databaseColumnId = 'id'",             $src );
		$this->assertStringContainsString( "public static string  \$databasePrefix   = ''",              $src );
	}

	public function testImportJobClaimIsAtomic(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImportJob.php' );
		/* The claim() body must include a WHERE clause requiring
		 * status='queued' inside the UPDATE — that is what makes the
		 * queued→running transition atomic and prevents two workers
		 * from claiming the same job. */
		$this->assertMatchesRegularExpression(
			"/update\\s*\\(\\s*['\"]gd_import_jobs['\"].+?'id=\\?\\s+AND\\s+status=\\?'\\s*,\\s*\\(int\\)\\s+\\\$this->id\\s*,\\s*self::STATUS_QUEUED/s",
			$src,
			'ImportJob::claim() must UPDATE conditionally on status=queued (atomic queued→running).'
		);
	}

	/* ---------- GenericImport queue extension ---------- */

	public function testGenericImportRegisteredInExtensions(): void
	{
		$ext = json_decode( $this->readFile( 'gdcatalog/data/extensions.json' ), true );
		$this->assertIsArray( $ext );
		$this->assertArrayHasKey( 'GenericImport', $ext['core']['Queue'] ?? [],
			'GenericImport must be registered in data/extensions.json under core.Queue (rule #16).' );
		$this->assertSame(
			'IPS\\gdcatalog\\extensions\\core\\Queue\\GenericImport',
			$ext['core']['Queue']['GenericImport']
		);
	}

	public function testGenericImportRefusesSportsSouth(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/GenericImport.php' );
		/* preQueueData must abort when auth_type is 'sportssouth' so
		 * SS never enters two queues at once. */
		$this->assertMatchesRegularExpression(
			"/authType\\s*===\\s*'sportssouth'/s",
			$src,
			'GenericImport::preQueueData must refuse auth_type=sportssouth.'
		);
	}

	public function testGenericImportUsesBoundedBatches(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/GenericImport.php' );
		$this->assertMatchesRegularExpression(
			'/const\s+BATCH_SIZE\s*=\s*\d+/',
			$src,
			'GenericImport must declare a BATCH_SIZE constant.'
		);
		$this->assertStringContainsString( 'array_slice(', $src,
			'GenericImport::run must slice the staged array to a bounded batch.'
		);
	}

	public function testGenericImportDelegatesToImporterRunChunk(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/GenericImport.php' );
		$this->assertStringContainsString( 'Importer::runChunk(', $src,
			'GenericImport::run must delegate catalog processing to Importer::runChunk (no duplicate product-persistence code).'
		);
		/* And explicitly NOT duplicate the product-write pipeline. */
		foreach ( [ 'createProduct(', 'updateProduct(', 'processRecord(', 'processNormalizedRecord(' ] as $forbidden )
		{
			$this->assertStringNotContainsString( $forbidden, $src,
				"GenericImport must not call {$forbidden} directly — it must go through Importer::runChunk." );
		}
	}

	public function testGenericImportDiscontinuationOnlyInPostComplete(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/GenericImport.php' );
		/* Extract postComplete body. */
		$this->assertTrue(
			preg_match( '/function\s+postComplete\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\}\s*\n\s*class\s+GenericImport/s', $src, $m ) === 1,
			'Could not extract GenericImport::postComplete body.'
		);
		$post = $m[1];
		$this->assertStringContainsString( 'processDiscontinuationsForSeenUpcs(', $post,
			'GenericImport::postComplete must call Importer::processDiscontinuationsForSeenUpcs.'
		);

		/* And the run() body must NOT call it — proves discontinuation
		 * only runs after the full job, never per batch. */
		$this->assertTrue(
			preg_match( '/function\s+run\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\/\*\*/s', $src, $rm ) === 1,
			'Could not extract GenericImport::run body.'
		);
		$this->assertStringNotContainsString( 'processDiscontinuationsForSeenUpcs(', $rm[1],
			'GenericImport::run must NOT run discontinuation — that would fire per batch.'
		);
	}

	/* ---------- feeds.php controller ---------- */

	public function testFeedsRunImportEnqueuesJob(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertTrue(
			preg_match( '/protected\s+function\s+runImport\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\/\*\*/s', $src, $m ) === 1,
			'Could not extract runImport() body.'
		);
		$body = $m[1];
		$this->assertStringContainsString( 'ImportJob::enqueueFor(', $body,
			'runImport must enqueue via ImportJob::enqueueFor().' );
		$this->assertStringContainsString( "Queue::queue( 'gdcatalog', 'GenericImport'", $body,
			'runImport must call IPS Task Queue::queue for GenericImport.' );
		$this->assertStringNotContainsString( 'Importer::run(', $body,
			'runImport must not call the synchronous Importer::run() — that is what Phase 7 replaces.' );
	}

	public function testFeedsAddsRetryAndCancelActions(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertMatchesRegularExpression( '/protected\s+function\s+retryImport\s*\(/', $src );
		$this->assertMatchesRegularExpression( '/protected\s+function\s+cancelImport\s*\(/', $src );
	}

	public function testManageComputesJobStatusFlags(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		foreach ( [
			'job_status', 'job_active', 'job_failed',
			'job_progress', 'job_last_error', 'job_updated_at',
			'retry_import_url', 'cancel_import_url',
		] as $key )
		{
			$this->assertStringContainsString( "'{$key}'", $src,
				"manage() must expose {$key} to the template." );
		}
	}

	public function testFeedsPreservesEveryPreviousAction(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		foreach ( [
			'add','delete','reorder','edit',
			'uploadFeed','runManualFeed',
			'refreshLookups','testConnection',
			'resetFeedStatus','catAttrs','reExtractAttributes',
			'testSource','runImport',
		] as $action )
		{
			$this->assertMatchesRegularExpression(
				"/protected\\s+function\\s+{$action}\\s*\\(/",
				$src,
				"feeds.php must preserve pre-Phase-7 action: {$action}"
			);
		}
	}

	/* ---------- Schema ---------- */

	public function testSchemaHasImportJobsTable(): void
	{
		$s = json_decode( $this->readFile( 'gdcatalog/data/schema.json' ), true );
		$this->assertIsArray( $s );
		$this->assertArrayHasKey( 'gd_import_jobs', $s );
		$cols = $s['gd_import_jobs']['columns'] ?? [];
		foreach ( [ 'id', 'feed_id', 'status', 'cursor_data', 'import_log_id', 'started_at', 'updated_at', 'completed_at', 'last_error' ] as $c )
		{
			$this->assertArrayHasKey( $c, $cols, "gd_import_jobs.$c missing" );
		}
		$this->assertSame( 'MEDIUMTEXT', $cols['cursor_data']['type'] );
	}

	public function testUpgradeCreatesImportJobsIdempotently(): void
	{
		$src = $this->readFile( 'gdcatalog/setup/upg_10123/upgrade.php' );
		$this->assertStringContainsString( "checkForTable( 'gd_import_jobs' )", $src,
			'upg_10123 must guard createTable behind checkForTable to remain idempotent.'
		);
		$this->assertStringContainsString( "'name'    => 'gd_import_jobs'", $src );
	}

	/* ---------- Test Source unchanged (guardrail) ---------- */

	public function testTestSourceStillNonDestructive(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertTrue(
			preg_match( '/protected\s+function\s+testSource\s*\([^)]*\)[^{]*\{(.+?)\n\s*(?:protected|public|private)\s+function\s+/s', $src, $m ) === 1
		);
		$body = $m[1];
		/* Test Source must still route through sampleRecords + adapter,
		 * NOT through the new job system. */
		$this->assertStringContainsString( 'sampleRecords(', $body,
			'Test Source must still use sampleRecords (small synchronous preview).'
		);
		$this->assertStringNotContainsString( "ImportJob::enqueueFor(", $body,
			'Test Source must not route through the background job system.'
		);
		$this->assertStringNotContainsString( "Queue::queue( 'gdcatalog', 'GenericImport'", $body,
			'Test Source must not enqueue GenericImport.'
		);
	}
}
