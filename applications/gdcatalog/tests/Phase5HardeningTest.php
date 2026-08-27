<?php
/**
 * @brief       Phase 5 Hardening Regression Tests
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Static regression checks for the four fixes shipped in gdcatalog
 * v1.0.121 (Phase 5). All four are behavioural changes that manifest
 * at import/render time under specific error conditions the standard
 * unit test rig cannot reproduce without an IPS bootstrap. These
 * tests therefore act as source-level guards: they read the same
 * repo files a fresh checkout would install and assert the
 * corrected shapes are present.
 *
 * Part 1 — data/schema.json contains the three Sports South lookup
 *          tables with the columns the code writes/reads.
 * Part 2 — dashboard.php::manage() does not construct
 *          OpenSearchIndexer, call indexExists(), or call getStats()
 *          in its body. The rebuildIndex / processQueue admin
 *          methods still do (explicit invocation is allowed).
 * Part 3 — categorize.php uses \IPS\gdcatalog\Catalog\Product, not
 *          \IPS\gdcatalog\Product.
 * Part 4 — ConflictResolver::isLocked() catches \Throwable, not
 *          just \UnderflowException, per CLAUDE.md rule #35.
 *
 * The tests are deterministic and IPS-free — they only inspect the
 * source files as strings.
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

class Phase5HardeningTest extends TestCase
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

	/* ================================================================
	 * Part 1 — schema.json
	 * ================================================================ */

	public function testSchemaContainsSportsSouthLookupTables(): void
	{
		$schema = json_decode( $this->readFile( 'gdcatalog/data/schema.json' ), true );
		$this->assertIsArray( $schema, 'schema.json must be valid JSON' );

		$this->assertArrayHasKey( 'gd_sportssouth_brands',       $schema );
		$this->assertArrayHasKey( 'gd_sportssouth_categories',   $schema );
		$this->assertArrayHasKey( 'gd_sportssouth_category_map', $schema );
	}

	public function testGdSportssouthCategoriesShape(): void
	{
		$schema = json_decode( $this->readFile( 'gdcatalog/data/schema.json' ), true );
		$cols = $schema['gd_sportssouth_categories']['columns'] ?? [];
		foreach ( [ 'catid', 'catdes', 'last_synced', 'raw_data' ] as $c )
		{
			$this->assertArrayHasKey( $c, $cols, "gd_sportssouth_categories missing column: $c" );
		}
		$this->assertSame( 'INT', $cols['catid']['type'] );
		$this->assertFalse( (bool) $cols['catid']['allow_null'] );
		$this->assertSame( 'MEDIUMTEXT', $cols['raw_data']['type'] );

		$indexes = $schema['gd_sportssouth_categories']['indexes'] ?? [];
		$this->assertArrayHasKey( 'PRIMARY', $indexes );
		$this->assertSame( [ 'catid' ], $indexes['PRIMARY']['columns'] );
	}

	public function testGdSportssouthCategoryMapShape(): void
	{
		$schema = json_decode( $this->readFile( 'gdcatalog/data/schema.json' ), true );
		$cols = $schema['gd_sportssouth_category_map']['columns'] ?? [];
		foreach ( [ 'sportssouth_catid', 'gd_category_id' ] as $c )
		{
			$this->assertArrayHasKey( $c, $cols, "gd_sportssouth_category_map missing column: $c" );
		}
		$this->assertSame( 'INT', $cols['sportssouth_catid']['type'] );
		$this->assertSame( 'INT', $cols['gd_category_id']['type'] );

		$indexes = $schema['gd_sportssouth_category_map']['indexes'] ?? [];
		$this->assertArrayHasKey( 'PRIMARY', $indexes );
		$this->assertSame( [ 'sportssouth_catid' ], $indexes['PRIMARY']['columns'] );
	}

	public function testGdSportssouthBrandsHasLastSyncedAndRawData(): void
	{
		$schema = json_decode( $this->readFile( 'gdcatalog/data/schema.json' ), true );
		$cols = $schema['gd_sportssouth_brands']['columns'] ?? [];
		$this->assertArrayHasKey( 'last_synced', $cols,
			'feeds.php::processBrandLookup writes last_synced — must be in schema.' );
		$this->assertArrayHasKey( 'raw_data', $cols,
			'feeds.php::processBrandLookup writes raw_data — must be in schema.' );
	}

	/* ================================================================
	 * Part 2 — dashboard.php sync OpenSearch calls
	 * ================================================================ */

	public function testDashboardManageDoesNotCallOpenSearch(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/dashboard.php' );

		/* Grab the manage() method body specifically — from
		 * "protected function manage()" or "public function manage()"
		 * to the next "protected function" / "public function". */
		$this->assertTrue(
			preg_match( '/function\s+manage\s*\([^)]*\)[^{]*\{(.+?)\n\s*(?:protected|public|private)\s+function\s+/s', $src, $m ) === 1,
			'Could not extract manage() body from dashboard.php'
		);
		$manageBody = $m[1];

		$this->assertStringNotContainsString( 'indexExists(', $manageBody,
			'dashboard::manage() must not call OpenSearchIndexer::indexExists() during render (rule #8).' );
		$this->assertStringNotContainsString( '->getStats(', $manageBody,
			'dashboard::manage() must not call OpenSearchIndexer::getStats() during render.' );
		$this->assertStringNotContainsString( 'OpenSearchIndexer::i()', $manageBody,
			'dashboard::manage() must not instantiate OpenSearchIndexer at all — even lazy construction risks accidental HTTP if new methods get called on it.' );
	}

	public function testDashboardRebuildAndProcessQueueStillHitOpenSearch(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/dashboard.php' );

		/* rebuildIndex + processQueue are explicit admin actions —
		 * they are ALLOWED to call OpenSearch. This test guards
		 * against overzealous cleanup that would strip them too. */
		$this->assertTrue(
			preg_match( '/function\s+rebuildIndex\s*\(\s*\).*?rebuildIndex\s*\(\s*\)/s', $src ) === 1,
			'rebuildIndex admin action must remain and still call the indexer.' );
		$this->assertTrue(
			preg_match( '/function\s+processQueue\s*\(\s*\).*?processQueue\s*\(/s', $src ) === 1,
			'processQueue admin action must remain and still call the indexer.' );
	}

	/* ================================================================
	 * Part 3 — categorize.php Product namespace
	 * ================================================================ */

	public function testCategorizeUsesCorrectProductNamespace(): void
	{
		$src = $this->readFile( 'gdcatalog/modules/admin/catalog/categorize.php' );

		$this->assertStringContainsString(
			'\IPS\gdcatalog\Catalog\Product::load(',
			$src,
			'categorize.php must load Product via \IPS\gdcatalog\Catalog\Product (fix for Phase 5 defect #3).'
		);

		/* The bare (wrong) reference must be gone from any executable
		 * line. Comments/docblocks may still mention it in explanatory
		 * text — verify no direct call. */
		$this->assertStringNotContainsString(
			'\IPS\gdcatalog\Product::load(',
			$src,
			'categorize.php must not reference the nonexistent \IPS\gdcatalog\Product class.'
		);
	}

	public function testProductClassLivesUnderCatalog(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Catalog/Product.php' );
		$this->assertStringContainsString( 'namespace IPS\\gdcatalog\\Catalog', $src,
			'Product class must live under IPS\\gdcatalog\\Catalog — Phase 5 fix rests on this.' );
	}

	/* ================================================================
	 * Part 4 — ConflictResolver catch broadened
	 * ================================================================ */

	public function testConflictResolverIsLockedCatchesThrowable(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ConflictResolver.php' );

		/* Pre-Phase-5 the file had exactly one `catch ( \UnderflowException )`
		 * (isLocked's field-locks DB path) and one `catch ( \Throwable )`
		 * (elsewhere). After the fix, \UnderflowException must be gone
		 * and \Throwable count must have gone up by one. Checking the
		 * whole file is more robust than trying to extract just
		 * isLocked()'s body — that method has nested if/try blocks
		 * that trip a simple non-greedy regex. */
		$this->assertStringNotContainsString( 'catch ( \\UnderflowException )', $src,
			'ConflictResolver.php must no longer have a `catch ( \\UnderflowException )` block — the only historical catch of it (isLocked) was broadened to \\Throwable per CLAUDE.md rule #35. The token \\UnderflowException may still appear in explanatory docblock prose.' );

		$throwableCount = substr_count( $src, 'catch ( \\Throwable )' );
		$this->assertGreaterThanOrEqual( 2, $throwableCount,
			'ConflictResolver.php must contain at least 2 `catch ( \\Throwable )` blocks post-Phase-5 (was 1 pre-Phase-5; isLocked now catches \\Throwable too).' );
	}

	/* ================================================================
	 * Part 5 — deferred image resolution: report guard
	 * ================================================================ */

	public function testImageResolutionDeferralIsDocumentedAsDeferred(): void
	{
		/* Guard: the Phase 5 prompt allows Part 5 to be deferred if
		 * preserving highest_res semantics requires a broader data
		 * model change. This test documents the current state — the
		 * synchronous HTTP GET still lives in
		 * ConflictResolver::getImageResolution and the upgrade
		 * comments explicitly note the deferral. Delete this test
		 * when Part 5 lands in a later phase. */
		$upg = $this->readFile( 'gdcatalog/setup/upg_10121/upgrade.php' );
		$this->assertStringContainsString( 'Part 5 — image resolution deferral: NOT IMPLEMENTED', $upg,
			'Phase 5 upgrade must document Part 5 as deferred, per prompt guidance.' );
	}
}
