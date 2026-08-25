<?php
/**
 * @brief       GD Master Catalog — NormalizedRecord DTO
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       25 Aug 2026
 *
 * Source-neutral in-memory representation of one catalog record on
 * its way through the importer. Phase 1 of the source-adapter
 * refactor (see audit 2026-08-25).
 *
 * PHASE 1 SCOPE — this class is a THIN WRAPPER only:
 *   - constructed from the mapped canonical array + original raw
 *     source record + source feed row,
 *   - toArray() re-exposes the canonical array so the existing
 *     downstream pipeline in Importer::processRecord() is
 *     unchanged,
 *   - carries source identity + raw payload for provenance so
 *     later phases can push more of processRecord into typed
 *     consumers of this DTO.
 *
 * Intentionally NOT in this class:
 *   - Sports South-specific field names (CATID, BRDNO, PICREF,
 *     ITATR*, IMFGNO, ITBRDNO, MFGINO, SHDESC, IDESC, etc.) —
 *     those remain raw-source concerns on the $raw payload.
 *   - Any per-source enrichment behaviour.
 *   - Any transformation / mutation of the mapped array
 *     (Phase 1 is a wrap-and-expose seam only).
 *   - Any persistence hooks (raw_distributor_data storage stays
 *     with the Product create/update paths for now).
 *
 * The class deliberately holds the canonical map as a plain
 * associative array rather than typed properties. Typed getters
 * for individual canonical fields can be added in Phase 2 once
 * consumers start reading via the DTO instead of via toArray().
 * That keeps Phase 1's behavioural surface area at zero.
 */

namespace IPS\gdcatalog\Feed;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _NormalizedRecord
{
	/**
	 * Canonical field=>value map, as produced by FieldMapper::mapRecord()
	 * + FieldMapper::castTypes() + the enrichment _ATTR_* merge in
	 * Importer::processRecord(). Downstream (UPC extraction, category
	 * resolution, product create/update, ConflictResolver) reads this
	 * shape — Phase 1 does not change it.
	 *
	 * @var array<string, mixed>
	 */
	protected array $canonical;

	/**
	 * Raw source record exactly as it arrived from the source (post-parse
	 * but including any per-source enrichment keys such as `_ATTR_*`,
	 * `_BRAND_NAME`, `_MANUFACTURER`, `_MPN`, plus every raw
	 * source-specific key the source ships). Retained for provenance
	 * and for source-aware code paths that still read raw keys
	 * (e.g. Importer's per-category accessory attribute lookup).
	 *
	 * @var array<string, mixed>
	 */
	protected array $raw;

	/**
	 * The source feed row this record was fetched from, when known.
	 * NULL is tolerated for call sites that construct the DTO before a
	 * feed context is available (unit tests, ad-hoc mapping utilities).
	 */
	protected ?Distributor $sourceFeed;

	/**
	 * @param array<string, mixed> $canonical  Mapped canonical field=>value
	 * @param array<string, mixed> $raw        Original raw source record
	 * @param Distributor|null     $sourceFeed Feed context, when known
	 */
	public function __construct( array $canonical, array $raw = [], ?Distributor $sourceFeed = null )
	{
		$this->canonical  = $canonical;
		$this->raw        = $raw;
		$this->sourceFeed = $sourceFeed;
	}

	/**
	 * Static factory — reads better at call sites than `new`.
	 *
	 * @param array<string, mixed> $canonical
	 * @param array<string, mixed> $raw
	 */
	public static function fromMapped( array $canonical, array $raw = [], ?Distributor $sourceFeed = null ): self
	{
		return new self( $canonical, $raw, $sourceFeed );
	}

	/**
	 * Return the canonical field=>value map. Phase 1 downstream code
	 * calls this to keep processing on the existing array shape.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return $this->canonical;
	}

	/**
	 * Return the original raw source record.
	 *
	 * @return array<string, mixed>
	 */
	public function getRaw(): array
	{
		return $this->raw;
	}

	/**
	 * Return the source feed row this record was fetched from, or null.
	 */
	public function getSourceFeed(): ?Distributor
	{
		return $this->sourceFeed;
	}

	/**
	 * Return a stable identifier for the source (the feed's distributor
	 * slug — e.g. "sports_south", "rsr_group") for provenance / logging.
	 * Returns null if no feed context was supplied.
	 */
	public function getSourceKey(): ?string
	{
		if ( $this->sourceFeed === null )
		{
			return null;
		}
		try
		{
			$slug = (string) $this->sourceFeed->distributor;
			return $slug !== '' ? $slug : null;
		}
		catch ( \Throwable )
		{
			return null;
		}
	}
}

class NormalizedRecord extends _NormalizedRecord {}
