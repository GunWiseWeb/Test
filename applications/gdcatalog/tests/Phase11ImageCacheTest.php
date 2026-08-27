<?php
/**
 * @brief       Phase 11 Image Dimension Cache Regression Tests
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Structural guards for gdcatalog v1.0.127. Full DB-driven flows
 * (real HTTP mocking, real Product save, real gd_feed_conflicts
 * lifecycle) require an IPS bootstrap; these tests are the shape
 * guards a fresh checkout would install.
 *
 * Guarded facts:
 *   - Import path (ConflictResolver::resolveHighestRes,
 *     ConflictResolver::getImageResolution) performs ZERO HTTP.
 *   - Cache API (lookup / enqueue / store / markFailed / pixelsFor)
 *     exposes the expected surface.
 *   - Cache pixelsFor returns null when unknown, 0 when failed,
 *     int on cache-hit — matching pre-Phase-11 semantics for
 *     failed = 0.
 *   - Deferred conflicts persist in gd_feed_conflicts with
 *     resolution_note='awaiting_image_dimensions'.
 *   - reevaluateForUrl is public static and applies the same
 *     tie / winner semantics + queues gd_reindex_queue.
 *   - FetchImageDimensions queue extension exists, is registered
 *     in extensions.json, and never touches Importer/Product
 *     write paths beyond the reevaluateForUrl helper.
 *   - Schema table gd_image_dimensions has the required columns.
 *   - Upgrade creates the table idempotently.
 *   - All prior-phase public APIs remain.
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

class Phase11ImageCacheTest extends TestCase
{
	protected function repoRoot(): string { return realpath( __DIR__ . '/../..' ); }
	protected function readFile( string $rel ): string
	{
		$path = $this->repoRoot() . '/' . ltrim( $rel, '/' );
		$this->assertFileExists( $path, "expected file at {$path}" );
		return (string) file_get_contents( $path );
	}

	/* ---------- ImageDimensionCache ---------- */

	public function testImageDimensionCacheApiSurface(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImageDimensionCache.php' );
		foreach ( [
			'public static function hashOf(',
			'public static function pixelsFromHint(',
			'public static function isValidHttpUrl(',
			'public static function lookup(',
			'public static function pixelsFor(',
			'public static function enqueue(',
			'public static function store(',
			'public static function markFailed(',
			"public const STATUS_PENDING = 'pending'",
			"public const STATUS_READY   = 'ready'",
			"public const STATUS_FAILED  = 'failed'",
			'public const FRESHNESS_DAYS = 30',
			'public const FAILED_RETRY_DAYS = 7',
		] as $needle )
		{
			$this->assertStringContainsString( $needle, $src, "ImageDimensionCache missing: $needle" );
		}
	}

	public function testCacheRejectsUnsafeSchemesAndPrivateHosts(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImageDimensionCache.php' );
		$this->assertStringContainsString( "'http', 'https'", $src,
			'ALLOWED_SCHEMES must whitelist http + https only.' );
		$this->assertStringContainsString( 'localhost|127\\.|10\\.|192\\.168\\.', $src,
			'isValidHttpUrl must reject localhost + RFC 1918 private ranges (SSRF hygiene).' );
	}

	public function testPixelsForHasFastPathThenCache(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImageDimensionCache.php' );
		/* URL-hint check must come BEFORE the DB lookup so trivially
		 * hint-parseable URLs never hit the DB. */
		$this->assertMatchesRegularExpression(
			"/function\\s+pixelsFor.+?pixelsFromHint\\s*\\(.+?\\\$hint\\s*>\\s*0.+?self::lookup\\s*\\(/s",
			$src,
			'pixelsFor must consult pixelsFromHint before self::lookup.'
		);
	}

	public function testCachePixelsForFailedReturnsZero(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImageDimensionCache.php' );
		/* Matching pre-Phase-11 sync behaviour (HTTP failure returned
		 * 0), a failed cache row also returns 0 so the OTHER image
		 * (if any) wins. */
		$this->assertMatchesRegularExpression(
			"/STATUS_FAILED.+?return\\s+0\\s*;/s",
			$src,
			'pixelsFor must return 0 for a STATUS_FAILED cache row.'
		);
	}

	public function testEnqueueIsDeduplicated(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ImageDimensionCache.php' );
		$this->assertStringContainsString( 'FRESHNESS_DAYS', $src );
		$this->assertStringContainsString( 'FAILED_RETRY_DAYS', $src );
		$this->assertStringContainsString( 'PENDING_STALE_SECONDS', $src );
		/* All three "skip re-enqueue" branches must return early. */
		$this->assertMatchesRegularExpression(
			"/STATUS_READY\\s+&&\\s+\\\$age\\s+<\\s+self::FRESHNESS_DAYS\\s+\\*\\s+86400\\s+\\)\\s*\\{\\s*return\\s*;/s",
			$src
		);
		$this->assertMatchesRegularExpression(
			"/STATUS_PENDING\\s+&&\\s+\\\$age\\s+<\\s+self::PENDING_STALE_SECONDS\\s+\\)\\s*\\{\\s*return\\s*;/s",
			$src
		);
		$this->assertMatchesRegularExpression(
			"/STATUS_FAILED\\s+&&\\s+\\\$age\\s+<\\s+self::FAILED_RETRY_DAYS\\s+\\*\\s+86400\\s+\\)\\s*\\{\\s*return\\s*;/s",
			$src
		);
	}

	/* ---------- ConflictResolver import-path network safety ---------- */

	public function testResolveHighestResUsesCacheOnlyNoHttp(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ConflictResolver.php' );
		/* Extract the resolveHighestRes body by matching from the
		 * signature to the next protected/public/private function or
		 * end-of-class. Method bodies contain many nested `}`,
		 * so we anchor on the SIBLING method boundary rather than
		 * a non-greedy inner-brace regex. */
		$this->assertTrue(
			preg_match( '/protected\s+function\s+resolveHighestRes\s*\([^)]*\)[^{]*\{(.+?)\n\s*(?:protected|public|private)\s+function\s+/s', $src, $m ) === 1,
			'Could not extract resolveHighestRes body.'
		);
		$body = $m[1];
		foreach ( [
			'Http\\Url::external(',
			'->get()',
			'curl_exec(',
			'getimagesize(',
			'file_put_contents(',
		] as $forbidden )
		{
			$this->assertStringNotContainsString(
				$forbidden,
				$body,
				"resolveHighestRes must not perform HTTP — found: {$forbidden}"
			);
		}
		$this->assertStringContainsString( 'ImageDimensionCache::pixelsFor(', $body,
			'resolveHighestRes must use ImageDimensionCache::pixelsFor for the sync path.'
		);
		$this->assertStringContainsString( 'ImageDimensionCache::enqueue(', $body,
			'resolveHighestRes must enqueue missing dimensions instead of fetching them synchronously.'
		);
		$this->assertStringContainsString( 'writeDeferredImageConflict(', $body,
			'resolveHighestRes must defer to gd_feed_conflicts when at least one dimension is unknown.'
		);
	}

	public function testGetImageResolutionRetiresHttp(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ConflictResolver.php' );
		/* getImageResolution is at the very end of the class after
		 * the refactor — extract from signature to the next
		 * sibling function OR to end-of-class. */
		$this->assertTrue(
			preg_match( '/protected\s+function\s+getImageResolution\s*\([^)]*\)[^{]*\{(.+?)\n\s*(?:(?:protected|public|private)\s+function\s+|\}\s*$)/s', $src, $m ) === 1,
			'Could not extract getImageResolution body.'
		);
		$body = $m[1];
		foreach ( [
			'Http\\Url::external(',
			'->get()',
			'curl_exec(',
			'getimagesize(',
			'file_put_contents(',
			'tempnam(',
		] as $forbidden )
		{
			$this->assertStringNotContainsString(
				$forbidden,
				$body,
				"getImageResolution must not perform HTTP or filesystem writes — found: {$forbidden}"
			);
		}
		$this->assertStringContainsString( 'ImageDimensionCache::pixelsFromHint(', $body,
			'getImageResolution must retain URL-hint parsing via ImageDimensionCache::pixelsFromHint.'
		);
	}

	public function testDeferredImageConflictShape(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ConflictResolver.php' );
		$this->assertStringContainsString( "'awaiting_image_dimensions'", $src,
			'Deferred image conflicts must be tagged with resolution_note=awaiting_image_dimensions.'
		);
		$this->assertStringContainsString( 'protected function writeDeferredImageConflict(', $src,
			'writeDeferredImageConflict must exist to persist unresolved highest_res comparisons.'
		);
	}

	public function testReevaluateForUrlPublicApi(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ConflictResolver.php' );
		$this->assertMatchesRegularExpression(
			'/public\s+static\s+function\s+reevaluateForUrl\s*\(\s*string\s+\$url\s*\)\s*:\s*int/',
			$src,
			'reevaluateForUrl(string $url): int must exist as a public static entry point for the queue worker.'
		);
	}

	public function testReevaluatePreservesSemanticsAndReindexes(): void
	{
		$src = $this->readFile( 'gdcatalog/sources/Feed/ConflictResolver.php' );
		$this->assertTrue(
			preg_match( '/public\s+static\s+function\s+reevaluateForUrl\s*\([^)]*\)[^{]*\{(.+?)\n\s*(?:protected|public|private)\s+function\s+/s', $src, $m ) === 1,
			'Could not extract reevaluateForUrl body.'
		);
		$body = $m[1];
		/* Same tie-break rule: only incoming > current swaps. */
		$this->assertStringContainsString( '$incomingPx > $currentPx', $body,
			'Re-eval must use the same tie semantics (incoming > current) as the sync path.'
		);
		/* Winner path saves product AND queues reindex through the
		 * existing gd_reindex_queue path — no OpenSearch HTTP. */
		$this->assertStringContainsString( '$product->save()', $body );
		$this->assertStringContainsString( "'gd_reindex_queue'", $body,
			'Re-eval must queue product for reindex through the existing gd_reindex_queue path.'
		);
		/* Explicitly does not touch OpenSearch */
		$this->assertStringNotContainsString( 'OpenSearchIndexer::', $body,
			'Re-eval must NOT touch OpenSearch directly — reuse the reindex queue.'
		);
	}

	/* ---------- FetchImageDimensions queue extension ---------- */

	public function testFetchImageDimensionsExtensionRegistered(): void
	{
		$ext = json_decode( $this->readFile( 'gdcatalog/data/extensions.json' ), true );
		$this->assertSame(
			'IPS\\gdcatalog\\extensions\\core\\Queue\\FetchImageDimensions',
			$ext['core']['Queue']['FetchImageDimensions'] ?? null,
			'FetchImageDimensions must be registered under core.Queue.'
		);
	}

	public function testFetchImageDimensionsWorkerShape(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/FetchImageDimensions.php' );
		$this->assertStringContainsString( 'class _FetchImageDimensions extends QueueAbstract', $src,
			'Worker must extend QueueAbstract with the dual-class shape.'
		);
		$this->assertStringContainsString( 'class FetchImageDimensions extends _FetchImageDimensions {}', $src );
		$this->assertMatchesRegularExpression(
			'/const\s+HTTP_TIMEOUT_SECONDS\s*=\s*\d+/',
			$src,
			'Worker must declare an explicit HTTP timeout constant.'
		);
		$this->assertStringContainsString( 'ImageDimensionCache::store(',      $src );
		$this->assertStringContainsString( 'ImageDimensionCache::markFailed(', $src );
		$this->assertStringContainsString( 'ConflictResolver::reevaluateForUrl(', $src );
	}

	public function testWorkerCallsReevaluateAfterSuccessfulStore(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/FetchImageDimensions.php' );
		$this->assertTrue(
			preg_match( '/function\s+run\s*\([^)]*\)[^{]*\{(.+?)public\s+function\s+getProgress/s', $src, $m ) === 1,
			'Could not extract worker run() body.'
		);
		$body = $m[1];
		$storePos      = strpos( $body, 'ImageDimensionCache::store(' );
		$reevaluatePos = strpos( $body, 'ConflictResolver::reevaluateForUrl(' );
		$this->assertNotFalse( $storePos );
		$this->assertNotFalse( $reevaluatePos );
		$this->assertLessThan( $reevaluatePos, $storePos,
			'Worker must store the dimensions BEFORE calling reevaluateForUrl (otherwise pixelsFor returns null and the re-eval no-ops).'
		);
	}

	public function testWorkerDoesNotWriteCatalogProductsDirectly(): void
	{
		$src = $this->readFile( 'gdcatalog/extensions/core/Queue/FetchImageDimensions.php' );
		foreach ( [
			'Importer::run(',
			'Importer::runChunk(',
			'processNormalizedRecord(',
			'processRecord(',
			'OpenSearchIndexer::',
		] as $forbidden )
		{
			$this->assertStringNotContainsString( $forbidden, $src,
				"FetchImageDimensions worker must not invoke {$forbidden} directly."
			);
		}
	}

	/* ---------- Schema + upgrade ---------- */

	public function testSchemaHasImageDimensionsTable(): void
	{
		$s = json_decode( $this->readFile( 'gdcatalog/data/schema.json' ), true );
		$this->assertArrayHasKey( 'gd_image_dimensions', $s );
		$cols = $s['gd_image_dimensions']['columns'] ?? [];
		foreach ( [ 'url_hash', 'url', 'width', 'height', 'status', 'checked_at', 'last_error' ] as $c )
		{
			$this->assertArrayHasKey( $c, $cols, "gd_image_dimensions.$c missing" );
		}
		$this->assertSame( 'CHAR', $cols['url_hash']['type'] );
		$this->assertSame( 64,     $cols['url_hash']['length'] );
	}

	public function testUpgradeCreatesImageTableIdempotently(): void
	{
		$src = $this->readFile( 'gdcatalog/setup/upg_10127/upgrade.php' );
		$this->assertStringContainsString( "checkForTable( 'gd_image_dimensions' )", $src,
			'Upgrade must guard createTable behind checkForTable to remain idempotent.'
		);
	}

	/* ---------- Regression on prior phases ---------- */

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
