<?php
/**
 * @brief       StructuredFeedAdapter Regression Test — Phase 4
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Verifies the Phase 4 refactor lands a source-neutral generic adapter
 * that:
 *
 *   1. Turns a parsed CSV / JSON / XML row (all of which the existing
 *      parsers reduce to associative arrays of string→string) into the
 *      same canonical NormalizedRecord shape given equivalent input.
 *   2. Runs FieldMapper::mapRecord + castTypes EXACTLY once per record
 *      — the Phase 4 "no double mapping" invariant.
 *   3. Preserves source identity (Distributor slug on the NormalizedRecord).
 *   4. Preserves the raw parsed input on the NormalizedRecord.
 *   5. Is idempotent (two normalize calls on the same input yield an
 *      equivalent NormalizedRecord).
 *   6. Carries ZERO Sports South-specific knowledge (asserted by the
 *      source-neutrality audit in the top-level Phase 4 verification
 *      script; the adapter file grep-clean for CATID / ITATR / BRDNO /
 *      PICREF / IMFGNO / ITBRDNO / MFGINO / SportsSouth).
 *
 * These assertions run against the adapter directly with a real
 * FieldMapper (constructed from a fixture mapping JSON), so no IPS
 * bootstrap or DB is needed for the mapping path itself.
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../gdcatalog/sources/Feed/NormalizedRecord.php';
require_once __DIR__ . '/../../gdcatalog/sources/Feed/FieldMapper.php';
require_once __DIR__ . '/../../gdcatalog/sources/Feed/SourceAdapter/SourceAdapterInterface.php';
require_once __DIR__ . '/../../gdcatalog/sources/Feed/SourceAdapter/StructuredFeedAdapter.php';

/**
 * Test-double FieldMapper — counts mapRecord invocations so the
 * "mapping runs exactly once" invariant can be asserted.
 */
class CountingFieldMapper extends \IPS\gdcatalog\Feed\FieldMapper
{
	public int $mapRecordCalls = 0;

	public function mapRecord( array $feedRecord ): array
	{
		$this->mapRecordCalls++;
		return parent::mapRecord( $feedRecord );
	}
}

class StructuredFeedAdapterTest extends TestCase
{
	/**
	 * Fixture field_mapping for a hypothetical generic feed. The three
	 * parser paths (CSV / JSON / XML) all reduce to associative arrays
	 * keyed by header/field name, so the same field_mapping applies to
	 * all three fixtures below.
	 */
	protected function fixtureMappingJson(): string
	{
		return json_encode( [
			'UPC_CODE'   => 'upc',
			'PROD_NAME'  => 'title',
			'MFG_NAME'   => 'brand',
			'MODEL_NO'   => 'model',
			'PROD_DESC'  => 'description',
			'CATEGORY'   => 'category',
			'IMAGE_URL'  => 'image_url',
			'CALIBER'    => 'caliber',
			'BARREL_LEN' => 'barrel_length',
			'MSRP'       => 'msrp',
			'CAPACITY'   => 'capacity',
			'NFA'        => 'nfa_item',
			'FFL'        => 'requires_ffl',
		] );
	}

	/**
	 * A representative raw record for the CSV parser output shape —
	 * associative array of header→cell, cells are all strings.
	 */
	protected function csvShapedRaw(): array
	{
		return [
			'UPC_CODE'   => '123456789012',
			'PROD_NAME'  => 'Acme Model 20 Rifle',
			'MFG_NAME'   => 'Acme Arms',
			'MODEL_NO'   => 'M20',
			'PROD_DESC'  => 'Bolt-action rifle',
			'CATEGORY'   => 'Rifles',
			'IMAGE_URL'  => 'https://example.test/m20.jpg',
			'CALIBER'    => '.308 Winchester',
			'BARREL_LEN' => '24',
			'MSRP'       => '899.99',
			'CAPACITY'   => '5',
			'NFA'        => 'false',
			'FFL'        => 'true',
		];
	}

	/**
	 * The same product, JSON-parsed. JsonParser::parse returns
	 * associative arrays keyed by top-level JSON object keys — same
	 * shape as CSV.
	 */
	protected function jsonShapedRaw(): array
	{
		return $this->csvShapedRaw();
	}

	/**
	 * The same product, XML-parsed. XmlParser::parse turns each XML
	 * element node into an associative array of child tags → text. Same
	 * shape as CSV.
	 */
	protected function xmlShapedRaw(): array
	{
		return $this->csvShapedRaw();
	}

