<?php
/**
 * @brief       NormalizedRecord Regression Test — Phase 1 Wrap+Expose Seam
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       25 Aug 2026
 *
 * Verifies the Phase 1 refactor seam is behaviour-preserving:
 *   - Wrapping a Sports-South-shaped mapped canonical array in
 *     NormalizedRecord and immediately calling toArray() yields
 *     the same array, byte-for-byte.
 *   - Raw source data is retained separately (getRaw()) and
 *     unaltered.
 *   - No source-neutral field on the DTO exposes Sports South
 *     raw field names.
 *
 * Runs standalone — does not require IPS bootstrap. Only asserts
 * against the DTO's own public surface.
 */

namespace IPS\gdcatalog\tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../gdcatalog/sources/Feed/NormalizedRecord.php';

class NormalizedRecordTest extends TestCase
{
	/**
	 * The exact shape produced by Importer::processRecord() at the
	 * point NormalizedRecord is instantiated: canonical field=>value
	 * after FieldMapper::mapRecord() + castTypes() + the _ATTR_*
	 * enrichment merge.
	 */
	protected function sampleMapped(): array
	{
		return [
			'upc'          => '787450518001',
			'title'        => 'Sample Firearm 5.56 NATO',
			'brand'        => 'ACME Arms',
			'manufacturer' => 'ACME',
			'mpn'          => 'SF-556-001',
			'description'  => 'A sample rifle for testing.',
			'msrp'         => 899.99,
			'image_url'    => 'https://example.com/sample.jpg',
			'caliber'      => '5.56 NATO',
			'action_type'  => 'semi-automatic',
			'requires_ffl' => 1,
			'nfa_item'     => 0,
			'is_ammo'      => 0,
			'barrel_length' => 16.0,
			'capacity'     => 30,
		];
	}

	/**
	 * The exact raw shape a Sports South record has at that same
	 * point, including SS raw fields and the enrichment sentinel
	 * keys the DTO must NOT strip or normalize away.
	 */
	protected function sampleRaw(): array
	{
		return [
			'ITUPC'        => '787450518001',
			'IDESC'        => 'Sample Firearm',
			'SHDESC'       => 'ACME SF-556 5.56',
			'CATID'        => 42,
			'BRDNO'        => 17,
			'IMFGNO'       => 'ACME',
			'ITBRDNO'      => 'ACME Arms',
			'MFGINO'       => 'SF-556-001',
			'PICREF'       => 'SF556001',
			'ITATR1'       => 'semi-automatic',
			'ITATR2'       => '5.56 NATO',
			'ITATR3'       => '16"',
			'ITATR4'       => '30',
			'_ATTR_action_type'   => 'semi-automatic',
			'_ATTR_caliber'       => '5.56 NATO',
			'_ATTR_barrel_length' => '16"',
			'_ATTR_capacity'      => '30',
			'_BRAND_NAME'  => 'ACME Arms',
			'_MANUFACTURER'=> 'ACME',
			'_MPN'         => 'SF-556-001',
		];
	}

	public function testToArrayReturnsExactCanonicalMap(): void
	{
		$mapped = $this->sampleMapped();
		$raw    = $this->sampleRaw();

		$dto    = \IPS\gdcatalog\Feed\NormalizedRecord::fromMapped( $mapped, $raw );
		$roundtrip = $dto->toArray();

		$this->assertSame( $mapped, $roundtrip,
			'NormalizedRecord::toArray() must return the canonical array byte-for-byte.' );
	}

	public function testRawIsRetainedSeparately(): void
	{
		$mapped = $this->sampleMapped();
		$raw    = $this->sampleRaw();

		$dto = \IPS\gdcatalog\Feed\NormalizedRecord::fromMapped( $mapped, $raw );

		$this->assertSame( $raw, $dto->getRaw(),
			'getRaw() must return the raw source record unaltered.' );
	}

	public function testConstructorAndFactoryEquivalent(): void
	{
		$mapped = $this->sampleMapped();
		$raw    = $this->sampleRaw();

		$viaCtor    = new \IPS\gdcatalog\Feed\NormalizedRecord( $mapped, $raw );
		$viaFactory = \IPS\gdcatalog\Feed\NormalizedRecord::fromMapped( $mapped, $raw );

		$this->assertSame( $viaCtor->toArray(), $viaFactory->toArray() );
		$this->assertSame( $viaCtor->getRaw(),  $viaFactory->getRaw() );
	}

	public function testSourceFeedNullByDefault(): void
	{
		$dto = \IPS\gdcatalog\Feed\NormalizedRecord::fromMapped( [], [] );
		$this->assertNull( $dto->getSourceFeed() );
		$this->assertNull( $dto->getSourceKey() );
	}

	public function testEmptyPayloadRoundtrips(): void
	{
		$dto = \IPS\gdcatalog\Feed\NormalizedRecord::fromMapped( [], [] );
		$this->assertSame( [], $dto->toArray() );
		$this->assertSame( [], $dto->getRaw() );
	}

	public function testDtoPublicSurfaceContainsNoSportsSouthFieldNames(): void
	{
		$rc = new \ReflectionClass( \IPS\gdcatalog\Feed\NormalizedRecord::class );
		$publicMethods = array_map( fn( $m ) => $m->getName(), $rc->getMethods( \ReflectionMethod::IS_PUBLIC ) );
		$forbidden = [ 'CATID', 'BRDNO', 'PICREF', 'ITATR', 'IMFGNO', 'ITBRDNO', 'MFGINO', 'SportsSouth' ];
		foreach ( $publicMethods as $m )
		{
			foreach ( $forbidden as $token )
			{
				$this->assertStringNotContainsStringIgnoringCase( $token, $m,
					"Public method {$m} contains SS-specific token {$token} — Phase 1 DTO must stay source-neutral." );
			}
		}
	}
}
