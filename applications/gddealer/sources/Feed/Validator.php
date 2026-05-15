<?php
/**
 * @brief       GD Dealer Manager - Feed Validator (Schema v1.1)
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.141
 *
 * Validates a parsed GunRack feed (XML / JSON / CSV after going through the
 * format-specific parser) against the v1.1 schema. Returns a structured
 * report with errors (block import), warnings (allow with caution), and
 * informational notes (auto-mapping, skip patterns).
 *
 * Lenient validation philosophy (v1.0.204+):
 *   - Category is auto-normalized via CategoryNormalizer (same path
 *     the importer uses). Non-canonical names are accepted and mapped.
 *   - Category-specific subfields (ammo.caliber, knife.type, etc.)
 *     are RECOMMENDED, not required. Missing subfields emit warnings;
 *     listings still import but don't appear in advanced search filters.
 *   - Format errors (wrong type, invalid enum value when field IS
 *     provided) remain hard errors.
 *
 * Required (all categories):
 *   upc, category, price, condition, url, in_stock
 *
 * shipping_info is optional free-form text (max 60 chars);
 * old feeds using free_shipping / shipping_cost are accepted
 * and auto-converted to text by the FieldMapper.
 *
 * Optional (all categories):
 *   sku, map_price, stock_qty, name, brand, mpn, image_url, shipping_info
 *
 * Category-specific recommended fields:
 *   ammo:      ammo.caliber, ammo.rounds
 *   firearm:   firearm.model, firearm.type, firearm.caliber (all optional)
 *   part:      part.type
 *   reloading: reloading.type, reloading.rounds, plus type-specific subfield
 *   optic:     optic.type
 *   knife:     knife.type
 *   accessory: (none)
 *   apparel:   (none)
 */

namespace IPS\gddealer\Feed;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class Validator
{
    public const VALID_CATEGORIES = [
        'firearm', 'ammo', 'part', 'accessory',
        'optic', 'reloading', 'knife', 'apparel',
    ];

    public const VALID_CONDITIONS = [ 'new', 'used', 'refurbished' ];

    public const REQUIRED_FIELDS = [ 'upc', 'category', 'price', 'condition', 'url' ];

    public const VALID_RELOADING_TYPES = [ 'bullet', 'brass', 'primer' ];

    public const VALID_OPTIC_TYPES = [
        'red_dot', 'holographic', 'lpvo', 'rifle_scope',
        'pistol_scope', 'magnifier', 'iron_sights', 'prism',
    ];

    public const VALID_KNIFE_TYPES = [
        'fixed_blade', 'folding', 'automatic', 'assisted', 'multitool',
    ];

    public const VALID_AMMO_FIRE_TYPES = [
        'centerfire', 'rimfire', 'black_powder', 'shotgun',
    ];

    public const VALID_AMMO_BULLET_DESIGNS = [
        'fmj', 'hollow_point', 'soft_point', 'polymer_tip', 'frangible', 'aluminum_tip',
    ];

    public const VALID_AMMO_TIP_COLORS = [
        'green', 'red', 'orange', 'black', 'blue', 'silver', 'white',
    ];

    public const VALID_AMMO_CASE_MATERIALS = [
        'brass', 'steel', 'aluminum', 'nickel',
    ];

    /**
     * Validate a list of parsed records.
     *
     * @param array<int, array<string, mixed>> $records
     * @return array{
     *   valid: bool,
     *   summary: array{total_records:int, valid_records:int, error_records:int, warning_records:int, note_records:int},
     *   errors: array<int, array{row:int, upc:string, field:string, message:string}>,
     *   warnings: array<int, array{row:int, upc:string, field:string, message:string}>,
     *   notes: array<int, array{row:int, upc:string, field:string, message:string}>
     * }
     */
    public static function validate( array $records ): array
    {
        $errors      = [];
        $warnings    = [];
        $notes       = [];
        $errorRows   = 0;
        $warningRows = 0;
        $noteRows    = 0;

        if ( count( $records ) === 0 )
        {
            return [
                'valid'    => false,
                'summary'  => [ 'total_records' => 0, 'valid_records' => 0, 'error_records' => 0, 'warning_records' => 0, 'note_records' => 0 ],
                'errors'   => [ [ 'row' => 0, 'upc' => '', 'field' => '_root', 'message' => 'Feed contains zero listings.' ] ],
                'warnings' => [],
                'notes'    => [],
            ];
        }

        $seenUpcs = [];

        foreach ( $records as $idx => $record )
        {
            $row = $idx + 1;
            $upc = isset( $record['upc'] ) ? (string) $record['upc'] : '';
            $rowHadError   = false;
            $rowHadWarning = false;
            $rowHadNote    = false;
            $price = 0.0;

            /* Required field presence */
            foreach ( self::REQUIRED_FIELDS as $field )
            {
                if ( !array_key_exists( $field, $record ) || (string) $record[ $field ] === '' )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => $field, 'message' => "Missing required field: {$field}" ];
                    $rowHadError = true;
                }
            }

