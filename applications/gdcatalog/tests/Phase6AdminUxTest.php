<?php
/**
 * @brief       Phase 6 AdminCP UX Regression Tests
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Source-level regression checks for the AdminCP source-management UX
 * shipped in v1.0.122 (Phase 6). Full clickthrough tests would need an
 * IPS bootstrap + DB fixture; these tests act as guards on the code
 * shape a fresh checkout would install:
 *
 *   1. Importer::sampleRecords is public static, takes Distributor + int.
 *   2. feedList template gates SS-only + generic-only buttons on the
 *      capability flags manage() computes.
 *   3. feeds.php exposes the new do=testSource and do=runImport actions.
 *   4. testSource never writes: no Product/Importer::run/ImportLog
 *      constructions inside its body.
 *   5. testSourcePreview template exists in dev/html/ AND ends up in
 *      the fresh-install seed loop that install.php's dev/html scanner
 *      picks up.
 *   6. No sync network call is added to the source-list render path.
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

class Phase6AdminUxTest extends TestCase
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

	public function testImporterExposesSampleRecords(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/Importer.php' );
		$this->assertMatchesRegularExpression(
			'/public\s+static\s+function\s+sampleRecords\s*\(\s*Distributor\s+\$\w+\s*,\s*int\s+\$\w+\s*=\s*\d+\s*\)\s*:\s*array/',
			$src,
			'Importer::sampleRecords(Distributor, int) must exist as public static → array.'
		);
	}

	public function testImporterPublicApisStillIntact(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/Importer.php' );
		$this->assertMatchesRegularExpression(
			'/public\s+static\s+function\s+run\s*\(\s*Distributor\s+\$feed\s*\)\s*:\s*ImportLog/',
			$src,
			'Importer::run(Distributor): ImportLog signature must not change.'
		);
		$this->assertMatchesRegularExpression(
			'/public\s+static\s+function\s+runChunk\s*\(\s*Distributor\s+\$feed\s*,\s*array\s+\$rawRecords\s*\)\s*:\s*array/',
			$src,
			'Importer::runChunk(Distributor, array): array signature must not change.'
		);
		$this->assertMatchesRegularExpression(
			'/protected\s+function\s+processNormalizedRecord\s*\(\s*NormalizedRecord\s+\$\w+\s*\)\s*:\s*void/',
			$src,
			'Importer::processNormalizedRecord(NormalizedRecord): void signature must not change.'
		);
	}

	/* ---------- Controller (feeds.php) ---------- */

	public function testFeedsControllerAddsTestSourceAndRunImport(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertMatchesRegularExpression(
			'/protected\s+function\s+testSource\s*\(\s*\)/',
			$src,
			'feeds.php must expose testSource() controller action.'
		);
		$this->assertMatchesRegularExpression(
			'/protected\s+function\s+runImport\s*\(\s*\)/',
			$src,
			'feeds.php must expose runImport() controller action.'
		);
	}

	public function testFeedsManagePassesCapabilityFlags(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		foreach ( [
			'is_sportssouth',
			'is_manual_upload',
			'is_running',
			'can_test_source',
			'can_refresh_lookups',
			'can_run',
			'type_label',
			'refresh_lookups_url',
			'test_source_url',
			'run_url',
		] as $flag )
		{
			$this->assertStringContainsString(
				"'{$flag}'",
				$src,
				"feeds.php manage() must expose the '{$flag}' capability flag / URL to the template."
			);
		}
	}

	public function testFeedsPreServesEveryPreviousDoAction(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		foreach ( [
			'add', 'delete', 'reorder', 'edit',
			'uploadFeed', 'runManualFeed',
			'refreshLookups', 'testConnection',
			'resetFeedStatus', 'catAttrs', 'reExtractAttributes',
		] as $action )
		{
			$this->assertMatchesRegularExpression(
				"/protected\\s+function\\s+{$action}\\s*\\(/",
				$src,
				"feeds.php must preserve pre-Phase-6 action: {$action}"
			);
		}
	}

	public function testTestSourceDoesNotWriteProducts(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		/* Extract the testSource() body (from signature to next
		 * "protected function" or class close). */
		$this->assertTrue(
			preg_match( '/protected\s+function\s+testSource\s*\([^)]*\)[^{]*\{(.+?)\n\s*(?:protected|public|private)\s+function\s+/s', $src, $m ) === 1,
			'Could not extract testSource() body.'
		);
		$body = $m[1];

		foreach ( [
			'createProduct(', 'updateProduct(', 'loadProduct(',
			'Importer::run(',  'Importer::runChunk(',
			'processNormalizedRecord(', 'processRecord(',
			'ImportLog::startRun',
			'queueReindex(',
			'processDiscontinuations(',
			'ConflictLog::record(', 'FlagProcessor::processFromFeed(',
		] as $forbidden )
		{
			$this->assertStringNotContainsString(
				$forbidden,
				$body,
				"testSource() must not call {$forbidden} — it must be read-only."
			);
		}

		$this->assertStringContainsString( 'sampleRecords(', $body,
			'testSource() must delegate the fetch/parse pass to Importer::sampleRecords().' );
		$this->assertStringContainsString( 'StructuredFeedAdapter', $body,
			'testSource() must use StructuredFeedAdapter to normalize the raw sample.' );
	}

	/* ---------- Templates ---------- */

	public function testFeedListTemplateGatesActionsOnCapabilityFlags(): void
	{
		$tpl = $this->readFile( 'gdcatalog/dev/html/admin/catalog/feedList.phtml' );
		foreach ( [
			'is_sportssouth', 'is_manual_upload',
			'can_test_source', 'can_refresh_lookups', 'can_run',
			'test_source_url', 'refresh_lookups_url', 'run_url',
			'type_label',
		] as $key )
		{
			$this->assertStringContainsString(
				$key,
				$tpl,
				"feedList template must consume {\$feed['{$key}']}."
			);
		}
		/* "Source" terminology in headers */
		$this->assertStringContainsString( 'Configured Sources', $tpl );
		$this->assertStringContainsString( 'Add Source',         $tpl );
	}

	public function testTestSourcePreviewTemplateExists(): void
	{
		$path = 'gdcatalog/dev/html/admin/catalog/testSourcePreview.phtml';
		$tpl  = $this->readFile( $path );
		$this->assertStringContainsString( '<ips:template parameters="$sourceName, $authType, $formatUpper, $recordCount, $rows, $backUrl"',
			$tpl,
			'testSourcePreview template must declare parameters matching the controller call.'
		);
		$this->assertStringContainsString( 'Read-only preview.', $tpl,
			'Preview must include the read-only banner so admin never confuses it with a real import.'
		);
	}

	public function testInstallSeedsAllDevHtmlTemplates(): void
	{
		$install = $this->readFile( 'gdcatalog/setup/install.php' );
		$this->assertStringContainsString(
			'/dev/html',
			$install,
			'install.php must walk dev/html/ so fresh installs get every template file (rule #52).'
		);
		$this->assertStringContainsString(
			"replace( 'core_theme_templates'",
			$install,
			'install.php must REPLACE INTO core_theme_templates so re-runs on reinstall are idempotent.'
		);
	}

	/* ---------- Network safety on list ---------- */

	public function testManageMethodMakesNoSyncHttpToSources(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/feeds.php' );
		$this->assertTrue(
			preg_match( '/function\s+manage\s*\([^)]*\)[^{]*\{(.+?)\n\s*(?:protected|public|private)\s+function\s+/s', $src, $m ) === 1,
			'Could not extract manage() body.'
		);
		$body = $m[1];
		foreach ( [
			'Http\\Url::external(',
			'->get()',
			'file_get_contents( "http',
			'file_get_contents(\'http',
			'curl_exec(',
			'ftp_connect(',
		] as $forbidden )
		{
			$this->assertStringNotContainsString(
				$forbidden,
				$body,
				"feeds.php::manage() must not perform live network calls to source endpoints — found: {$forbidden}"
			);
		}
	}
}