	protected function makeAdapter( ?CountingFieldMapper $fm = null ): \IPS\gdcatalog\Feed\SourceAdapter\StructuredFeedAdapter
	{
		$fm = $fm ?? new CountingFieldMapper( $this->fixtureMappingJson() );
		return new \IPS\gdcatalog\Feed\SourceAdapter\StructuredFeedAdapter( null, $fm );
	}

	public function testNormalizeReturnsNormalizedRecord(): void
	{
		$dto = $this->makeAdapter()->normalize( $this->csvShapedRaw() );
		$this->assertInstanceOf(
			\IPS\gdcatalog\Feed\NormalizedRecord::class,
			$dto,
			'StructuredFeedAdapter::normalize must return a NormalizedRecord.'
		);
	}

	public function testCanonicalMapPopulatedFromMapping(): void
	{
		$canonical = $this->makeAdapter()->normalize( $this->csvShapedRaw() )->toArray();

		$this->assertSame( '123456789012', $canonical['upc'] );
		$this->assertSame( 'Acme Model 20 Rifle', $canonical['title'] );
		$this->assertSame( 'Acme Arms', $canonical['brand'] );
		$this->assertSame( 'M20', $canonical['model'] );
		$this->assertSame( 'Bolt-action rifle', $canonical['description'] );
		$this->assertSame( 'Rifles', $canonical['category'] );
		$this->assertSame( 'https://example.test/m20.jpg', $canonical['image_url'] );
		$this->assertSame( '.308 Winchester', $canonical['caliber'] );
	}

	public function testCastTypesApplied(): void
	{
		$canonical = $this->makeAdapter()->normalize( $this->csvShapedRaw() )->toArray();

		/* FieldMapper::castTypes casts:
		 *   int:   capacity
		 *   float: barrel_length, msrp
		 *   bool(0/1): nfa_item, requires_ffl */
		$this->assertSame( 5,      $canonical['capacity'] );
		$this->assertSame( 24.0,   $canonical['barrel_length'] );
		$this->assertSame( 899.99, $canonical['msrp'] );
		$this->assertSame( 0,      $canonical['nfa_item'] );
		$this->assertSame( 1,      $canonical['requires_ffl'] );
	}

	public function testCsvJsonXmlShapesProduceEquivalentCanonical(): void
	{
		$adapter = $this->makeAdapter();

		$csv  = $adapter->normalize( $this->csvShapedRaw() )->toArray();
		$json = $adapter->normalize( $this->jsonShapedRaw() )->toArray();
		$xml  = $adapter->normalize( $this->xmlShapedRaw() )->toArray();

		$this->assertSame( $csv, $json,
			'CSV-shaped and JSON-shaped raw inputs must produce equivalent canonical maps.' );
		$this->assertSame( $csv, $xml,
			'CSV-shaped and XML-shaped raw inputs must produce equivalent canonical maps.' );
	}

	public function testRawPreservedOnNormalizedRecord(): void
	{
		$dto = $this->makeAdapter()->normalize( $this->csvShapedRaw() );

		/* getRaw() must return the ORIGINAL parsed row verbatim —
		 * unmapped, untouched, no synthetic keys added. */
		$this->assertSame( $this->csvShapedRaw(), $dto->getRaw(),
			'StructuredFeedAdapter must preserve the raw parsed record verbatim.' );
	}

	public function testMappingRunsExactlyOncePerNormalize(): void
	{
		$fm = new CountingFieldMapper( $this->fixtureMappingJson() );
		$adapter = $this->makeAdapter( $fm );

		$adapter->normalize( $this->csvShapedRaw() );

		$this->assertSame( 1, $fm->mapRecordCalls,
			'StructuredFeedAdapter::normalize must call FieldMapper::mapRecord exactly once. ' .
			'That is the Phase 4 "no double mapping" invariant. If this rises above 1, a downstream ' .
			'consumer is silently re-mapping the record.' );
	}

	public function testMappingRunsExactlyOncePerRecordAcrossManyRecords(): void
	{
		$fm = new CountingFieldMapper( $this->fixtureMappingJson() );
		$adapter = $this->makeAdapter( $fm );

		for ( $i = 0; $i < 25; $i++ )
		{
			$row = $this->csvShapedRaw();
			$row['UPC_CODE'] = '12345678900' . str_pad( (string) $i, 1, '0', STR_PAD_LEFT );
			$adapter->normalize( $row );
		}

		$this->assertSame( 25, $fm->mapRecordCalls,
			'25 records → exactly 25 mapRecord calls. Any deviation indicates redundant mapping.' );
	}