            /* UPC format: 12 (UPC-A) or 13 (EAN-13) digits */
            if ( $upc !== '' )
            {
                $cleaned = preg_replace( '/[^0-9]/', '', $upc );
                if ( $cleaned !== $upc )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'upc', 'message' => 'UPC contains non-digit characters; will be stripped on import.' ];
                    $rowHadWarning = true;
                }
                $len = strlen( $cleaned );
                if ( $len !== 12 && $len !== 13 )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'upc', 'message' => "UPC must be 12 or 13 digits (got {$len})." ];
                    $rowHadError = true;
                }
                if ( isset( $seenUpcs[ $cleaned ] ) )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'upc', 'message' => "Duplicate UPC; previously seen on row {$seenUpcs[$cleaned]}. Last occurrence wins on import." ];
                    $rowHadWarning = true;
                }
                else
                {
                    $seenUpcs[ $cleaned ] = $row;
                }
            }

            /* Category — lenient. If raw value is canonical, use directly.
             * Otherwise run CategoryNormalizer (same path the importer
             * uses) to find the closest canonical. Never blocks the
             * dealer; emits informational notes for traceability. */
            $cat = '';
            if ( isset( $record['category'] ) && (string) $record['category'] !== '' )
            {
                $rawCat     = trim( (string) $record['category'] );
                $loweredCat = strtolower( $rawCat );

                if ( in_array( $loweredCat, self::VALID_CATEGORIES, true ) )
                {
                    $cat = $loweredCat;
                }
                else
                {
                    $normalized = CategoryNormalizer::normalize( $rawCat );
                    if ( $normalized === 'skip' )
                    {
                        $notes[] = [
                            'row' => $row, 'upc' => $upc, 'field' => 'category',
                            'message' => "Category '{$rawCat}' matches a skip pattern (membership/gift card/etc) — will be excluded on import.",
                        ];
                        $rowHadNote = true;
                        $cat = '';
                    }
                    elseif ( $normalized === 'accessory' && stripos( $rawCat, 'accessor' ) === false )
                    {
                        $notes[] = [
                            'row' => $row, 'upc' => $upc, 'field' => 'category',
                            'message' => "Category '{$rawCat}' didn't match any known type — will be imported as 'accessory'. Review in your Categories tab after the first import.",
                        ];
                        $rowHadNote = true;
                        $cat = $normalized;
                    }
                    else
                    {
                        $notes[] = [
                            'row' => $row, 'upc' => $upc, 'field' => 'category',
                            'message' => "Category '{$rawCat}' will be auto-mapped to '{$normalized}' on import. Override in your Categories tab if needed.",
                        ];
                        $rowHadNote = true;
                        $cat = $normalized;
                    }
                }
            }

            /* Price */
            if ( isset( $record['price'] ) && (string) $record['price'] !== '' )
            {
                $rawPrice = (string) $record['price'];
                $price    = (float) preg_replace( '/[^0-9.]/', '', $rawPrice );
                if ( $price <= 0 )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'price', 'message' => "Price must be greater than 0 (got '{$rawPrice}')." ];
                    $rowHadError = true;
                }
                if ( $price > 100000 )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'price', 'message' => "Price '{$rawPrice}' exceeds \$100,000 sanity check." ];
                    $rowHadWarning = true;
                }
            }

            /* MAP price (optional) */
            if ( isset( $record['map_price'] ) && (string) $record['map_price'] !== '' )
            {
                $rawMap = (string) $record['map_price'];
                $map    = (float) preg_replace( '/[^0-9.]/', '', $rawMap );
                if ( $map <= 0 )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'map_price', 'message' => "MAP price must be greater than 0 (got '{$rawMap}')." ];
                    $rowHadError = true;
                }
                if ( $price > 0 && $map <= $price )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'map_price', 'message' => "MAP price ({$map}) is not greater than price ({$price}); MAP will be ignored on import." ];
                    $rowHadWarning = true;
                }
            }

            /* Condition */
            if ( isset( $record['condition'] ) && (string) $record['condition'] !== '' )
            {
                $cond = strtolower( trim( (string) $record['condition'] ) );
                if ( !in_array( $cond, self::VALID_CONDITIONS, true ) )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'condition', 'message' => "Invalid condition '{$cond}'. Must be one of: " . implode( ', ', self::VALID_CONDITIONS ) ];
                    $rowHadError = true;
                }
            }

            /* URL */
            if ( isset( $record['url'] ) && (string) $record['url'] !== '' )
            {
                $url = (string) $record['url'];
                if ( !preg_match( '#^https://#i', $url ) )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'url', 'message' => "URL must start with https:// (got '{$url}')." ];
                    $rowHadError = true;
                }
                elseif ( filter_var( $url, FILTER_VALIDATE_URL ) === false )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'url', 'message' => "URL is malformed: '{$url}'" ];
                    $rowHadError = true;
                }
            }

            /* image_url (optional) */
            if ( isset( $record['image_url'] ) && (string) $record['image_url'] !== '' )
            {
                $imgUrl = (string) $record['image_url'];
                if ( !preg_match( '#^https://#i', $imgUrl ) )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'image_url', 'message' => "image_url should start with https:// (got '{$imgUrl}'); browsers may block mixed content." ];
                    $rowHadWarning = true;
                }
            }

            /* in_stock */
            if ( isset( $record['in_stock'] ) && (string) $record['in_stock'] !== '' )
            {
                if ( !self::truthyParseable( $record['in_stock'] ) )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'in_stock', 'message' => "Ambiguous in_stock value '" . $record['in_stock'] . "'; will be treated as truthy on import." ];
                    $rowHadWarning = true;
                }
            }

            /* stock_qty */
            if ( isset( $record['stock_qty'] ) && (string) $record['stock_qty'] !== '' )
            {
                $qty = (string) $record['stock_qty'];
                if ( !ctype_digit( $qty ) )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'stock_qty', 'message' => "stock_qty should be a non-negative integer (got '{$qty}')." ];
                    $rowHadWarning = true;
                }
            }

            /* sku length */
            if ( isset( $record['sku'] ) && strlen( (string) $record['sku'] ) > 100 )
            {
                $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'sku', 'message' => 'sku must be 100 characters or fewer.' ];
                $rowHadError = true;
            }

            /* name / brand / mpn length sanity */
            foreach ( [ 'name' => 200, 'brand' => 100, 'mpn' => 100 ] as $f => $maxLen )
            {
                if ( isset( $record[ $f ] ) && strlen( (string) $record[ $f ] ) > $maxLen )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => $f, 'message' => "{$f} exceeds {$maxLen} characters; will be truncated on import." ];
                    $rowHadWarning = true;
                }
            }

            /* ----- Category-specific validation ----- */

            if ( $cat === 'ammo' )
            {
                if ( !isset( $record['ammo.caliber'] ) || (string) $record['ammo.caliber'] === '' )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'ammo.caliber', 'message' => 'ammo.caliber is recommended when category=ammo (e.g. "9mm Luger", ".223 Remington"). The listing will still import but won\'t appear in caliber-specific search filters.' ];
                    $rowHadWarning = true;
                }
                if ( !isset( $record['ammo.rounds'] ) || (string) $record['ammo.rounds'] === '' )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'ammo.rounds', 'message' => 'ammo.rounds is recommended when category=ammo (e.g. 20 for a box of 20 rounds). The listing will still import but won\'t appear in cost-per-round comparisons.' ];
                    $rowHadWarning = true;
                }
                elseif ( !ctype_digit( (string) $record['ammo.rounds'] ) || (int) $record['ammo.rounds'] <= 0 )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'ammo.rounds', 'message' => "ammo.rounds must be a positive integer (got '" . $record['ammo.rounds'] . "')." ];
                    $rowHadError = true;
                }

                /* ammo.fire_type (optional) */
                $fireType = '';
                if ( isset( $record['ammo.fire_type'] ) && (string) $record['ammo.fire_type'] !== '' )
                {
                    $fireType = strtolower( trim( (string) $record['ammo.fire_type'] ) );
                    if ( !in_array( $fireType, self::VALID_AMMO_FIRE_TYPES, true ) )
                    {
                        $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'ammo.fire_type', 'message' => "Invalid ammo.fire_type '{$fireType}'. Must be one of: " . implode( ', ', self::VALID_AMMO_FIRE_TYPES ) ];
                        $rowHadError = true;
                        $fireType = '';
                    }
                }

                /* ammo.bullet_design (optional) */
                if ( isset( $record['ammo.bullet_design'] ) && (string) $record['ammo.bullet_design'] !== '' )
                {
                    $bulletDesign = strtolower( trim( (string) $record['ammo.bullet_design'] ) );
                    if ( !in_array( $bulletDesign, self::VALID_AMMO_BULLET_DESIGNS, true ) )
                    {
                        $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'ammo.bullet_design', 'message' => "Invalid ammo.bullet_design '{$bulletDesign}'. Must be one of: " . implode( ', ', self::VALID_AMMO_BULLET_DESIGNS ) ];
                        $rowHadError = true;
                    }
                }

                /* ammo.tip_color (optional) */
                if ( isset( $record['ammo.tip_color'] ) && (string) $record['ammo.tip_color'] !== '' )
                {
                    $tipColor = strtolower( trim( (string) $record['ammo.tip_color'] ) );
                    if ( !in_array( $tipColor, self::VALID_AMMO_TIP_COLORS, true ) )
                    {
                        $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'ammo.tip_color', 'message' => "Invalid ammo.tip_color '{$tipColor}'. Must be one of: " . implode( ', ', self::VALID_AMMO_TIP_COLORS ) ];
                        $rowHadError = true;
                    }
                }

                /* ammo.case_material - required when fire_type=centerfire, otherwise optional */
                $caseMaterial = isset( $record['ammo.case_material'] ) ? strtolower( trim( (string) $record['ammo.case_material'] ) ) : '';
                if ( $fireType === 'centerfire' && $caseMaterial === '' )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'ammo.case_material', 'message' => 'ammo.case_material is required when ammo.fire_type=centerfire. Must be one of: ' . implode( ', ', self::VALID_AMMO_CASE_MATERIALS ) ];
                    $rowHadError = true;
                }
                elseif ( $caseMaterial !== '' && !in_array( $caseMaterial, self::VALID_AMMO_CASE_MATERIALS, true ) )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'ammo.case_material', 'message' => "Invalid ammo.case_material '{$caseMaterial}'. Must be one of: " . implode( ', ', self::VALID_AMMO_CASE_MATERIALS ) ];
                    $rowHadError = true;
                }
            }

            if ( $cat === 'firearm' )
            {
                /* No required subfields - empty firearm block valid. */
                if ( ( !isset( $record['firearm.model'] ) || (string) $record['firearm.model'] === '' )
                  && ( !isset( $record['firearm.type'] ) || (string) $record['firearm.type'] === '' )
                  && ( !isset( $record['firearm.caliber'] ) || (string) $record['firearm.caliber'] === '' ) )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'firearm', 'message' => 'No firearm.model / firearm.type / firearm.caliber provided; this listing will not appear in firearm-specific search filters.' ];
                    $rowHadWarning = true;
                }
            }

            if ( $cat === 'part' )
            {
                if ( !isset( $record['part.type'] ) || (string) $record['part.type'] === '' )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'part.type', 'message' => 'part.type is recommended when category=part (e.g. "1911 magazine", "AR-15 lower"). The listing will still import but won\'t appear in part-specific search filters.' ];
                    $rowHadWarning = true;
                }
            }

            if ( $cat === 'reloading' )
            {
                $rType = isset( $record['reloading.type'] ) ? strtolower( trim( (string) $record['reloading.type'] ) ) : '';
                if ( $rType === '' )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'reloading.type', 'message' => 'reloading.type is recommended when category=reloading. Must be one of: ' . implode( ', ', self::VALID_RELOADING_TYPES ) . '. The listing will still import but won\'t appear in reloading-specific filters.' ];
                    $rowHadWarning = true;
                }
                elseif ( !in_array( $rType, self::VALID_RELOADING_TYPES, true ) )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'reloading.type', 'message' => "Invalid reloading.type '{$rType}'. Must be one of: " . implode( ', ', self::VALID_RELOADING_TYPES ) ];
                    $rowHadError = true;
                }

                if ( !isset( $record['reloading.rounds'] ) || (string) $record['reloading.rounds'] === '' )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'reloading.rounds', 'message' => 'reloading.rounds is recommended when category=reloading (e.g. 1000 for a box of 1000 primers/bullets/brass). The listing will still import but won\'t appear in quantity-specific filters.' ];
                    $rowHadWarning = true;
                }
                elseif ( !ctype_digit( (string) $record['reloading.rounds'] ) || (int) $record['reloading.rounds'] <= 0 )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'reloading.rounds', 'message' => "reloading.rounds must be a positive integer (got '" . $record['reloading.rounds'] . "')." ];
                    $rowHadError = true;
                }

                /* Type-specific subfield rules — dependent fields stay as errors */
                if ( $rType === 'bullet' && ( !isset( $record['reloading.bullet_caliber'] ) || (string) $record['reloading.bullet_caliber'] === '' ) )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'reloading.bullet_caliber', 'message' => 'reloading.bullet_caliber is required when reloading.type=bullet.' ];
                    $rowHadError = true;
                }
                if ( $rType === 'brass' && ( !isset( $record['reloading.brass_cartridge'] ) || (string) $record['reloading.brass_cartridge'] === '' ) )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'reloading.brass_cartridge', 'message' => 'reloading.brass_cartridge is required when reloading.type=brass.' ];
                    $rowHadError = true;
                }
                if ( $rType === 'primer' && ( !isset( $record['reloading.primer_size'] ) || (string) $record['reloading.primer_size'] === '' ) )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'reloading.primer_size', 'message' => 'reloading.primer_size is required when reloading.type=primer.' ];
                    $rowHadError = true;
                }
            }

            if ( $cat === 'optic' )
            {
                $oType = isset( $record['optic.type'] ) ? strtolower( trim( (string) $record['optic.type'] ) ) : '';
                if ( $oType === '' )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'optic.type', 'message' => 'optic.type is recommended when category=optic. Suggested values: ' . implode( ', ', self::VALID_OPTIC_TYPES ) . '. The listing will still import but won\'t appear in optic-specific filters.' ];
                    $rowHadWarning = true;
                }
                elseif ( !in_array( $oType, self::VALID_OPTIC_TYPES, true ) )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'optic.type', 'message' => "Invalid optic.type '{$oType}'. Must be one of: " . implode( ', ', self::VALID_OPTIC_TYPES ) ];
                    $rowHadError = true;
                }
                if ( isset( $record['optic.objective_mm'] ) && (string) $record['optic.objective_mm'] !== ''
                  && !ctype_digit( (string) $record['optic.objective_mm'] ) )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'optic.objective_mm', 'message' => "optic.objective_mm should be a positive integer in mm (got '" . $record['optic.objective_mm'] . "')." ];
                    $rowHadWarning = true;
                }
            }

            if ( $cat === 'knife' )
            {
                $kType = isset( $record['knife.type'] ) ? strtolower( trim( (string) $record['knife.type'] ) ) : '';
                if ( $kType === '' )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'knife.type', 'message' => 'knife.type is recommended when category=knife. Suggested values: ' . implode( ', ', self::VALID_KNIFE_TYPES ) . '. The listing will still import but won\'t appear in knife-specific filters.' ];
                    $rowHadWarning = true;
                }
                elseif ( !in_array( $kType, self::VALID_KNIFE_TYPES, true ) )
                {
                    $errors[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'knife.type', 'message' => "Invalid knife.type '{$kType}'. Must be one of: " . implode( ', ', self::VALID_KNIFE_TYPES ) ];
                    $rowHadError = true;
                }
                if ( isset( $record['knife.blade_length_in'] ) && (string) $record['knife.blade_length_in'] !== ''
                  && !is_numeric( (string) $record['knife.blade_length_in'] ) )
                {
                    $warnings[] = [ 'row' => $row, 'upc' => $upc, 'field' => 'knife.blade_length_in', 'message' => "knife.blade_length_in should be a decimal number in inches (got '" . $record['knife.blade_length_in'] . "')." ];
                    $rowHadWarning = true;
                }
            }

            /* accessory and apparel: no category-specific rules */

            if ( $rowHadError )        { $errorRows++; }
            elseif ( $rowHadWarning )  { $warningRows++; }
            elseif ( $rowHadNote )     { $noteRows++; }
        }

        $total = count( $records );

        return [
            'valid'   => empty( $errors ),
            'summary' => [
                'total_records'   => $total,
                'valid_records'   => $total - $errorRows,
                'error_records'   => $errorRows,
                'warning_records' => $warningRows,
                'note_records'    => $noteRows,
            ],
            'errors'   => $errors,
            'warnings' => $warnings,
            'notes'    => $notes,
        ];
    }

    protected static function truthy( mixed $v ): bool
    {
        if ( is_bool( $v ) ) { return $v; }
        if ( is_int( $v ) )  { return $v > 0; }
        $s = strtolower( trim( (string) $v ) );
        return in_array( $s, [ '1', 'true', 'yes', 'y', 'in_stock', 'instock', 'available', 'on' ], true );
    }

    protected static function truthyParseable( mixed $v ): bool
    {
        if ( is_bool( $v ) || is_int( $v ) ) { return true; }
        $s = strtolower( trim( (string) $v ) );
        return in_array( $s, [
            '1', '0', 'true', 'false', 'yes', 'no', 'y', 'n',
            'in_stock', 'out_of_stock', 'instock', 'outofstock',
            'available', 'unavailable', 'on', 'off', '',
        ], true );
    }
}
