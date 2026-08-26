<?php
/**
 * @brief       SportsSouthAdapter Regression Test — Phase 2
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       25 Aug 2026
 *
 * Verifies the Phase 2 refactor is behaviour-preserving:
 *
 *   BEFORE PHASE 2
 *     raw SS record
 *       ↓
 *     Importer::enrichSportsSouthRecord() (inline body)
 *       ↓
 *     enriched array
 *
 *   AFTER PHASE 2
 *     raw SS record
 *       ↓
 *     SportsSouthAdapter::normalize()
 *       ↓
 *     NormalizedRecord
 *       ↓
 *     ->getRaw() = enriched array (equivalent to before)
 *
 * The important assertions are per-key on the enriched output —
 * that every synthetic key the adapter is expected to produce
 * (_BRAND_NAME, _MANUFACTURER, _MPN, _CATEGORY_ID, _CATEGORY_DESC,
 * _ATTR_*, PICREF as URL) matches what the pre-refactor code path
 * would have produced.
 *
 * Uses a subclass of SportsSouthAdapter that overrides all four
 * DB-backed lazy loaders so this test runs without an IPS bootstrap
 * or a live DB connection. That mirrors what an in-repo unit test
 * needs — the goal is verifying the enrichment RULES, not the DB
 * loading (which is a copy-paste of the original queries).
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../gdcatalog/sources/Feed/NormalizedRecord.php';
require_once __DIR__ . '/../../gdcatalog/sources/Feed/SourceAdapter/SourceAdapterInterface.php';
require_once __DIR__ . '/../../gdcatalog/sources/Feed/SourceAdapter/SportsSouthAdapter.php';

/**
 * Test-double: skips the DB-backed lazy loaders. Instead we inject
 * pre-populated lookup arrays so the enrichment logic runs against
 * deterministic fixture data.
 */
class SportsSouthAdapterFixtureable extends \IPS\gdcatalog\Feed\SourceAdapter\SportsSouthAdapter
{
	public function seedLookups(
		array $brandLookup = [],
		array $categoryLookup = [],
		array $categoryMap = [],
		array $categoryAttrs = []
	): void
	{
		$rc = new \ReflectionClass( parent::class );
		foreach ( [
			'brandLookup'    => $brandLookup,
			'categoryLookup' => $categoryLookup,
			'categoryMap'    => $categoryMap,
			'categoryAttrs'  => $categoryAttrs,
		] as $prop => $val )
		{
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $this, $val );
		}
	}
}

class SportsSouthAdapterTest extends TestCase
{
	/**
	 * A representative SS raw row that exercises every enrichment
	 * responsibility listed in the Phase 2 prompt:
	 *   1. UPC (passed through untouched)
	 *   2. brand lookup (via IMFGNO -> _BRAND_NAME)
	 *   3. manufacturer lookup (differs from brand -> _MANUFACTURER)
	 *   4. MPN (from MFGINO -> _MPN)
	 *   5. category lookup (CATID -> _CATEGORY_DESC + _CATEGORY_ID)
	 *   6. PICREF/image URL (real URL substituted)
	 *   7. attribute mapping (per-category ATTR_LABEL_MAP hit)
	 *   8. title-derived enrichment (via TitleParser — cannot easily
	 *      test without loading TitleParser + IPS constants, so this
	 *      test omits title-fallback assertions; the enrichment
	 *      itself is exercised by the raw SS field mapping keys.)
	 *   9. _ATTR_* values (populated where category ATTRs match)
	 *   10. raw source retention (getRaw includes originals)
	 */
	protected function sampleRaw(): array
	{
		return [
			'ITUPC'   => '787450518001',
			'IMFGNO'  => '9001',        // manufacturer key
			'ITBRDNO' => '9002',        // brand key (different name → _MANUFACTURER set)
			'MFGINO'  => 'SF-556-001',  // MPN
			'CATID'   => '94',          // Rifles category
			'PICREF'  => '1533',
			'ITEMNO'  => 'X-1533',
			/* ATTR values keyed by slot; per-category label defines what column
			   these end up on. Category 94 has ATTR1 = 'Action Type', ATTR2 =
			   'Caliber', ATTR3 = 'Barrel Length'. */
			'ITATR1'  => 'Semi-Auto',
			'ITATR2'  => '5.56 NATO',
			'ITATR3'  => '16"',
			'SHDESC'  => 'ACME SF-556 5.56',
			'IDESC'   => 'Sample Rifle',
		];
	}

	protected function makeAdapter(): SportsSouthAdapterFixtureable
	{
		$a = new SportsSouthAdapterFixtureable();
		$a->seedLookups(
			brandLookup: [
				'9001' => 'ACME Corp',        // Manufacturer
				'9002' => 'ACME Arms',        // Brand
			],
			categoryLookup: [
				'94' => 'RIFLES CENTERFIRE',
			],
			categoryMap: [
				'94' => 42,                   // canonical gd_categories.id
			],
			categoryAttrs: [
				'94' => [
					1 => 'Action Type',
					2 => 'Caliber',
					3 => 'Barrel Length',
				],
			],
		);
		return $a;
	}

