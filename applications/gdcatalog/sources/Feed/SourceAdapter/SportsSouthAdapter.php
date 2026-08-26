<?php
/**
 * @brief       GD Master Catalog — Sports South Source Adapter
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       25 Aug 2026
 *
 * Phase 2 of the source-adapter refactor plan (audit 2026-08-25).
 *
 * WHAT MOVED HERE — verbatim, from Importer.php:
 *   - Importer::enrichSportsSouthRecord() body (was :617-970)
 *   - The four lazy-loaded lookup properties that back it
 *     ($sportsSouthBrandLookup, $sportsSouthCategoryLookup,
 *      $sportsSouthCategoryMap, $sportsSouthCategoryAttrs)
 *   - The SPORTS_SOUTH_ATTR_LABEL_MAP class constant (was :193-240)
 *
 * WHAT DELIBERATELY DID NOT MOVE (Phase 2 keeps these where they
 * are — reorganizing them offers no user-visible value and enlarges
 * this deploy):
 *   - SportsSouthClient::fromDistributor / dailyItemUpdate /
 *     parseTableRows / imageUrlForPicref — all HTTP/XML concerns
 *     remain in the source client. This adapter uses
 *     SportsSouthClient::imageUrlForPicref() the same way Importer
 *     did (static call).
 *   - SportsSouthAttributeMap::resolve — the per-CATID slot→field
 *     lookup remains in its own class. This adapter calls it the
 *     same way Importer did.
 *   - TitleParser::parse / gaugeFromTitle — still called here for
 *     SS enrichment's title-derived fallback, because currently
 *     that call is inside the SS enrichment body. Whether TitleParser
 *     is "SS-only" or "generic" is a Phase 4 question.
 *   - Importer::ACCESSORY_ATTR_MAP + accessoryAttrsFor +
 *     topSlugForCategoryId — these run from the generic
 *     processRecord() pipeline after enrichment (Importer.php:1083-84
 *     reads raw ITATR* keys AFTER this adapter finishes). Moving them
 *     is scope for a later phase, per the Phase 2 prompt's
 *     "Leave These Known Couplings Alone If Necessary" section.
 *   - Importer::refineCategoryByTitle — also runs from processRecord
 *     (line 1077) after enrichment.
 *
 * BOUNDARY (contract):
 *   Input:  one raw parsed Sports South row exactly as
 *           SportsSouthClient::dailyItemUpdate() returns it.
 *   Output: NormalizedRecord whose getRaw() is the enriched raw
 *           payload — same array shape Importer's per-record
 *           downstream pipeline already knows how to consume
 *           (FieldMapper::mapRecord + castTypes + _ATTR_* merge).
 *
 * The adapter does NOT map to canonical fields itself — FieldMapper
 * still runs downstream. The canonical array in the returned DTO is
 * therefore empty; getRaw() carries everything. That preserves
 * exact-current semantics: the raw payload is what
 * Importer::processRecord() receives as $rawRecord today, unchanged.
 *
 * The adapter is STATEFUL — the four lookup properties are lazy-
 * loaded on first normalize() call and reused for every subsequent
 * call in the same PHP request. Instantiate one adapter per import
 * run (as Importer does today).
 */

namespace IPS\gdcatalog\Feed\SourceAdapter;

