<?php
/**
 * @brief       Phase 9 Import Job Reconciliation Regression Tests
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Source-level guards for gdcatalog v1.0.125 (Phase 9). All checks
 * are structural — full DB-driven reconciliation runs would need an
 * IPS bootstrap. These tests are the guardrails that catch any
 * regression in the code shape a fresh checkout would install.
 *
 * Guarded facts:
 *   - ImportJob exposes STALE_THRESHOLD_SECONDS, isStale, isResumable,
 *     reconcile, plus internal finalizeLog helpers.
 *   - reconcile()'s decision tree: healthy active → return false;
 *     stale active → markFailed + descend into failed branch;
 *     completed → mark feed/log completed + delete stage;
 *     failed → keep stage iff resumable; cancelled → resetRunning
 *     + fail("Cancelled by administrator …") + delete stage.
 *   - cancelImport now finalises the ImportLog through reconcile
 *     rather than leaving it untouched.
 *   - resetFeedStatus reconciles the full source state, refuses to
 *     kill a healthy active job, and preserves the do=resetFeedStatus
 *     route.
 *   - ReconcileImportJobs task exists, is registered in
 *     data/tasks.json (PT1H), and performs zero source I/O.
 *   - Task cleans orphan staged files while preserving resumable ones.
 *   - All Phase 3–8 regressions remain.
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

class Phase9ReconcileTest extends TestCase
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

	/* ---------- ImportJob helpers ---------- */

	public function testImportJobExposesReconcileHelpers(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImportJob.php' );
		$this->assertStringContainsString(
			'public const STALE_THRESHOLD_SECONDS = 3600',
			$src,
			'STALE_THRESHOLD_SECONDS must be 3600 (one hour — conservative vs healthy batch cadence).'
		);
		foreach ( [
			'public function isStale():',
			'public function isResumable():',
			'public function reconcile():',
			'protected function finalizeLogAsCompleted(',
			'protected function finalizeLogAsFailed(',
		] as $needle )
		{
			$this->assertStringContainsString( $needle, $src, "ImportJob missing: $needle" );
		}
	}

	public function testIsStaleGuardsOnActiveStatus(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImportJob.php' );
		/* isStale must only return true for active statuses. */
		$this->assertMatchesRegularExpression(
			"/function\\s+isStale\\s*\\(\\s*\\)\\s*:\\s*bool.+?in_array\\s*\\(\\s*\\(string\\)\\s*\\\$this->status\\s*,\\s*self::ACTIVE_STATUSES\\s*,\\s*true\\s*\\)/s",
			$src,
			'isStale must return false when the status is not in ACTIVE_STATUSES.'
		);
	}

	public function testIsResumableRequiresValidCheckpoint(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImportJob.php' );
		$this->assertMatchesRegularExpression(
			"/function\\s+isResumable\\s*\\(\\s*\\)\\s*:\\s*bool.+?STATUS_FAILED.+?stage_ready.+?is_file\\s*\\(\\s*\\\$path\\s*\\).+?offset.+?total/s",
			$src,
			'isResumable must require status=failed AND stage_ready AND file exists AND offset in range.'
		);
	}

	public function testReconcileHealthyActiveJobReturnsFalse(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImportJob.php' );
		/* Healthy active branch returns false without touching anything. */
		$this->assertMatchesRegularExpression(
			"/function\\s+reconcile.+?in_array\\s*\\(\\s*\\\$status\\s*,\\s*self::ACTIVE_STATUSES.+?if\\s*\\(\\s*!\\\$this->isStale\\s*\\(\\s*\\)\\s*\\)\\s*\\{\\s*return\\s+false\\s*;/s",
			$src,
			'reconcile() must return false on a healthy active job.'
		);
	}

	public function testReconcileCancelledFinalisesLog(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImportJob.php' );
		$this->assertMatchesRegularExpression(
			"/STATUS_CANCELLED.+?finalizeLogAsFailed.+?'Cancelled by administrator after %d records processed\\.'/s",
			$src,
			'reconcile() cancelled branch must call finalizeLogAsFailed with the "Cancelled by administrator" message.'
		);
	}

	public function testReconcileFailedKeepsResumableStage(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImportJob.php' );
		$this->assertMatchesRegularExpression(
			"/STATUS_FAILED.+?if\\s*\\(\\s*!\\\$this->isResumable\\s*\\(\\s*\\)\\s*\\)\\s*\\{\\s*\\\$this->deleteStagedFile\\s*\\(\\s*\\)\\s*;/s",
			$src,
			'reconcile() failed branch must skip deleteStagedFile when isResumable() is true.'
		);
	}

	/* ---------- feeds.php ---------- */

	public function testCancelImportReconcilesInsteadOfBespokeCleanup(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertTrue(
			preg_match( '/protected\s+function\s+cancelImport\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*\}\s*\n\s*class\s+feeds/s', $src, $m ) === 1
		);
		$body = $m[1];
		$this->assertStringContainsString( '$job->reconcile()', $body,
			'cancelImport must delegate to ImportJob::reconcile so the ImportLog reaches a terminal state.'
		);
		$this->assertStringContainsString( '$job->markCancelled()', $body,
			'cancelImport must still call markCancelled first so status flips before reconcile runs.'
		);
	}

	public function testResetFeedStatusReconcilesAndRefusesHealthy(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertTrue(
			preg_match( '/protected\s+function\s+resetFeedStatus\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*protected\s+function\s+catAttrs/s', $src, $m ) === 1,
			'Could not extract resetFeedStatus() body.'
		);
		$body = $m[1];

		$this->assertStringContainsString( 'activeForFeed(', $body,
			'resetFeedStatus must check for an active job before touching state.'
		);
		$this->assertStringContainsString( '!$active->isStale()', $body,
			'resetFeedStatus must refuse to touch a healthy active job.'
		);
		$this->assertStringContainsString( '"Cancel Import"', $body,
			'resetFeedStatus must point the admin at Cancel Import when refusing on a healthy job.'
		);
		$this->assertStringContainsString( '$active->reconcile()', $body,
			'resetFeedStatus must reconcile a stale active job.'
		);
	}

	public function testResetFeedStatusIsCsrfChecked(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertTrue(
			preg_match( '/protected\s+function\s+resetFeedStatus\s*\([^)]*\)[^{]*\{(.+?)\n\s*\}\s*\n\s*protected\s+function\s+catAttrs/s', $src, $m ) === 1
		);
		$body = $m[1];
		$this->assertStringContainsString( 'csrfCheck()', $body,
			'resetFeedStatus must retain csrfCheck (rule #48 pattern).'
		);
	}

	/* ---------- ReconcileImportJobs task ---------- */

	public function testReconcileTaskExists(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ReconcileImportJobs.php' );
		$this->assertStringContainsString( 'class _ReconcileImportJobs extends \IPS\Task', $src,
			'ReconcileImportJobs must extend \IPS\Task with dual-class shape.'
		);
		$this->assertStringContainsString( 'class ReconcileImportJobs extends _ReconcileImportJobs {}', $src,
			'ReconcileImportJobs must have the dual-class wrapper alias.'
		);
	}

	public function testReconcileTaskIsRegisteredHourly(): void
	{
		$tasks = json_decode( $this->readFile( 'gdcatalog/data/tasks.json' ), true );
		$this->assertIsArray( $tasks );
		$this->assertArrayHasKey( 'ReconcileImportJobs', $tasks,
			'ReconcileImportJobs must be registered in data/tasks.json.'
		);
		$this->assertSame( 'PT1H', $tasks['ReconcileImportJobs'],
			'ReconcileImportJobs schedule must be PT1H (matches STALE_THRESHOLD_SECONDS cadence).'
		);
	}

	public function testReconcileTaskDoesNoNetworkNorProductWrites(): void
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
		] as $forbidden )
		{
			$this->assertStringNotContainsString(
				$forbidden,
				$src,
				"ReconcileImportJobs task must not perform {$forbidden} — it is a maintenance-only task."
			);
		}

		/* And must actually do its two jobs. */
		$this->assertStringContainsString( 'reconcile()',           $src, 'Task must call ImportJob::reconcile.' );
		$this->assertStringContainsString( 'gdcatalog_job_*.json',  $src, 'Task must glob orphan staged files by pattern.' );
		$this->assertStringContainsString( 'STALE_THRESHOLD_SECONDS', $src, 'Task must use the model-level stale threshold.' );
	}

	public function testReconcileTaskKeepsResumableStagedFiles(): void
	{
		$src = $this->readFile( 'gdcatalog/tasks/ReconcileImportJobs.php' );
		/* The task must ask isResumable() before unlinking a failed
		 * job's staged file — this is the Phase 8 resume guarantee. */
		$this->assertMatchesRegularExpression(
			"/STATUS_FAILED\\s+&&\\s+\\\$job->isResumable\\s*\\(\\s*\\)\\s*\\)\\s*\\{\\s*continue\\s*;/s",
			$src,
			'Task must `continue` (skip deletion) for failed-and-resumable staged files.'
		);
	}

	/* ---------- Discontinuation still gated ---------- */

	public function testCancelReconcilePathDoesNotRunDiscontinuation(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImportJob.php' );
		/* reconcile() itself must never invoke discontinuation
		 * (that's postComplete's job only, and only for completed
		 * jobs — Phase 8). */
		$this->assertStringNotContainsString(
			'processDiscontinuationsForSeenUpcs(',
			$src,
			'ImportJob::reconcile must NOT call discontinuation — Phase 8 rule preserved.'
		);
	}

	/* ---------- Regression: Phase 3–8 hallmarks ---------- */

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
}