	public function testNormalizeReturnsNormalizedRecord(): void
	{
		$dto = $this->makeAdapter()->normalize( $this->sampleRaw() );
		$this->assertInstanceOf(
			\IPS\gdcatalog\Feed\NormalizedRecord::class,
			$dto,
			'SportsSouthAdapter::normalize must return a NormalizedRecord.'
		);
	}

	public function testBrandAndManufacturerResolvedFromLookups(): void
	{
		$raw = $this->makeAdapter()->normalize( $this->sampleRaw() )->getRaw();
		$this->assertSame( 'ACME Arms', $raw['_BRAND_NAME'],
			'ITBRDNO=9002 → brandLookup → _BRAND_NAME must be "ACME Arms".' );
		$this->assertSame( 'ACME Corp', $raw['_MANUFACTURER'],
			'IMFGNO=9001 differs from brand → _MANUFACTURER must be set to the manufacturer.' );
	}

	public function testMpnFromMfgIno(): void
	{
		$raw = $this->makeAdapter()->normalize( $this->sampleRaw() )->getRaw();
		$this->assertSame( 'SF-556-001', $raw['_MPN'],
			'MFGINO → _MPN passthrough.' );
	}

	public function testCategoryLookupAndMap(): void
	{
		$raw = $this->makeAdapter()->normalize( $this->sampleRaw() )->getRaw();
		$this->assertSame( 'RIFLES CENTERFIRE', $raw['_CATEGORY_DESC'],
			'CATID → categoryLookup → _CATEGORY_DESC.' );
		$this->assertSame( '42', $raw['_CATEGORY_ID'],
			'CATID → categoryMap → _CATEGORY_ID (stringified).' );
	}

	public function testPicrefReplacedWithUrl(): void
	{
		$raw = $this->makeAdapter()->normalize( $this->sampleRaw() )->getRaw();
		$this->assertStringStartsWith( 'http', $raw['PICREF'],
			'PICREF must be replaced by SportsSouthClient::imageUrlForPicref output (starts with http).' );
		$this->assertNotSame( '1533', $raw['PICREF'],
			'PICREF must NOT still be the original ID after enrichment.' );
	}

	public function testAttrLabelMapProducesSyntheticFields(): void
	{
		$raw = $this->makeAdapter()->normalize( $this->sampleRaw() )->getRaw();
		/* Category 94 defines ATTR1=Action Type, ATTR2=Caliber, ATTR3=Barrel Length.
		   The ATTR_LABEL_MAP maps 'action type' → action_type, 'caliber' → caliber,
		   'barrel length' → barrel_length. So we expect these synthetic fields. */
		$this->assertSame( 'Semi-Auto', $raw['_ACTION_TYPE'],
			'ITATR1 + category ATTR1="Action Type" → _ACTION_TYPE.' );
		$this->assertSame( '5.56 NATO', $raw['_CALIBER'],
			'ITATR2 + category ATTR2="Caliber" → _CALIBER.' );
		$this->assertSame( '16', $raw['_BARREL_LENGTH'],
			'ITATR3="16\"" + category ATTR3="Barrel Length" → _BARREL_LENGTH parsed to "16".' );
	}

	public function testRawSourceRetained(): void
	{
		$raw = $this->makeAdapter()->normalize( $this->sampleRaw() )->getRaw();
		/* Every original SS raw key must survive enrichment (except PICREF
		   which is intentionally transformed in-place). */
		$this->assertSame( '787450518001', $raw['ITUPC'] );
		$this->assertSame( '9001',         $raw['IMFGNO'] );
		$this->assertSame( '9002',         $raw['ITBRDNO'] );
		$this->assertSame( 'SF-556-001',   $raw['MFGINO'] );
		$this->assertSame( '94',           $raw['CATID'] );
		$this->assertSame( 'X-1533',       $raw['ITEMNO'] );
		$this->assertSame( 'Semi-Auto',    $raw['ITATR1'] );
		$this->assertSame( '5.56 NATO',    $raw['ITATR2'] );
		$this->assertSame( '16"',          $raw['ITATR3'] );
	}

	public function testSourceFeedNullByDefault(): void
	{
		$dto = $this->makeAdapter()->normalize( $this->sampleRaw() );
		$this->assertNull( $dto->getSourceFeed(),
			'No Distributor passed to constructor → getSourceFeed() null.' );
		$this->assertNull( $dto->getSourceKey() );
	}

	public function testIdempotency(): void
	{
		$adapter = $this->makeAdapter();
		$raw1 = $adapter->normalize( $this->sampleRaw() )->getRaw();
		$raw2 = $adapter->normalize( $this->sampleRaw() )->getRaw();
		$this->assertSame( $raw1, $raw2,
			'normalize() must be idempotent on the same input (lazy caches notwithstanding).' );
	}

	public function testInterfaceCompliance(): void
	{
		$rc = new \ReflectionClass( \IPS\gdcatalog\Feed\SourceAdapter\SportsSouthAdapter::class );
		$this->assertTrue(
			$rc->implementsInterface( \IPS\gdcatalog\Feed\SourceAdapter\SourceAdapterInterface::class ),
			'SportsSouthAdapter must implement SourceAdapterInterface.'
		);
	}
}