	public function testNoFieldMapperYieldsEmptyCanonical(): void
	{
		/* Constructor called with no FieldMapper — adapter is a pure
		 * passthrough into NormalizedRecord (empty canonical, raw
		 * preserved). Matches the SportsSouthAdapter Phase-2 shape,
		 * and lets test contexts / one-off callers use the adapter
		 * without wiring a FieldMapper. */
		$adapter = new \IPS\gdcatalog\Feed\SourceAdapter\StructuredFeedAdapter( null, null );
		$dto = $adapter->normalize( $this->csvShapedRaw() );

		$this->assertSame( [], $dto->toArray(),
			'No FieldMapper → empty canonical (deferred mapping compat shape).' );
		$this->assertSame( $this->csvShapedRaw(), $dto->getRaw(),
			'Raw payload still preserved when FieldMapper is absent.' );
	}

	public function testIdempotency(): void
	{
		$adapter = $this->makeAdapter();
		$a = $adapter->normalize( $this->csvShapedRaw() );
		$b = $adapter->normalize( $this->csvShapedRaw() );

		$this->assertSame( $a->toArray(), $b->toArray(),
			'normalize() must be idempotent on the same input (canonical).' );
		$this->assertSame( $a->getRaw(), $b->getRaw(),
			'normalize() must be idempotent on the same input (raw).' );
	}

	public function testInterfaceCompliance(): void
	{
		$rc = new \ReflectionClass( \IPS\gdcatalog\Feed\SourceAdapter\StructuredFeedAdapter::class );
		$this->assertTrue(
			$rc->implementsInterface( \IPS\gdcatalog\Feed\SourceAdapter\SourceAdapterInterface::class ),
			'StructuredFeedAdapter must implement SourceAdapterInterface.'
		);
	}

	public function testMissingMappingKeysAreDropped(): void
	{
		/* FieldMapper only picks keys named in the map. Extra raw keys
		 * (unmapped) do NOT leak into the canonical output — they
		 * remain on getRaw() only. */
		$raw = $this->csvShapedRaw();
		$raw['UNMAPPED_JUNK'] = 'ignore me';

		$dto = $this->makeAdapter()->normalize( $raw );
		$canonical = $dto->toArray();

		$this->assertArrayNotHasKey( 'UNMAPPED_JUNK', $canonical );
		$this->assertArrayNotHasKey( 'unmapped_junk', $canonical );

		/* But raw retains it (provenance). */
		$this->assertSame( 'ignore me', $dto->getRaw()['UNMAPPED_JUNK'] );
	}

	public function testSourceNeutralityGuard(): void
	{
		/* Belt-and-suspenders check against SS field names leaking into
		 * canonical. The adapter has zero SS-specific knowledge, so
		 * raw keys like CATID / ITATR* / BRDNO / IMFGNO in the input
		 * must NOT be interpreted — they don't appear in the fixture
		 * mapping and therefore drop out of canonical, and the adapter
		 * does not synthesize any _CATEGORY_ID / _BRAND_NAME / _MPN /
		 * _ATTR_* sentinels of its own. */
		$raw = $this->csvShapedRaw();
		$raw['CATID']   = '94';
		$raw['ITATR1']  = 'Semi-Auto';
		$raw['IMFGNO']  = '9001';
		$raw['ITBRDNO'] = '9002';

		$canonical = $this->makeAdapter()->normalize( $raw )->toArray();

		$this->assertArrayNotHasKey( 'CATID',         $canonical );
		$this->assertArrayNotHasKey( 'ITATR1',        $canonical );
		$this->assertArrayNotHasKey( 'IMFGNO',        $canonical );
		$this->assertArrayNotHasKey( 'ITBRDNO',       $canonical );
		$this->assertArrayNotHasKey( '_CATEGORY_ID',  $canonical );
		$this->assertArrayNotHasKey( '_BRAND_NAME',   $canonical );
		$this->assertArrayNotHasKey( '_MANUFACTURER', $canonical );
		$this->assertArrayNotHasKey( '_MPN',          $canonical );

		/* Sentinels are also NOT synthesized on raw by this adapter. */
		$dto = $this->makeAdapter()->normalize( $this->csvShapedRaw() );
		$this->assertArrayNotHasKey( '_CATEGORY_ID',  $dto->getRaw() );
		$this->assertArrayNotHasKey( '_BRAND_NAME',   $dto->getRaw() );
		$this->assertArrayNotHasKey( '_MANUFACTURER', $dto->getRaw() );
		$this->assertArrayNotHasKey( '_MPN',          $dto->getRaw() );
	}
}
