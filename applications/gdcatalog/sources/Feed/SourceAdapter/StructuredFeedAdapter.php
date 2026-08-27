<?php
/**
 * @brief       GD Master Catalog — Generic Structured Feed Source Adapter
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       27 Aug 2026
 *
 * Phase 4 of the source-adapter refactor plan (audit 2026-08-25).
 *
 * WHAT THIS ADAPTER DOES
 *   Wraps the generic structured-feed path (CSV / JSON / XML / manual
 *   upload / HTTP / FTP — anything that lands as an already-parsed
 *   associative array of raw source fields) in the same
 *   SourceAdapterInterface contract SportsSouthAdapter uses. This is
 *   the second concrete adapter and closes the dispatch shape opened
 *   by Phase 2.
 *
 *   The adapter runs the per-feed FieldMapper against the raw record —
 *   mapRecord + castTypes — and returns a NormalizedRecord whose
 *   canonical array is the mapped/cast result and whose raw payload is
 *   the original untouched parsed record. Importer's downstream
 *   generic processing (see Importer::processNormalizedRecord) consumes
 *   that canonical map without re-running FieldMapper, so a record is
 *   mapped and cast exactly once per import — Step 4 of the Phase 4
 *   prompt.
 *
 * WHAT THIS ADAPTER DELIBERATELY DOES NOT DO
 *   - Fetch data over the network (owned by Importer::fetchFeed:
 *     HTTP / FTP / manual_upload — unchanged).
 *   - Parse XML / JSON / CSV (owned by
 *     IPS\gdcatalog\Feed\Parser\XmlParser|JsonParser|CsvParser —
 *     unchanged).
 *   - Load / create / update Product records.
 *   - Invoke ConflictResolver.
 *   - Write compliance flags.
 *   - Queue OpenSearch reindex.
 *   - Process discontinuations.
 *   - Perform ImportLog writes.
 *   - Interpret Sports South-specific fields — CATID, ITATR*, BRDNO,
 *     PICREF, IMFGNO, ITBRDNO, MFGINO, or any other SS-only field
 *     name.  Zero SS coupling is a Phase-4 acceptance requirement.
 *
 * CONFIGURATION SOURCE OF TRUTH
 *   Per-feed field_mapping and category_mapping JSON columns live on
 *   `gd_distributor_feeds` (the Distributor model). The Importer that
 *   owns the adapter constructs a FieldMapper from
 *   $feed->field_mapping and injects it here. CategoryMapper
 *   resolution runs downstream in
 *   Importer::processNormalizedRecord (via
 *   $mapped['category'] → categoryMapper->map, exactly as pre-Phase-4),
 *   so this adapter does NOT need a CategoryMapper reference to
 *   produce a correct canonical map.
 *
 * SENTINEL KEYS
 *   The generic `_ATTR_<col>` merge in Importer::processNormalizedRecord
 *   still runs against the raw payload, so a generic feed that ships
 *   pre-computed sentinels (e.g. a distributor that publishes an
 *   `_ATTR_caliber` column) benefits from the same convergence
 *   channel Sports South uses. This adapter does not itself synthesise
 *   sentinels — that's what a source-specific adapter is for.
 *
 * IDEMPOTENCY
 *   normalize() is a pure function of ($rawRecord, $fieldMapper). Two
 *   calls on the same input yield equivalent NormalizedRecord values.
 *   The adapter holds no per-record mutable state.
 */

namespace IPS\gdcatalog\Feed\SourceAdapter;

use IPS\gdcatalog\Feed\Distributor;
use IPS\gdcatalog\Feed\FieldMapper;
use IPS\gdcatalog\Feed\NormalizedRecord;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _StructuredFeedAdapter implements SourceAdapterInterface
{
	/**
	 * The feed context that owns this adapter instance. Passed through
	 * to the returned NormalizedRecord so downstream provenance
	 * (getSourceFeed / getSourceKey) is populated. Null in test
	 * contexts that don't need feed identity.
	 */
	protected ?Distributor $sourceFeed;

	/**
	 * Per-feed FieldMapper — constructed from Distributor->field_mapping
	 * by the Importer that owns this adapter. Null in test contexts
	 * that don't exercise mapping (adapter returns a NormalizedRecord
	 * with an empty canonical map in that case, matching the
	 * SportsSouthAdapter "no mapping — defer to caller" contract).
	 */
	protected ?FieldMapper $fieldMapper;

	public function __construct( ?Distributor $sourceFeed = null, ?FieldMapper $fieldMapper = null )
	{
		$this->sourceFeed  = $sourceFeed;
		$this->fieldMapper = $fieldMapper;
	}

	/**
	 * SourceAdapterInterface — map a generic parsed source record into
	 * the canonical field=>value shape and wrap in NormalizedRecord.
	 *
	 * The returned DTO carries:
	 *   - canonical: mapped/cast values (FieldMapper::mapRecord +
	 *                castTypes) if a FieldMapper was injected; empty
	 *                array otherwise (caller-mapping compatibility).
	 *   - raw:       the ORIGINAL parsed source record, untouched.
	 *                Retained for provenance and for the generic
	 *                `_ATTR_*` merge downstream.
	 *   - sourceFeed: the Distributor row this record came from.
	 *
	 * @param array<string, mixed> $rawRecord
	 */
	public function normalize( array $rawRecord ): NormalizedRecord
	{
		$mapped = [];
		if ( $this->fieldMapper !== null )
		{
			$mapped = $this->fieldMapper->mapRecord( $rawRecord );
			$mapped = FieldMapper::castTypes( $mapped );
		}

		return NormalizedRecord::fromMapped( $mapped, $rawRecord, $this->sourceFeed );
	}
}

class StructuredFeedAdapter extends _StructuredFeedAdapter {}
