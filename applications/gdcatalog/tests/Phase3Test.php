<?php
/**
 * @brief       Phase 3 Regression Test — processRecord source-neutralization
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       26 Aug 2026
 *
 * Verifies the Phase 3 refactor is behaviour-preserving on the
 * SportsSouthAdapter boundary:
 *
 *   BEFORE PHASE 3
 *     Importer::processRecord() read raw $rawRecord['CATID'] and
 *     called static self::accessoryAttrsFor() + instance
 *     $this->topSlugForCategoryId() to route SS ITATR* slot values
 *     into accessory columns. That coupled processRecord to Sports
 *     South-specific field names (CATID / ITATR*).
 *
 *   AFTER PHASE 3
 *     SportsSouthAdapter::enrich() writes:
 *       - `_CATEGORY_ID` (overridden via CategoryMapper::resolve when
 *         raw CATID > 0),
 *       - `_ATTR_<col>` for every accessory-slot value produced by
 *         the moved ACCESSORY_ATTR_MAP / accessoryAttrs logic.
 *     Importer::processRecord no longer reads raw CATID or raw ITATR*.
 *     The generic `_ATTR_*` merge already living in processRecord
 *     picks the accessory columns up automatically.
 *
 * These assertions run against the adapter directly with a fake
 * CategoryMapper so no IPS bootstrap or DB is needed.
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../gdcatalog/sources/Feed/NormalizedRecord.php';
require_once __DIR__ . '/../../gdcatalog/sources/Feed/SourceAdapter/SourceAdapterInterface.php';
require_once __DIR__ . '/../../gdcatalog/sources/Feed/SourceAdapter/SportsSouthAdapter.php';

/**
 * Test-double CategoryMapper: honours a fixture map, defaults
 * unmapped ids to 58 (matching the real "Uncategorized" fallback).
 * Only the resolve(int|string): int method is exercised by
 * Phase 3-moved logic.
 */
class FakeCategoryMapperP3 extends \IPS\gdcatalog\Feed\CategoryMapper
{
	protected array $fixture;

	public function __construct( array $fixture )
	{
		$this->fixture = $fixture;
	}

	public function resolve( int|string $rawCatId ): int
	{
		$key = (string) $rawCatId;
		return $this->fixture[ $key ] ?? 58;
	}
}

/**
 * Test-double adapter — seeds the four SS lookup arrays AND the
 * topSlug cache the Phase-3-moved accessoryAttrs branch reads, so
 * the accessory pipeline runs against deterministic fixture data
 * without hitting gd_categories.
 */
class Phase3AdapterFixtureable extends \IPS\gdcatalog\Feed\SourceAdapter\SportsSouthAdapter
{
	public function seedAll(
		array $brandLookup = [],
		array $categoryLookup = [],
		array $categoryMap = [],
		array $categoryAttrs = [],
		array $topSlugByCatId = []
	): void
	{
		$rc = new \ReflectionClass( parent::class );
		foreach ( [
			'brandLookup'    => $brandLookup,
			'categoryLookup' => $categoryLookup,
			'categoryMap'    => $categoryMap,
			'categoryAttrs'  => $categoryAttrs,
			'topSlugByCatId' => $topSlugByCatId,
		] as $prop => $val )
		{
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $this, $val );
		}
	}
}

class Phase3Test extends TestCase
{
	/**
	 * Bare SS row in a holster category. Fixture wires:
	 *   CATID 200 → CategoryMapper canonical id 88
	 *   canonical 88 → topSlug 'holsters-carry'
	 * so ACCESSORY_ATTR_MAP[holsters-carry] should extract
	 *   ITATR1 → holster_type
	 *   ITATR2 → holster_color
	 *   ITATR3 → holster_material
	 *   ITATR5 → holster_hand
	 * as _ATTR_<col> sentinels on the enriched raw record.
	 */
	protected function holsterRaw(): array
	{
		return [
			'ITUPC'  => '111111111111',
			'IMFGNO' => '',
			'ITBRDNO'=> '',
			'MFGINO' => '',
			'CATID'  => '200',
			'PICREF' => '',
			'ITEMNO' => 'H-200',
			'ITATR1' => 'IWB',
			'ITATR2' => 'Black',
			'ITATR3' => 'Kydex',
			'ITATR5' => 'Right',
			'SHDESC' => 'Kydex Holster Right Black IWB',
			'IDESC'  => 'Inside-the-waistband holster',
		];
	}

