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
 * The interface is deliberately kept to one method. When Phase 3+
 * introduces a second real adapter and a factory/registry, the
 * contract can grow (a static `supports(Distributor): bool` and a
 * `getSourceKey(): string` are the obvious next additions). Not
 * needed yet — Importer instantiates SportsSouthAdapter directly
 * in this phase, exactly where it currently instantiates
 * SportsSouthClient in the sportssouth branch of fetchFeed().
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