use IPS\gdcatalog\Feed\Distributor;
use IPS\gdcatalog\Feed\Distributor\SportsSouthAttributeMap;
use IPS\gdcatalog\Feed\Distributor\SportsSouthClient;
use IPS\gdcatalog\Feed\NormalizedRecord;
use IPS\gdcatalog\Feed\TitleParser;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _SportsSouthAdapter implements SourceAdapterInterface
{
	/**
	 * v1.0.20: Sports South attribute label -> canonical column map.
	 * Moved verbatim from Importer::SPORTS_SOUTH_ATTR_LABEL_MAP.
	 * Comparison uses mb_strtolower($label) and looks for any of
	 * these strings as substring matches. First match wins — order
	 * matters (more specific labels before less specific).
	 */
	protected const ATTR_LABEL_MAP = [
		/* Caliber */
		'caliber'         => 'caliber',
		'cartridge'       => 'caliber',
		'gauge'           => 'caliber',

		/* Action type - 'action type' must precede bare 'action' */
		'action type'      => 'action_type',
		'operating system' => 'action_type',
		'action'           => 'action_type',

		/* Lengths - 'barrel length' before bare 'overall length' before 'oal' */
		'barrel length'   => 'barrel_length',
		'bbl length'      => 'barrel_length',
		'barrel len'      => 'barrel_length',
		'overall length'  => 'overall_length',
		'oal'             => 'overall_length',

		/* Capacity */
		'mag capacity'    => 'capacity',
		'capacity'        => 'capacity',
		'rounds'          => 'capacity',

		/* Finish / materials */
		'frame material'  => 'frame_material',
		'stock finish'    => 'finish',
		'barrel finish'   => 'finish',
		'frame finish'    => 'finish',
		'finish'          => 'finish',

		/* v1.0.27 fields */
		'safety'              => 'safety_type',
		'grips'               => 'stock_type',
		'sight configuration' => 'sight_type',
		'sight'               => 'sight_type',
		'slide description'   => 'receiver_type',
		'slide'               => 'receiver_type',
		'weight'              => 'weight_oz',

		/* MUST come last - bare 'type' catches anything else with 'type' in label */
		'type'            => 'gun_type',
	];

	/** Lazy-loaded brand lookup — keyed by brdno (string) => brdnam. */
	protected ?array $brandLookup = null;

	/** Lazy-loaded category lookup — keyed by catid (string) => catdes. */
	protected ?array $categoryLookup = null;

	/** Lazy-loaded Sports South CATID -> gd_categories.id map. */
	protected ?array $categoryMap = null;

	/** Lazy-loaded per-category ATTR label map. See enrichment body. */
	protected ?array $categoryAttrs = null;

	/**
	 * Optional feed context — passed through to the returned
	 * NormalizedRecord so its getSourceFeed()/getSourceKey() are
	 * populated for provenance / logging. Null in test contexts.
	 */
	protected ?Distributor $sourceFeed;

	public function __construct( ?Distributor $sourceFeed = null )
	{
		$this->sourceFeed = $sourceFeed;
	}

	/**
	 * SourceAdapterInterface — enrich the raw Sports South row and
	 * wrap in a NormalizedRecord. The DTO's getRaw() is the enriched
	 * raw payload; the canonical map is empty (FieldMapper still runs
	 * downstream in Importer::processRecord).
	 */
	public function normalize( array $rawRecord ): NormalizedRecord
	{
		$enriched = $this->enrich( $rawRecord );
		return NormalizedRecord::fromMapped( [], $enriched, $this->sourceFeed );
	}

	/**
	 * The verbatim enrichment body moved from Importer::enrichSportsSouthRecord.
	 * Comments and behaviour preserved unchanged. All property references
	 * that were $this->sportsSouthBrandLookup / ...CategoryLookup /
	 * ...CategoryMap / ...CategoryAttrs on Importer are now
	 * $this->brandLookup / ...categoryLookup / ...categoryMap /
	 * ...categoryAttrs on this adapter, but the values loaded, the
	 * queries used, the row shapes produced, and every synthetic key
	 * written into $record are identical.
	 */
	protected function enrich( array $record ): array
	{
		/* Lazy-load brand lookup once per import run */
		if ( $this->brandLookup === null )
		{
			$this->brandLookup = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'brdno, brdnam', 'gd_sportssouth_brands' ) as $brandRow )
				{
					$this->brandLookup[ (string) $brandRow['brdno'] ] = (string) $brandRow['brdnam'];
				}
			}
			catch ( \Throwable ) {}
		}

		/* Lazy-load category lookup once per import run */
		if ( $this->categoryLookup === null )
		{
			$this->categoryLookup = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'catid, catdes', 'gd_sportssouth_categories' ) as $catRow )
				{
					$this->categoryLookup[ (string) $catRow['catid'] ] = (string) $catRow['catdes'];
				}
			}
			catch ( \Throwable ) {}
		}

		/* Resolve brand: try IMFGNO first, fall back to ITBRDNO. If neither
		 * resolves, leave the original IMFGNO value as brand string so admin
		 * has something to work with on review.
		 *
		 * v1.0.27: ALSO track manufacturer separately. If IMFGNO and ITBRDNO
		 * resolve to DIFFERENT names, treat IMFGNO as manufacturer (the actual
		 * maker, e.g. "Miroku") and ITBRDNO as brand (consumer-facing, e.g.
		 * "Browning"). If they're the same, only brand gets set. */
		$mfgKey = (string) ( $record['IMFGNO']  ?? '' );
		$brdKey = (string) ( $record['ITBRDNO'] ?? '' );

		$mfgName = ( $mfgKey !== '' && isset( $this->brandLookup[ $mfgKey ] ) )
			? $this->brandLookup[ $mfgKey ]
			: $mfgKey;

		$brdName = ( $brdKey !== '' && isset( $this->brandLookup[ $brdKey ] ) )
			? $this->brandLookup[ $brdKey ]
			: $brdKey;

		/* Brand: prefer brdName, fall back to mfgName, fall back to empty */
		$brandResolved = $brdName !== '' ? $brdName : $mfgName;
		$record['_BRAND_NAME'] = $brandResolved;

		/* Manufacturer: set only if it differs from the brand */
		if ( $mfgName !== '' && $mfgName !== $brandResolved )
		{
			$record['_MANUFACTURER'] = $mfgName;
		}

		/* v1.0.27: MPN comes directly from MFGINO field (model number assigned
		 * by manufacturer). Sports South puts this in MFGINO. */
		$mfgPartNo = trim( (string) ( $record['MFGINO'] ?? '' ) );
		if ( $mfgPartNo !== '' )
		{
			$record['_MPN'] = $mfgPartNo;
		}

		/* Lazy-load category map once per import run */
		if ( $this->categoryMap === null )
		{
			$this->categoryMap = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'sportssouth_catid, gd_category_id', 'gd_sportssouth_category_map' ) as $mapRow )
				{
					$gdCatId = (int) $mapRow['gd_category_id'];
					if ( $gdCatId > 0 )
					{
						$this->categoryMap[ (string) $mapRow['sportssouth_catid'] ] = $gdCatId;
					}
				}
			}
			catch ( \Throwable ) {}
		}

		/* Resolve category description (informational) */
		$catKey = (string) ( $record['CATID'] ?? '' );
		if ( $catKey !== '' && isset( $this->categoryLookup[ $catKey ] ) )
		{
			$record['_CATEGORY_DESC'] = $this->categoryLookup[ $catKey ];
		}

		/* v1.0.15: Resolve gd_categories.id from Sports South CATID via mapping table.
		 * Inject _CATEGORY_ID for the FieldMapper to pick up. */
		if ( $catKey !== '' && isset( $this->categoryMap[ $catKey ] ) )
		{
			$record['_CATEGORY_ID'] = (string) $this->categoryMap[ $catKey ];
		}

		/* v1.0.20: Extract caliber/action/finish/etc from ITATR slots.
		 *
		 * Sports South stores per-product attribute VALUES in ITATR1..N.
		 * The LABEL for each slot is defined per-category in
		 * gd_sportssouth_categories.raw_data (ATTR1..N).
		 *
		 * For example, category "RIFLES CENTERFIRE" might define:
		 *   ATTR1 = 'Action Type'
		 *   ATTR2 = 'Caliber'
		 *   ATTR3 = 'Barrel Length'
		 *
		 * And a product in that category has:
		 *   ITATR1 = 'Bolt Action'
		 *   ITATR2 = '.308 Winchester'
		 *   ITATR3 = '24"'
		 *
		 * We match labels (case-insensitive substring) to canonical
		 * column names via ATTR_LABEL_MAP. Each matched
		 * value gets injected as _CALIBER, _ACTION_TYPE, etc. */
		if ( $this->categoryAttrs === null )
		{
			$this->categoryAttrs = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'catid, raw_data', 'gd_sportssouth_categories' ) as $catRow )
				{
					$rawJson = (string) ( $catRow['raw_data'] ?? '' );
					if ( $rawJson === '' )
					{
						continue;
					}
					$decoded = json_decode( $rawJson, true );
					if ( !is_array( $decoded ) )
					{
						continue;
					}

					$attrMap = [];
					/* ATTR0 through ATTR20 - capture all attribute label slots.
					 * Slot number is parsed from the key (ATTR0, ATTR1, etc). */
					foreach ( $decoded as $key => $value )
					{
						if ( preg_match( '/^ATTR(\d+)$/i', (string) $key, $m ) )
						{
							$slot = (int) $m[1];
							$label = trim( (string) $value );
							if ( $label !== '' )
							{
								$attrMap[ $slot ] = $label;
							}
						}
					}

					if ( !empty( $attrMap ) )
					{
						$this->categoryAttrs[ (string) $catRow['catid'] ] = $attrMap;
					}
				}
			}
			catch ( \Throwable ) {}
		}

		/* Walk this product's category attribute labels and extract values */
		if ( $catKey !== '' && isset( $this->categoryAttrs[ $catKey ] ) )
		{
			$labelMap = $this->categoryAttrs[ $catKey ];

			foreach ( $labelMap as $slot => $label )
			{
				/* Build the ITATR key for this slot. Sports South uses ITATR0,
				 * ITATR1, etc paralleling the ATTR0..N labels. */
				$itatrKey = 'ITATR' . $slot;
				$itatrValue = trim( (string) ( $record[ $itatrKey ] ?? '' ) );
				if ( $itatrValue === '' )
				{
					continue;
				}

				/* Match label against the known label->column map.
				 * Case-insensitive substring match. First match wins. */
				$labelLower = mb_strtolower( $label );
				$targetColumn = null;
				foreach ( self::ATTR_LABEL_MAP as $needle => $col )
				{
					if ( str_contains( $labelLower, $needle ) )
					{
						$targetColumn = $col;
						break;
					}
				}

				if ( $targetColumn === null )
				{
					continue;
				}

				/* Apply per-column value parsing */
				$parsedValue = $itatrValue;
				if ( $targetColumn === 'barrel_length' )
				{
					/* Strip non-numeric chars except dot, take first match.
					 * "16\"" -> 16, "20.5\"" -> 20.5, "16 in" -> 16 */
					if ( preg_match( '/(\d+(?:\.\d+)?)/', $itatrValue, $m ) )
					{
						$parsedValue = $m[1];
					}
					else
					{
						continue;
					}
				}
				elseif ( $targetColumn === 'capacity' )
				{
					/* Parse first integer. "30+1" -> 30, "5+1" -> 5, "10" -> 10 */
					if ( preg_match( '/(\d+)/', $itatrValue, $m ) )
					{
						$parsedValue = $m[1];
					}
					else
					{
						continue;
					}
				}
				elseif ( $targetColumn === 'overall_length' )
				{
					/* v1.0.27: Same parser as barrel_length. "47.5\"" -> 47.5 */
					if ( preg_match( '/(\d+(?:\.\d+)?)/', $itatrValue, $m ) )
					{
						$parsedValue = $m[1];
					}
					else
					{
						continue;
					}
				}
				elseif ( $targetColumn === 'weight_oz' )
				{
					/* v1.0.27: Parse weight, convert to oz if needed.
					 * "22.4 oz" -> 22.4
					 * "1.4 lbs" -> 22.4 (1.4 * 16)
					 * "0.85 lb" -> 13.6
					 * "20" -> 20 (assume oz when unit absent)
					 *
					 * Sports South ATTR9 for pistols is typically oz already
					 * but rifles use lbs - support both. */
					if ( preg_match( '/(\d+(?:\.\d+)?)\s*(oz|lbs?|pounds?)?/i', $itatrValue, $m ) )
					{
						$numericVal = (float) $m[1];
						$unit = strtolower( trim( $m[2] ?? '' ) );

						if ( $unit === 'lb' || $unit === 'lbs' || $unit === 'pound' || $unit === 'pounds' )
						{
							$numericVal *= 16.0;
						}

						$parsedValue = number_format( $numericVal, 2, '.', '' );
					}
					else
					{
						continue;
					}
				}

				/* Inject as synthetic field. Uppercase for consistency
				 * with _BRAND_NAME, _CATEGORY_ID patterns. */
				$syntheticKey = '_' . strtoupper( $targetColumn );

				/* First match wins - don't overwrite if already set.
				 * (Some categories may have e.g. 'Stock Finish' AND
				 * 'Barrel Finish' both mapping to finish.) */
				if ( !isset( $record[ $syntheticKey ] ) )
				{
					$record[ $syntheticKey ] = $parsedValue;
				}
			}
		}

		/* Transform PICREF to full image URL. */
		$picref = (string) ( $record['PICREF'] ?? '' );
		$itemno = (string) ( $record['ITEMNO'] ?? '' );
		if ( $picref !== '' || $itemno !== '' )
		{
			$record['PICREF'] = SportsSouthClient::imageUrlForPicref( $picref, $itemno );
		}

		/* Extract ALL ITATR attributes using category-aware position map */
		$ssCatId = (int) ( $record['CATID'] ?? 0 );
		if ( $ssCatId > 0 )
		{
			for ( $i = 1; $i <= 20; $i++ )
			{
				$itatrKey = $i === 10 ? 'ITATR0' : 'ITATR' . $i;
				$val = trim( (string) ( $record[ $itatrKey ] ?? '' ) );
				if ( $val === '' )
				{
					continue;
				}
				$col = SportsSouthAttributeMap::resolve( $ssCatId, $i );
				if ( $col !== null )
				{
					if ( $col === 'features' && isset( $record['_ATTR_features'] ) && $record['_ATTR_features'] !== '' )
					{
						$record['_ATTR_features'] .= ', ' . $val;
					}
					else
					{
						$record[ '_ATTR_' . $col ] = $val;
					}
				}
			}
		}

		/* Map top-level fields to canonical columns */
		if ( !empty( $record['SERIES'] ) )  $record['_ATTR_features']       = trim( ( $record['_ATTR_features'] ?? '' ) . ' ' . $record['SERIES'] );
		if ( !empty( $record['LENGTH'] ) )  $record['_ATTR_overall_length'] = (string) $record['LENGTH'];
		if ( !empty( $record['WTPBX'] ) )   $record['_ATTR_weight_lbs']     = (string) $record['WTPBX'];
		if ( !empty( $record['IMODEL'] ) )  $record['_ATTR_model']          = (string) $record['IMODEL'];
		if ( !empty( $record['MFGINO'] ) )  $record['_ATTR_mpn']            = (string) $record['MFGINO'];

		/* Fallback: parse title/description for missing attributes */
		$titleParseInput = [];
		foreach ( $record as $k => $v )
		{
			if ( str_starts_with( (string) $k, '_ATTR_' ) && $v !== '' )
			{
				$titleParseInput[ substr( $k, 6 ) ] = $v;
			}
		}
		$sTitle = (string) ( $record['SHDESC'] ?? '' );
		$sDesc  = (string) ( $record['IDESC'] ?? '' );
		if ( $sTitle !== '' || $sDesc !== '' )
		{
			$canonicalCatId = isset( $record['_CATEGORY_ID'] ) ? (int) $record['_CATEGORY_ID'] : 0;
			$parsed = TitleParser::parse( $sTitle, $sDesc, $canonicalCatId, $titleParseInput );
			foreach ( $parsed as $col => $val )
			{
				if ( !isset( $record[ '_ATTR_' . $col ] ) || $record[ '_ATTR_' . $col ] === '' )
				{
					$record[ '_ATTR_' . $col ] = $val;
				}
			}
		}

		/* Shotgun gauge in the title is authoritative for caliber — override any attribute-derived
		 * value (Sports South puts shot size in the Caliber attribute for shotshells). */
		if ( $sTitle !== '' || $sDesc !== '' )
		{
			$forcedGauge = TitleParser::gaugeFromTitle( $sTitle . ' ' . $sDesc );
			if ( $forcedGauge !== null )
			{
				$record['_ATTR_caliber'] = $forcedGauge;
			}
		}

		return $record;
	}
}

class SportsSouthAdapter extends _SportsSouthAdapter {}