	/**
	 * Optics row — 'optics' top-slug hits the special-cased branch
	 * of accessoryAttrs() (pattern validation on mag/objective).
	 */
	protected function opticsRaw(): array
	{
		return [
			'ITUPC'  => '222222222222',
			'CATID'  => '300',
			'PICREF' => '',
			'ITATR1' => '4-12x',
			'ITATR2' => '40mm',
			'SHDESC' => 'ACME Scope 4-12x40',
			'IDESC'  => 'Riflescope',
		];
	}

	protected function makeAdapter( array $catMap ): Phase3AdapterFixtureable
	{
		$mapper = new FakeCategoryMapperP3( $catMap );
		$a      = new Phase3AdapterFixtureable( null, $mapper );

		/* topSlugByCatId keys are CANONICAL cat ids (post-resolve),
		 * mirroring how topSlugForCategoryId walks gd_categories. */
		$a->seedAll(
			brandLookup:    [],
			categoryLookup: [],
			categoryMap:    [],
			categoryAttrs:  [],
			topSlugByCatId: [
				88 => 'holsters-carry',
				99 => 'optics',
			],
		);
		return $a;
	}

	public function testHolsterAccessoryAttrsWrittenAsAttrSentinels(): void
	{
		$adapter = $this->makeAdapter( [ '200' => 88 ] );
		$raw     = $adapter->normalize( $this->holsterRaw() )->getRaw();

		$this->assertSame( '88', $raw['_CATEGORY_ID'],
			'CATID=200 → CategoryMapper->resolve → _CATEGORY_ID="88".' );

		/* All four ACCESSORY_ATTR_MAP[holsters-carry] outputs must land
		 * as _ATTR_* sentinels so the generic _ATTR_* merge in
		 * Importer::processRecord copies them into $mapped[col]. */
		$this->assertSame( 'IWB',   $raw['_ATTR_holster_type']     ?? null );
		$this->assertSame( 'Black', $raw['_ATTR_holster_color']    ?? null );
		$this->assertSame( 'Kydex', $raw['_ATTR_holster_material'] ?? null );
		$this->assertSame( 'Right', $raw['_ATTR_holster_hand']     ?? null );
	}

	public function testOpticsBranchPatternValidated(): void
	{
		$adapter = $this->makeAdapter( [ '300' => 99 ] );
		$raw     = $adapter->normalize( $this->opticsRaw() )->getRaw();

		$this->assertSame( '4-12x', $raw['_ATTR_optic_magnification'] ?? null,
			'ITATR1="4-12x" matches magnification pattern → _ATTR_optic_magnification.' );
		$this->assertSame( '40mm', $raw['_ATTR_optic_objective'] ?? null,
			'ITATR2="40mm" matches objective pattern → _ATTR_optic_objective.' );
	}

	public function testOpticsBranchRejectsMalformedValues(): void
	{
		$raw = $this->opticsRaw();
		$raw['ITATR1'] = 'variable';   // fails /^[0-9][0-9.\-]*x$/i
		$raw['ITATR2'] = '40 mm';      // fails /^[0-9][0-9.]*mm$/i (space)

		$adapter = $this->makeAdapter( [ '300' => 99 ] );
		$out     = $adapter->normalize( $raw )->getRaw();

		$this->assertArrayNotHasKey( '_ATTR_optic_magnification', $out,
			'Non-pattern-matching magnification must not be written.' );
		$this->assertArrayNotHasKey( '_ATTR_optic_objective', $out,
			'Non-pattern-matching objective must not be written.' );
	}

