<?php
/**
 * @brief       GD Master Catalog — Field Mapper
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Translates distributor feed field names to canonical gd_catalog column
 * names using the per-distributor field_mapping JSON stored in
 * gd_distributor_feeds.
 *
 * Field mapping JSON format (stored per feed):
 * {
 *     "PROD_NAME": "title",
 *     "MFG_NAME":  "brand",
 *     "UPC_CODE":  "upc",
 *     "DESC":      "description",
 *     ...
 * }
 */

namespace IPS\gdcatalog\Feed;

/* To prevent PHP errors (extending class does not exist) revealing path */

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}
class FieldMapper
{
	/**
	 * @brief Mapping: distributor field name => canonical column name
	 */
	protected array $map = [];

	/**
	 * @brief Reverse mapping: canonical column => distributor field name
	 */
	protected array $reverseMap = [];

	/**
	 * @brief Canonical fields that are valid gd_catalog columns.
	 *        Documentation only — the actual import-time filter is
	 *        Importer::catalogColumns() which reads live from
	 *        schema.json. Kept in sync so downstream references
	 *        (Review Queue CSV export + manual-upload default
	 *        field_mapping) can generate correct column sets.
	 *
	 *        v1.0.141: expanded with firearm/shotgun/ammo/optic
	 *        detail columns that were already present in
	 *        gd_catalog but missing from this list — the AI
	 *        enrichment round-trip could not fill them because
	 *        neither the CSV export nor the default field_mapping
	 *        included them.
	 */
	public const VALID_FIELDS = [
		/* identity */
		'upc', 'title', 'brand', 'model', 'mpn',
		'category', 'subcategory',
		/* common attributes */
		'caliber', 'action_type', 'barrel_length', 'capacity', 'finish',
		'weight_oz', 'weight_lbs', 'overall_length',
		'msrp', 'description', 'image_url', 'additional_images', 'features',
		/* flags */
		'nfa_item', 'requires_ffl', 'is_ammo',
		/* firearm-specific */
		'gun_type', 'safety_type', 'trigger_type',
		'metal_finish', 'frame_finish',
		'stock_material', 'stock_type',
		'sight_type', 'grips', 'hammer_style',
		'receiver_type', 'receiver_desc', 'frame_material', 'slide_material',
		/* shotgun-specific */
		'gauge', 'choke_config', 'chamber',
		/* ammo-specific */
		'rounds_per_box', 'bullet_type', 'bullet_weight',
		'muzzle_velocity', 'muzzle_energy', 'boxes_per_case', 'casing_material',
		/* optic-specific */
		'magnification', 'objective_mm', 'reticle', 'tube_diameter', 'eye_relief',
		/* v1.0.142 UPC/identity audit columns — populated automatically
		 * for structural failures + by AI enrichment via CSV round-trip */
		'upc_audit_status', 'upc_audit_notes', 'suggested_correct_upc',
		'verified_mpn', 'upc_audit_source',
	];

	/**
	 * @brief Compliance-related field names that auto-detect in feeds.
	 *        Per Section 2.10.4 — standard names for state restriction detection.
	 */
	public const COMPLIANCE_AUTO_DETECT = [
		'restricted_states',
		'no_ship_to',
		'blocked_states',
		'ship_restriction',
		'state_restrictions',
	];

	/**
	 * Constructor.
	 *
	 * @param  string|null $mappingJson  JSON from gd_distributor_feeds.field_mapping
	 */
	public function __construct( ?string $mappingJson )
	{
		if ( $mappingJson === null || $mappingJson === '' )
		{
			return;
		}

		$decoded = json_decode( $mappingJson, true );
		if ( !\is_array( $decoded ) )
		{
			return;
		}

		foreach ( $decoded as $distField => $canonical )
		{
			$canonical = trim( $canonical );
			if ( $canonical !== '' )
			{
				$this->map[ $distField ]          = $canonical;
				$this->reverseMap[ $canonical ]    = $distField;
			}
		}
	}

