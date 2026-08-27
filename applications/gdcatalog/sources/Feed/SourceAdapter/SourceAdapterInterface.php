<?php
/**
 * @brief       GD Master Catalog — Source Adapter Contract
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       25 Aug 2026
 *
 * Phase 2 of the source-adapter refactor plan (audit 2026-08-25).
 * Defines the boundary between source-specific interpretation of a
 * raw record and the generic catalog pipeline downstream.
 *
 * SCOPE — intentionally minimal:
 *
 *   raw source record
 *          ↓
 *   normalize()
 *          ↓
 *   NormalizedRecord
 *          ↓
 *   FieldMapper::mapRecord / castTypes / _ATTR_* merge  (unchanged)
 *          ↓
 *   generic Importer pipeline                            (unchanged)
 *
 * An adapter is responsible for source-specific enrichment /
 * interpretation of one already-parsed raw source row. It transforms
 * source-specific keys (SS: CATID, BRDNO, PICREF, ITATR*, IMFGNO,
 * ITBRDNO, MFGINO) into a form the generic downstream code already
 * understands — most importantly by populating the sentinel keys
 * (_ATTR_*, _BRAND_NAME, _MANUFACTURER, _MPN, _CATEGORY_ID,
 * _CATEGORY_DESC) that FieldMapper::mapRecord() + the enrichment
 * merge in Importer::processRecord() already know how to consume.
 *
 * An adapter MUST NOT:
 *
 *   - fetch data over the network (that's the source client's job,
 *     e.g. SportsSouthClient, and stays where it is)
 *   - parse XML / JSON / CSV (the Parser/ classes own that)
 *   - create or update products
 *   - write conflict records
 *   - invoke OpenSearch or any indexer
 *   - perform discontinuation processing
 *   - manipulate raw_distributor_data on gd_catalog
 *   - own task or queue execution
 *   - own AdminCP controllers or routes
 *
 * The interface is deliberately kept to one method. Phase 4 added a
 * second real adapter (StructuredFeedAdapter) and a small explicit
 * source dispatch (Importer::resolveAdapter) that switches on the
 * Distributor's existing auth_type — no factory or registry, because
 * the codebase currently has exactly two adapter kinds and a third
 * only extends the switch by one line. A future `supports(Distributor)`
 * static and a `getSourceKey(): string` remain the obvious next
 * additions when a third source ships.
 *
 * TWO CURRENT ADAPTER SHAPES — for normalize()'s NormalizedRecord:
 *   - SportsSouthAdapter (Phase 2): canonical map EMPTY, raw ENRICHED
 *     with sentinels (_BRAND_NAME, _MANUFACTURER, _MPN, _CATEGORY_ID,
 *     _ATTR_*). FieldMapper runs in the Importer's SS legacy
 *     processRecord path, downstream. This shape defers mapping to
 *     the caller.
 *   - StructuredFeedAdapter (Phase 4): canonical map POPULATED
 *     (FieldMapper::mapRecord + castTypes done in the adapter),
 *     raw UNTOUCHED. This shape does mapping inside the adapter.
 *
 * BOTH shapes reach Importer::processNormalizedRecord as the shared
 * generic-catalog tail. Mapping runs exactly once per record for
 * either path.
 */

namespace IPS\gdcatalog\Feed\SourceAdapter;

use IPS\gdcatalog\Feed\NormalizedRecord;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

interface SourceAdapterInterface
{
	/**
	 * Enrich one raw source-parsed record with source-specific
	 * interpretation and wrap the result in a NormalizedRecord.
	 *
	 * The returned NormalizedRecord's getRaw() must yield an enriched
	 * raw payload suitable for downstream generic processing
	 * (FieldMapper::mapRecord + castTypes + the _ATTR_* merge in
	 * Importer::processRecord). Its getSourceFeed() carries the feed
	 * context when known.
	 *
	 * Implementations are expected to be idempotent — calling
	 * normalize() twice on the same input must yield an equivalent
	 * NormalizedRecord (aside from any lazy-loaded caches).
	 *
	 * @param  array<string, mixed> $rawRecord  Raw parsed source row
	 * @return NormalizedRecord
	 */
	public function normalize( array $rawRecord ): NormalizedRecord;
}