	public function testAccessorySkippedWhenNoCategoryMapper(): void
	{
		/* Constructor without CategoryMapper — adapter still runs and
		 * enrichment finishes, but the Phase-3-moved category / accessory
		 * branches are guarded off so no _ATTR_holster_* sentinels appear.
		 * That matches how test fixtures / one-off callers can construct
		 * the adapter without the Importer wiring. */
		$adapter = new Phase3AdapterFixtureable( null, null );
		$adapter->seedAll(
			topSlugByCatId: [ 88 => 'holsters-carry' ],
		);
		$raw = $adapter->normalize( $this->holsterRaw() )->getRaw();

		$this->assertArrayNotHasKey( '_ATTR_holster_type',     $raw );
		$this->assertArrayNotHasKey( '_ATTR_holster_color',    $raw );
		$this->assertArrayNotHasKey( '_ATTR_holster_material', $raw );
		$this->assertArrayNotHasKey( '_ATTR_holster_hand',     $raw );
	}

	public function testAccessorySkippedForFirearmTopSlug(): void
	{
		/* Rifle-category top-slug isn't in ACCESSORY_ATTR_MAP →
		 * accessoryAttrs returns []. No holster / optics sentinels
		 * appear even though ITATR* values are present. This is
		 * exactly the pre-Phase-3 behaviour of accessoryAttrsFor. */
		$adapter = new Phase3AdapterFixtureable(
			null,
			new FakeCategoryMapperP3( [ '94' => 42 ] )
		);
		$adapter->seedAll(
			topSlugByCatId: [ 42 => 'rifles' ],
		);

		$rifleRaw = [
			'ITUPC'  => '333333333333',
			'CATID'  => '94',
			'ITATR1' => 'Bolt Action',
			'ITATR2' => '.308 Winchester',
			'SHDESC' => 'ACME Rifle .308',
			'IDESC'  => '',
		];
		$raw = $adapter->normalize( $rifleRaw )->getRaw();

		$this->assertArrayNotHasKey( '_ATTR_holster_type', $raw );
		$this->assertArrayNotHasKey( '_ATTR_optic_magnification', $raw );
	}

	public function testCategoryMapperResolveOverridesSportsSouthCategoryMap(): void
	{
		/* If gd_sportssouth_category_map maps CATID=200 → 77, but the
		 * per-feed category_mapping maps CATID=200 → 88, the second
		 * wins (unconditional override on CATID>0). Pre-Phase-3 this
		 * was Importer::processRecord line 674; Phase 3 moves the
		 * override into the adapter's enrich() tail. */
		$adapter = new Phase3AdapterFixtureable(
			null,
			new FakeCategoryMapperP3( [ '200' => 88 ] )
		);
		$adapter->seedAll(
			categoryMap:    [ '200' => 77 ],  // adapter's own SS-map lookup
			topSlugByCatId: [ 88 => 'holsters-carry', 77 => '' ],
		);
		$raw = $adapter->normalize( $this->holsterRaw() )->getRaw();

		$this->assertSame( '88', $raw['_CATEGORY_ID'],
			'CategoryMapper::resolve override must win over the adapter\'s own sportssouth_category_map lookup.' );
	}

	public function testFirstMatchWinsGuardOnAttrCollision(): void
	{
		/* If _ATTR_holster_type is already non-empty (e.g. from
		 * ATTR_LABEL_MAP upstream — theoretically), the Phase-3
		 * accessory-slot merge preserves it and does not overwrite.
		 * This mirrors the discipline the ATTR_LABEL_MAP path already
		 * uses (adapter line 447). */
		$adapter = $this->makeAdapter( [ '200' => 88 ] );

		$raw = $this->holsterRaw();
		/* Pre-seed a colliding _ATTR sentinel — inject via the raw
		 * input; the adapter must respect it. */
		$rawIn = $raw;

		/* Wrap the adapter's normalize so we can seed the property
		 * mid-run — easier here: put a pre-existing key on the raw
		 * so the adapter sees `_ATTR_holster_type` as already set. */
		$rawIn['_ATTR_holster_type'] = 'PRE_EXISTING';

		$out = $adapter->normalize( $rawIn )->getRaw();

		$this->assertSame( 'PRE_EXISTING', $out['_ATTR_holster_type'],
			'When _ATTR_holster_type is already set, Phase-3 accessory merge must not overwrite.' );
	}
}