	/**
	 * Map a single feed record to canonical field names.
	 * Returns only fields that have a valid mapping.
	 *
	 * @param  array<string, mixed> $feedRecord  Raw key-value pairs from feed
	 * @return array<string, mixed>  Canonical field => value
	 */
	public function mapRecord( array $feedRecord ): array
	{
		$mapped = [];

		foreach ( $feedRecord as $distField => $value )
		{
			if ( isset( $this->map[ $distField ] ) )
			{
				$canonical = $this->map[ $distField ];
				$mapped[ $canonical ] = $value;
			}
		}

		return $mapped;
	}

	/**
	 * Extract the UPC value from a feed record.
	 *
	 * @param  array<string, mixed> $feedRecord
	 * @return string|null
	 */
	public function extractUpc( array $feedRecord ): ?string
	{
		$mapped = $this->mapRecord( $feedRecord );
		$upc    = $mapped['upc'] ?? null;

		if ( $upc === null || trim( (string) $upc ) === '' )
		{
			return null;
		}

		/* Normalise: strip leading zeros, remove non-numeric chars */
		$upc = preg_replace( '/[^0-9]/', '', trim( (string) $upc ) );
		return $upc !== '' ? $upc : null;
	}

	/**
	 * Detect compliance field names present in a feed record.
	 * Checks both mapped names and auto-detect standard names.
	 *
	 * @param  array<string, mixed> $feedRecord
	 * @return array<string, string>  field_name => raw value
	 */
	public function detectComplianceFields( array $feedRecord ): array
	{
		$found = [];

		/* Check auto-detect standard names directly in the raw record */
		foreach ( self::COMPLIANCE_AUTO_DETECT as $stdName )
		{
			if ( isset( $feedRecord[ $stdName ] ) && trim( (string) $feedRecord[ $stdName ] ) !== '' )
			{
				$found[ $stdName ] = trim( (string) $feedRecord[ $stdName ] );
			}
		}

		/* Also check mapped compliance fields */
		foreach ( $this->map as $distField => $canonical )
		{
			if (
				\in_array( $canonical, self::COMPLIANCE_AUTO_DETECT, true )
				&& isset( $feedRecord[ $distField ] )
				&& trim( (string) $feedRecord[ $distField ] ) !== ''
			)
			{
				$found[ $canonical ] = trim( (string) $feedRecord[ $distField ] );
			}
		}

		return $found;
	}

	/**
	 * Cast mapped values to their expected PHP types.
	 *
	 * @param  array<string, mixed> $mapped  Output from mapRecord()
	 * @return array<string, mixed>
	 */
	public static function castTypes( array $mapped ): array
	{
		$intFields   = [ 'capacity', 'rounds_per_box' ];
		$floatFields = [ 'barrel_length', 'weight_oz', 'overall_length', 'msrp' ];
		$boolFields  = [ 'nfa_item', 'requires_ffl', 'is_ammo' ];

		foreach ( $intFields as $f )
		{
			if ( isset( $mapped[$f] ) )
			{
				$mapped[$f] = ( $mapped[$f] !== '' && $mapped[$f] !== null )
					? (int) $mapped[$f]
					: null;
			}
		}

		foreach ( $floatFields as $f )
		{
			if ( isset( $mapped[$f] ) )
			{
				$mapped[$f] = ( $mapped[$f] !== '' && $mapped[$f] !== null )
					? (float) $mapped[$f]
					: null;
			}
		}

		foreach ( $boolFields as $f )
		{
			if ( isset( $mapped[$f] ) )
			{
				$mapped[$f] = \in_array(
					mb_strtolower( trim( (string) $mapped[$f] ) ),
					[ '1', 'true', 'yes', 'y' ],
					true
				) ? 1 : 0;
			}
		}

		/* Trim all string fields */
		foreach ( $mapped as $k => $v )
		{
			if ( \is_string( $v ) )
			{
				$mapped[$k] = trim( $v );
			}
		}

		return $mapped;
	}

	/**
	 * Get the mapping array (for display in ACP).
	 *
	 * @return array<string, string>
	 */
	public function getMap(): array
	{
		return $this->map;
	}

	/**
	 * Get the distributor field name for a canonical field.
	 *
	 * @param  string $canonical
	 * @return string|null
	 */
	public function reverseMap( string $canonical ): ?string
	{
		return $this->reverseMap[ $canonical ] ?? null;
	}
}
