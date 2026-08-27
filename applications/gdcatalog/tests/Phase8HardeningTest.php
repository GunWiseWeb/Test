<?php
/**
 * @brief       Phase 8 Job Correctness / Async Hardening Regression Tests
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Structural guards for the Phase 8 fixes (v1.0.124):
 *
 *   1. Failed batches must NOT silently advance the cursor. When
 *      Importer::runChunk throws, GenericImport::run must (a) leave
 *      offset unchanged, (b) increment batch_retry_count on the
 *      cursor, and (c) mark the job failed once retries hit the cap.
 *   2. Active-job creation is atomic: ImportJob::enqueueFor uses
 *      INSERT ... WHERE NOT EXISTS via preparedQuery so two
 *      concurrent Run Import clicks cannot both create active jobs.
 *   3. runImport is fully async: the browser request performs zero
 *      source-endpoint I/O. preQueueData no longer contains
 *      fetchAndParse; the first background run() batch handles it.
 *   4. Scheduled ImportFeeds task enqueues generic feeds via
 *      GenericImport instead of running Importer::run() synchronously.
 *   5. cancelImport clears Distributor::last_run_status AND removes
 *      the staged file.
 *   6. Retry chooses resume vs fresh strategy based on checkpoint.
 *   7. ImportLog is preserved across batch retries and resumes
 *      (import_log_id sticks to the job).
 *   8. Discontinuation never runs for failed / cancelled jobs.
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

class Phase8HardeningTest extends TestCase
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

	/* ---------- Part 1: failed batch does NOT advance ---------- */

	public function testGenericImportRunHasBoundedBatchRetry(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/GenericImport.php' );

		$this->assertMatchesRegularExpression(
			'/const\s+MAX_BATCH_RETRIES\s*=\s*\d+/',
			$src,
			'GenericImport must declare a MAX_BATCH_RETRIES constant.'
		);

		/* On runChunk throw, the run body must NOT advance offset —
		 * we verify by asserting the catch block returns $offset
		 * (not $offset + count($batch)) and touches
		 * batch_retry_count. */
		$this->assertMatchesRegularExpression(
			"/catch\\s*\\(\\s*\\\\Throwable\\s+\\\$e\\s*\\).+?batch_retry_count.+?return\\s+\\\$offset\\s*;/s",
			$src,
			'GenericImport::run catch(\\Throwable) must return the SAME offset (no advance) and bump batch_retry_count.'
		);

		$this->assertStringContainsString( "self::MAX_BATCH_RETRIES", $src,
			'GenericImport::run must compare batch_retry_count against MAX_BATCH_RETRIES.' );
		$this->assertStringContainsString( '$job->markFailed(', $src,
			'GenericImport::run must call markFailed on the job when the retry cap is reached.' );
	}

	/* ---------- Part 2: atomic enqueueFor ---------- */

	public function testImportJobEnqueueForIsAtomic(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImportJob.php' );
		$this->assertStringContainsString( 'preparedQuery(', $src,
			'ImportJob::enqueueFor must use preparedQuery for the atomic INSERT.' );
		$this->assertMatchesRegularExpression(
			"/INSERT INTO\\s+\\{\\\$prefix\\}gd_import_jobs.+?SELECT.+?WHERE NOT EXISTS\\s*\\(\\s*SELECT 1 FROM\\s+\\{\\\$prefix\\}gd_import_jobs\\s+WHERE feed_id=\\?\\s+AND status IN\\s*\\(\\s*\\?\\s*,\\s*\\?\\s*\\)/s",
			$src,
			'ImportJob::enqueueFor must use INSERT ... WHERE NOT EXISTS to guarantee at most one active job.'
		);
		$this->assertStringContainsString( '\IPS\Db::i()->affected_rows', $src,
			'ImportJob::enqueueFor must inspect affected_rows to know if the insert won the race.' );
	}

	/* ---------- Part 3: async ACP path ---------- */

	public function testRunImportPerformsNoNetwork(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertTrue(
			preg_match( '/protected\s+function\s+runImport\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\/\*\*/s', $src, $m ) === 1,
			'Could not extract runImport() body.'
		);
		$body = $m[1];
		foreach ( [
			'fetchAndParse(',
			'sampleRecords(',
			'Http\\Url::external(',
			'->get()',
			'curl_exec(',
			'ftp_connect(',
			'Importer::run(',
		] as $forbidden )
		{
			$this->assertStringNotContainsString(
				$forbidden,
				$body,
				"runImport() must not perform source I/O — found: {$forbidden}"
			);
		}
	}

	public function testPreQueueDataPerformsNoNetwork(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/GenericImport.php' );
		$this->assertTrue(
			preg_match( '/function\s+preQueueData\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\/\*\*/s', $src, $m ) === 1,
			'Could not extract preQueueData() body.'
		);
		$body = $m[1];
		foreach ( [
			'fetchAndParse(',
			'file_put_contents(',
			'Http\\Url::external(',
			'->get()',
			'curl_exec(',
			'ftp_connect(',
		] as $forbidden )
		{
			$this->assertStringNotContainsString(
				$forbidden,
				$body,
				"GenericImport::preQueueData must not fetch or stage (Phase 8) — found: {$forbidden}. IPS runs preQueueData SYNCHRONOUSLY inside Queue::queue()."
			);
		}
	}

	public function testFirstRunBatchDoesTheFetch(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/GenericImport.php' );
		$this->assertTrue(
			preg_match( '/function\s+run\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\/\*\*/s', $src, $m ) === 1,
			'Could not extract run() body.'
		);
		$body = $m[1];
		$this->assertStringContainsString( 'stage_ready', $body,
			'GenericImport::run must gate the fetch on cursor.stage_ready.' );
		$this->assertStringContainsString( 'fetchAndParse(', $body,
			'GenericImport::run must call Importer::fetchAndParse on the first tick.' );
		$this->assertStringContainsString( 'file_put_contents(', $body,
			'GenericImport::run must stage the parsed records on the first tick.' );
	}

	/* ---------- Part 4: scheduled task enqueues jobs ---------- */

	public function testImportFeedsTaskEnqueuesGenericJobs(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ImportFeeds.php' );
		$this->assertStringContainsString( 'ImportJob::activeForFeed(', $src,
			'ImportFeeds task must skip feeds that already have an active job.' );
		$this->assertStringContainsString( 'ImportJob::enqueueFor(', $src,
			'ImportFeeds task must enqueue generic feeds via ImportJob::enqueueFor.' );
		$this->assertStringContainsString( "Queue::queue( 'gdcatalog', 'GenericImport'", $src,
			'ImportFeeds task must enqueue GenericImport for generic feeds.' );

		/* SS branch preserved: sportssouth still goes through the
		 * synchronous Importer::run path (task doesn't touch SS's
		 * own queue extension). */
		$this->assertMatchesRegularExpression(
			"/authType\\s*!==\\s*'sportssouth'/s",
			$src,
			'ImportFeeds task must branch on auth_type so SS still uses Importer::run.'
		);
	}

	/* ---------- Part 5: cancel clears feed running state ---------- */

	public function testCancelImportResetsFeedAndDeletesStage(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertTrue(
			preg_match( '/protected\s+function\s+cancelImport\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\}\s*\n\s*class\s+feeds/s', $src, $m ) === 1,
			'Could not extract cancelImport() body.'
		);
		$body = $m[1];
		$this->assertStringContainsString( 'resetRunningStatus()', $body,
			'cancelImport must call Distributor::resetRunningStatus so feed no longer shows as running.' );
		$this->assertStringContainsString( 'deleteStagedFile()', $body,
			'cancelImport must call ImportJob::deleteStagedFile so uploads/gdcatalog_job_*.json does not accumulate.' );
	}

	/* ---------- Part 7: retry semantics ---------- */

	public function testRetryChoosesResumeOrFresh(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertTrue(
			preg_match( '/protected\s+function\s+retryImport\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\/\*\*/s', $src, $m ) === 1,
			'Could not extract retryImport() body.'
		);
		$body = $m[1];
		$this->assertStringContainsString( 'reopen()', $body,
			'retryImport must call ImportJob::reopen() on the resume path.' );
		$this->assertStringContainsString( 'enqueueFor(', $body,
			'retryImport must fall through to enqueueFor() on the fresh path.' );
		$this->assertStringContainsString( "'resume'", $body,
			'retryImport must expose the resume/fresh decision so the admin sees the intended mode.' );
	}

	public function testImportJobReopenIsAtomic(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImportJob.php' );
		$this->assertMatchesRegularExpression(
			"/update\\s*\\(\\s*'gd_import_jobs'.+?'id=\\?\\s+AND\\s+status=\\?'\\s*,\\s*\\(int\\)\\s+\\\$this->id\\s*,\\s*self::STATUS_FAILED/s",
			$src,
			'ImportJob::reopen must UPDATE conditionally on status=failed (atomic failed→queued).'
		);
	}

	/* ---------- Part 8: ImportLog one per logical import ---------- */

	public function testPreQueueDataReusesExistingImportLogOnResume(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/GenericImport.php' );
		$this->assertTrue(
			preg_match( '/function\s+preQueueData\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\/\*\*/s', $src, $m ) === 1
		);
		$body = $m[1];
		$this->assertStringContainsString( '$job->import_log_id', $body,
			'preQueueData must inspect the job\'s existing import_log_id.' );
		/* The resume branch must NOT call startRun when a log
		 * already exists — that would create a second log for the
		 * same logical import. */
		$this->assertMatchesRegularExpression(
			"/if\\s*\\(\\s*\\(int\\)\\s*\\(\\s*\\\$job->import_log_id\\s*\\?\\?\\s*0\\s*\\)\\s*>\\s*0\\s*\\)/s",
			$body,
			'preQueueData must guard startRun behind a check that no import_log_id exists yet.'
		);
	}

	/* ---------- Part 9: discontinuation safety ---------- */

	public function testPostCompleteSkipsDiscontinuationOnFailedOrCancelled(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/GenericImport.php' );
		$this->assertTrue(
			preg_match( '/function\s+postComplete\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\}\s*\n\s*class\s+GenericImport/s', $src, $m ) === 1
		);
		$post = $m[1];
		/* The discontinuation call must be gated on the job NOT
		 * being failed OR cancelled. */
		$this->assertMatchesRegularExpression(
			"/jobStatus\\s*!==\\s*ImportJob::STATUS_FAILED\\s+&&\\s+.*?jobStatus\\s*!==\\s*ImportJob::STATUS_CANCELLED.+?processDiscontinuationsForSeenUpcs\\s*\\(/s",
			$post,
			'postComplete must gate processDiscontinuationsForSeenUpcs on job NOT being failed/cancelled.'
		);
	}

	/* ---------- Regression on Phase 7 ---------- */

	public function testGenericImportStillRefusesSportsSouth(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/GenericImport.php' );
		$this->assertMatchesRegularExpression(
			"/'sportssouth'/",
			$src,
			'GenericImport must still refuse auth_type=sportssouth.'
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

	public function testFeedsPreservesAllActions(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		foreach ( [
			'add', 'delete', 'reorder', 'edit',
			'uploadFeed', 'runManualFeed',
			'refreshLookups', 'testConnection',
			'resetFeedStatus', 'catAttrs', 'reExtractAttributes',
			'testSource', 'runImport', 'retryImport', 'cancelImport',
		] as $action )
		{
			$this->assertMatchesRegularExpression(
				"/protected\\s+function\\s+{$action}\\s*\\(/",
				$src,
				"feeds.php must preserve action: {$action}"
			);
		}
	}

	public function testTestSourceStillNonDestructive(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertTrue(
			preg_match( '/protected\s+function\s+testSource\s*\([^)]*\)[^{]*\{(.+?)\n\s*(?:protected|public|private)\s+function\s+/s', $src, $m ) === 1
		);
		$body = $m[1];
		$this->assertStringContainsString( 'sampleRecords(', $body );
		$this->assertStringNotContainsString( "ImportJob::enqueueFor(", $body );
		$this->assertStringNotContainsString( "Queue::queue( 'gdcatalog', 'GenericImport'", $body );
	}
}
