<?php
/**
 * @brief       GD Dealer Manager - Canonical Field Registry
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.148
 *
 * Central registry of every canonical field a dealer feed can map to.
 * Used by:
 *   - FieldMapper (knows which keys are valid targets)
 *   - Feed wizard UI (renders grouped dropdown options)
 *   - Auto-suggest engine (maps dealer field names to canonical fields)
 *
 * This class is the single source of truth. Adding a new canonical field
 * means editing this file and only this file - the validator, mapper, and
 * wizard all read from here.
 *
 * Auto-suggest dictionary covers common variants used by:
 *   - Generic e-commerce platforms (Shopify, WooCommerce, BigCommerce)
 *   - Common naming conventions (snake_case, camelCase, kebab-case, "Title Case")
 *   - Industry-standard product feed formats (Google Merchant, Facebook Catalog)
 *
 * Distributor-specific patterns (RSR, Lipsey's, Sports South) are NOT included
 * yet - they will be added in a later version once real distributor feed
 * samples have been collected.
 */

namespace IPS\gddealer\Feed;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class CanonicalFields
{
    /**
     * Required tier constants.
     */
    public const REQ_REQUIRED        = 'required';
    public const REQ_CONDITIONAL     = 'conditional';
    public const REQ_OPTIONAL        = 'optional';
    public const REQ_CAT_REQUIRED    = 'cat_required';
    public const REQ_CAT_OPTIONAL    = 'cat_optional';

    /**
     * Group keys. Used for dropdown grouping in the wizard UI.
     */
    public const GROUP_CORE_REQUIRED = 'core_required';
    public const GROUP_CORE_OPTIONAL = 'core_optional';
    public const GROUP_AMMO          = 'ammo';
    public const GROUP_FIREARM       = 'firearm';
    public const GROUP_PART          = 'part';
    public const GROUP_RELOADING     = 'reloading';
    public const GROUP_OPTIC         = 'optic';
    public const GROUP_KNIFE         = 'knife';

    /**
     * Group display labels.
     *
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            self::GROUP_CORE_REQUIRED => 'Core Required Fields',
            self::GROUP_CORE_OPTIONAL => 'Core Optional Fields',
            self::GROUP_AMMO          => 'Ammo Subfields',
            self::GROUP_FIREARM       => 'Firearm Subfields',
            self::GROUP_PART          => 'Part Subfields',
            self::GROUP_RELOADING     => 'Reloading Subfields',
            self::GROUP_OPTIC         => 'Optic Subfields',
            self::GROUP_KNIFE         => 'Knife Subfields',
        ];
    }

    /**
     * The full canonical field registry.
     *
     * Each entry has:
     *   slug   - canonical field name (the key)
     *   label  - human-readable name for UI
     *   group  - which dropdown group this field belongs to
     *   req    - required tier (REQ_* constant)
     *
     * @return array<string, array{slug:string, label:string, group:string, req:string}>
     */
    public static function all(): array
    {
        return [
            /* ========== Core Required (every listing) ========== */
            'upc'           => [ 'slug' => 'upc',           'label' => 'UPC',                'group' => self::GROUP_CORE_REQUIRED, 'req' => self::REQ_REQUIRED ],
            'category'      => [ 'slug' => 'category',      'label' => 'Category',           'group' => self::GROUP_CORE_REQUIRED, 'req' => self::REQ_REQUIRED ],
            'price'         => [ 'slug' => 'price',         'label' => 'Price',              'group' => self::GROUP_CORE_REQUIRED, 'req' => self::REQ_REQUIRED ],
            'condition'     => [ 'slug' => 'condition',     'label' => 'Condition',          'group' => self::GROUP_CORE_REQUIRED, 'req' => self::REQ_REQUIRED, 'default' => 'new' ],
            'url'           => [ 'slug' => 'url',           'label' => 'Listing URL',        'group' => self::GROUP_CORE_REQUIRED, 'req' => self::REQ_REQUIRED ],
            'in_stock'      => [ 'slug' => 'in_stock',      'label' => 'In Stock Flag',      'group' => self::GROUP_CORE_REQUIRED, 'req' => self::REQ_REQUIRED, 'default' => 'true' ],

            /* ========== Core Optional (every listing) ========== */
            'shipping_info'       => [ 'slug' => 'shipping_info',       'label' => 'Shipping Info',                              'group' => self::GROUP_CORE_OPTIONAL, 'req' => self::REQ_OPTIONAL ],
            'free_shipping_hint'  => [ 'slug' => 'free_shipping_hint',  'label' => 'Free Shipping Flag (auto-converts to text)', 'group' => self::GROUP_CORE_OPTIONAL, 'req' => self::REQ_OPTIONAL ],
            'sku'           => [ 'slug' => 'sku',           'label' => 'Dealer SKU',         'group' => self::GROUP_CORE_OPTIONAL, 'req' => self::REQ_OPTIONAL ],
            'map_price'     => [ 'slug' => 'map_price',     'label' => 'MAP Price',          'group' => self::GROUP_CORE_OPTIONAL, 'req' => self::REQ_OPTIONAL ],
            'stock_qty'     => [ 'slug' => 'stock_qty',     'label' => 'Stock Quantity',     'group' => self::GROUP_CORE_OPTIONAL, 'req' => self::REQ_OPTIONAL ],
            'name'          => [ 'slug' => 'name',          'label' => 'Product Name',       'group' => self::GROUP_CORE_OPTIONAL, 'req' => self::REQ_OPTIONAL ],
            'brand'         => [ 'slug' => 'brand',         'label' => 'Brand',              'group' => self::GROUP_CORE_OPTIONAL, 'req' => self::REQ_OPTIONAL ],
            'mpn'           => [ 'slug' => 'mpn',           'label' => 'MPN',                'group' => self::GROUP_CORE_OPTIONAL, 'req' => self::REQ_OPTIONAL ],
            'image_url'     => [ 'slug' => 'image_url',     'label' => 'Image URL',          'group' => self::GROUP_CORE_OPTIONAL, 'req' => self::REQ_OPTIONAL ],

            /* ========== Ammo subfields (when category=ammo) ========== */
            'ammo.caliber'       => [ 'slug' => 'ammo.caliber',       'label' => 'Ammo: Caliber',        'group' => self::GROUP_AMMO, 'req' => self::REQ_CAT_REQUIRED ],
            'ammo.rounds'        => [ 'slug' => 'ammo.rounds',        'label' => 'Ammo: Round Count',    'group' => self::GROUP_AMMO, 'req' => self::REQ_CAT_REQUIRED ],
            'ammo.fire_type'     => [ 'slug' => 'ammo.fire_type',     'label' => 'Ammo: Fire Type',      'group' => self::GROUP_AMMO, 'req' => self::REQ_CAT_OPTIONAL ],
            'ammo.bullet_design' => [ 'slug' => 'ammo.bullet_design', 'label' => 'Ammo: Bullet Design',  'group' => self::GROUP_AMMO, 'req' => self::REQ_CAT_OPTIONAL ],
            'ammo.tip_color'     => [ 'slug' => 'ammo.tip_color',     'label' => 'Ammo: Tip Color',      'group' => self::GROUP_AMMO, 'req' => self::REQ_CAT_OPTIONAL ],
            'ammo.case_material' => [ 'slug' => 'ammo.case_material', 'label' => 'Ammo: Case Material',  'group' => self::GROUP_AMMO, 'req' => self::REQ_CAT_OPTIONAL ],

            /* ========== Firearm subfields (when category=firearm) ========== */
            'firearm.model'   => [ 'slug' => 'firearm.model',   'label' => 'Firearm: Model',   'group' => self::GROUP_FIREARM, 'req' => self::REQ_CAT_OPTIONAL ],
            'firearm.type'    => [ 'slug' => 'firearm.type',    'label' => 'Firearm: Type',    'group' => self::GROUP_FIREARM, 'req' => self::REQ_CAT_OPTIONAL ],
            'firearm.action'  => [ 'slug' => 'firearm.action',  'label' => 'Firearm: Action',  'group' => self::GROUP_FIREARM, 'req' => self::REQ_CAT_OPTIONAL ],
            'firearm.caliber' => [ 'slug' => 'firearm.caliber', 'label' => 'Firearm: Caliber', 'group' => self::GROUP_FIREARM, 'req' => self::REQ_CAT_OPTIONAL ],

            /* ========== Part subfields (when category=part) ========== */
            'part.type' => [ 'slug' => 'part.type', 'label' => 'Part: Type', 'group' => self::GROUP_PART, 'req' => self::REQ_CAT_REQUIRED ],

            /* ========== Reloading subfields (when category=reloading) ========== */
            'reloading.type'             => [ 'slug' => 'reloading.type',             'label' => 'Reloading: Type',             'group' => self::GROUP_RELOADING, 'req' => self::REQ_CAT_REQUIRED ],
            'reloading.rounds'           => [ 'slug' => 'reloading.rounds',           'label' => 'Reloading: Round Count',      'group' => self::GROUP_RELOADING, 'req' => self::REQ_CAT_REQUIRED ],
            'reloading.bullet_caliber'   => [ 'slug' => 'reloading.bullet_caliber',   'label' => 'Reloading: Bullet Caliber',   'group' => self::GROUP_RELOADING, 'req' => self::REQ_CAT_REQUIRED ],
            'reloading.brass_cartridge'  => [ 'slug' => 'reloading.brass_cartridge',  'label' => 'Reloading: Brass Cartridge',  'group' => self::GROUP_RELOADING, 'req' => self::REQ_CAT_REQUIRED ],
            'reloading.primer_size'      => [ 'slug' => 'reloading.primer_size',     'label' => 'Reloading: Primer Size',      'group' => self::GROUP_RELOADING, 'req' => self::REQ_CAT_REQUIRED ],

            /* ========== Optic subfields (when category=optic) ========== */
            'optic.type'          => [ 'slug' => 'optic.type',          'label' => 'Optic: Type',           'group' => self::GROUP_OPTIC, 'req' => self::REQ_CAT_REQUIRED ],
            'optic.magnification' => [ 'slug' => 'optic.magnification', 'label' => 'Optic: Magnification',  'group' => self::GROUP_OPTIC, 'req' => self::REQ_CAT_OPTIONAL ],
            'optic.reticle'       => [ 'slug' => 'optic.reticle',       'label' => 'Optic: Reticle',        'group' => self::GROUP_OPTIC, 'req' => self::REQ_CAT_OPTIONAL ],
            'optic.objective_mm'  => [ 'slug' => 'optic.objective_mm',  'label' => 'Optic: Objective (mm)', 'group' => self::GROUP_OPTIC, 'req' => self::REQ_CAT_OPTIONAL ],

            /* ========== Knife subfields (when category=knife) ========== */
            'knife.type'             => [ 'slug' => 'knife.type',             'label' => 'Knife: Type',              'group' => self::GROUP_KNIFE, 'req' => self::REQ_CAT_REQUIRED ],
            'knife.blade_length_in'  => [ 'slug' => 'knife.blade_length_in',  'label' => 'Knife: Blade Length (in)', 'group' => self::GROUP_KNIFE, 'req' => self::REQ_CAT_OPTIONAL ],
            'knife.blade_steel'      => [ 'slug' => 'knife.blade_steel',      'label' => 'Knife: Blade Steel',       'group' => self::GROUP_KNIFE, 'req' => self::REQ_CAT_OPTIONAL ],
        ];
    }

    /**
     * Return a flat list of canonical slugs.
     *
     * @return array<int, string>
     */
    public static function allSlugs(): array
    {
        return array_keys( self::all() );
    }

    /**
     * Return canonical fields grouped for UI rendering.
     * Order matches the UI display order (required first, then optional, then categories).
     *
     * @return array<string, array{label:string, fields:array<int, array{slug:string, label:string, req:string}>}>
     */
    public static function grouped(): array
    {
        $groupLabels = self::groupLabels();
        $groups = [];
        foreach ( $groupLabels as $key => $label )
        {
            $groups[ $key ] = [ 'label' => $label, 'fields' => [] ];
        }
        foreach ( self::all() as $field )
        {
            $g = $field['group'];
            if ( !isset( $groups[ $g ] ) ) { continue; }
            $groups[ $g ]['fields'][] = $field;
        }
        return $groups;
    }

    /**
     * Auto-suggest dictionary - maps normalized dealer field names to
     * canonical slugs. Normalization is done by self::normalizeKey().
     *
     * Roughly 120 entries covering common e-commerce naming variants.
     *
     * @return array<string, string>
     */
    public static function suggestionDictionary(): array
    {
        return [
            /* upc */
            'upc' => 'upc',
            'upcCode' => 'upc',
            'upcA' => 'upc',
            'gtin' => 'upc',
            'gtin12' => 'upc',
            'gtin13' => 'upc',
            'gtin14' => 'upc',
            'ean' => 'upc',
            'ean13' => 'upc',
            'barcode' => 'upc',
            'productCode' => 'upc',
            'productId' => 'upc',
            'itemCode' => 'upc',
            'itemNumber' => 'upc',

            /* sku */
            'sku' => 'sku',
            'dealerSku' => 'sku',
            'vendorSku' => 'sku',
            'internalSku' => 'sku',
            'stockNumber' => 'sku',
            'stockKeepingUnit' => 'sku',
            'partNumber' => 'sku',

            /* category */
            'category' => 'category',
            'productCategory' => 'category',
            'categoryName' => 'category',
            'productType' => 'category',
            'itemType' => 'category',
            'department' => 'category',

            /* price */
            'price' => 'price',
            'listPrice' => 'price',
            'salePrice' => 'price',
            'sellingPrice' => 'price',
            'ourPrice' => 'price',
            'retailPrice' => 'price',
            'currentPrice' => 'price',
            'cost' => 'price',
            'unitPrice' => 'price',
            'priceUsd' => 'price',
            'amount' => 'price',

            /* map_price */
            'mapPrice' => 'map_price',
            'mapMin' => 'map_price',
            'minimumAdvertisedPrice' => 'map_price',
            'msrp' => 'map_price',
            'manufacturerSuggestedRetailPrice' => 'map_price',
            'compareAtPrice' => 'map_price',

            /* condition */
            'condition' => 'condition',
            'productCondition' => 'condition',
            'itemCondition' => 'condition',
            'conditionType' => 'condition',
            'state' => 'condition',

            /* url — v1.0.334 expanded to cover LitCommerce, Shopify,
               WordPress/WooCommerce, and BigCommerce naming conventions
               seen in production dealer feeds. Every entry here also
               powers the snapshot's URL fallback + FieldMapper::apply()'s
               URL auto-recovery, so adding a variant here is the single
               place to teach the whole pipeline about a new URL tag. */
            'url' => 'url',
            'productUrl' => 'url',
            'listingUrl' => 'url',
            'productLink' => 'url',
            'link' => 'url',
            'webLink' => 'url',
            'pageUrl' => 'url',
            'productPage' => 'url',
            'productPageUrl' => 'url',
            'detailUrl' => 'url',
            'href' => 'url',
            'externalUrl' => 'url',
            'permalink' => 'url',
            'itemUrl' => 'url',
            'itemLink' => 'url',
            'pageLink' => 'url',
            'catalogUrl' => 'url',
            'merchantUrl' => 'url',
            'storefrontUrl' => 'url',
            'sourceUrl' => 'url',
            'canonicalUrl' => 'url',
            'shopUrl' => 'url',
            'webUrl' => 'url',

            /* image_url */
            'image' => 'image_url',
            'imageUrl' => 'image_url',
            'imageLink' => 'image_url',
            'productImage' => 'image_url',
            'thumbnail' => 'image_url',
            'thumbnailUrl' => 'image_url',
            'photo' => 'image_url',
            'photoUrl' => 'image_url',
            'mainImage' => 'image_url',
            'primaryImage' => 'image_url',

            /* free_shipping_hint (old free_shipping aliases repointed) */
            'freeShipping' => 'free_shipping_hint',
            'freeShip' => 'free_shipping_hint',
            'shippingFree' => 'free_shipping_hint',
            'isFreeShipping' => 'free_shipping_hint',
            'hasFreeShipping' => 'free_shipping_hint',

            /* shipping_info (old shipping_cost aliases repointed + new aliases) */
            'shippingCost' => 'shipping_info',
            'shipping' => 'shipping_info',
            'shippingPrice' => 'shipping_info',
            'shippingFee' => 'shipping_info',
            'shippingAmount' => 'shipping_info',
            'shippingRate' => 'shipping_info',
            'shipCost' => 'shipping_info',
            'deliveryFee' => 'shipping_info',
            'shippingInfo' => 'shipping_info',
            'shipping_info' => 'shipping_info',
            'shipInfo' => 'shipping_info',
            'shippingNote' => 'shipping_info',
            'shippingNotes' => 'shipping_info',
            'shippingText' => 'shipping_info',

            /* in_stock */
            'inStock' => 'in_stock',
            'isInStock' => 'in_stock',
            'available' => 'in_stock',
            'isAvailable' => 'in_stock',
            'availability' => 'in_stock',
            'stockStatus' => 'in_stock',
            'inventoryStatus' => 'in_stock',
            'hasStock' => 'in_stock',

            /* stock_qty */
            'stockQty' => 'stock_qty',
            'stock' => 'stock_qty',
            'stockQuantity' => 'stock_qty',
            'qty' => 'stock_qty',
            'quantity' => 'stock_qty',
            'inventory' => 'stock_qty',
            'inventoryCount' => 'stock_qty',
            'inventoryQuantity' => 'stock_qty',
            'onHand' => 'stock_qty',
            'qtyOnHand' => 'stock_qty',
            'count' => 'stock_qty',

            /* name */
            'name' => 'name',
            'productName' => 'name',
            'productTitle' => 'name',
            'title' => 'name',
            'itemName' => 'name',
            'displayName' => 'name',
            'description' => 'name',
            'shortDescription' => 'name',

            /* brand */
            'brand' => 'brand',
            'brandName' => 'brand',
            'manufacturer' => 'brand',
            'manufacturerName' => 'brand',
            'maker' => 'brand',
            'vendor' => 'brand',
            'vendorName' => 'brand',
            'producer' => 'brand',

            /* mpn */
            'mpn' => 'mpn',
            'manufacturerPartNumber' => 'mpn',
            'manufacturerNumber' => 'mpn',
            'modelNumber' => 'mpn',

            /* ammo subfields - common nested patterns */
            'ammoCaliber' => 'ammo.caliber',
            'caliber' => 'ammo.caliber',
            'gauge' => 'ammo.caliber',
            'cartridge' => 'ammo.caliber',
            'roundCount' => 'ammo.rounds',
            'rounds' => 'ammo.rounds',
            'numRounds' => 'ammo.rounds',
            'numberOfRounds' => 'ammo.rounds',
            'roundsPerBox' => 'ammo.rounds',
            'fireType' => 'ammo.fire_type',
            'primerType' => 'ammo.fire_type',
            'bulletDesign' => 'ammo.bullet_design',
            'bulletType' => 'ammo.bullet_design',
            'projectile' => 'ammo.bullet_design',
            'tipColor' => 'ammo.tip_color',
            'caseMaterial' => 'ammo.case_material',
            'casing' => 'ammo.case_material',

            /* firearm subfields */
            'firearmModel' => 'firearm.model',
            'model' => 'firearm.model',
            'firearmType' => 'firearm.type',
            'gunType' => 'firearm.type',
            'firearmAction' => 'firearm.action',
            'action' => 'firearm.action',
            'actionType' => 'firearm.action',
            'firearmCaliber' => 'firearm.caliber',

            /* part subfields */
            'partType' => 'part.type',
            'partCategory' => 'part.type',

            /* reloading subfields */
            'reloadingType' => 'reloading.type',
            'reloadingRounds' => 'reloading.rounds',
            'bulletCaliber' => 'reloading.bullet_caliber',
            'brassCartridge' => 'reloading.brass_cartridge',
            'primerSize' => 'reloading.primer_size',

            /* optic subfields */
            'opticType' => 'optic.type',
            'scopeType' => 'optic.type',
            'magnification' => 'optic.magnification',
            'magRange' => 'optic.magnification',
            'reticle' => 'optic.reticle',
            'reticleType' => 'optic.reticle',
            'objective' => 'optic.objective_mm',
            'objectiveMm' => 'optic.objective_mm',
            'objectiveDiameter' => 'optic.objective_mm',

            /* knife subfields */
            'knifeType' => 'knife.type',
            'bladeType' => 'knife.type',
            'bladeLength' => 'knife.blade_length_in',
            'bladeLengthIn' => 'knife.blade_length_in',
            'bladeSteel' => 'knife.blade_steel',
            'steelType' => 'knife.blade_steel',
            'steel' => 'knife.blade_steel',
        ];
    }

    /**
     * Normalize a dealer field name for matching against the suggestion
     * dictionary. Strips separators, lowercases, removes common prefixes.
     *
     * Examples:
     *   "product_url"       -> "producturl"
     *   "Product URL"       -> "producturl"
     *   "product-url"       -> "producturl"
     *   "productUrl"        -> "producturl"
     *   "specs.caliber"     -> "specscaliber"
     *
     * Note: dictionary keys must also be passed through this normalizer
     * before lookup (we do that internally in suggestMappingFor).
     */
    public static function normalizeKey( string $key ): string
    {
        $k = strtolower( $key );
        $k = preg_replace( '/[^a-z0-9]/', '', $k ) ?? '';
        return $k;
    }

    /**
     * Suggest a canonical field for a given dealer field name.
     * Returns the canonical slug, or null if no confident match.
     *
     * @param string $dealerField  Raw field name from a dealer feed.
     * @return string|null
     */
    public static function suggestMappingFor( string $dealerField ): ?string
    {
        $normalized = self::normalizeKey( $dealerField );
        if ( $normalized === '' ) { return null; }

        /* First check: does the dealer field match a canonical slug exactly
         * after normalization? (Handles "ammo.caliber" -> "ammocaliber".) */
        foreach ( self::allSlugs() as $slug )
        {
            if ( self::normalizeKey( $slug ) === $normalized )
            {
                return $slug;
            }
        }

        /* Second check: dictionary lookup. */
        foreach ( self::suggestionDictionary() as $variant => $canonical )
        {
            if ( self::normalizeKey( $variant ) === $normalized )
            {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Build a complete suggestion map for an array of dealer field names.
     * Returns dealer_field => canonical_slug (only includes confident matches).
     *
     * @param array<int, string> $dealerFields
     * @return array<string, string>
     */
    public static function buildSuggestionMap( array $dealerFields ): array
    {
        $out = [];
        foreach ( $dealerFields as $field )
        {
            $suggestion = self::suggestMappingFor( $field );
            if ( $suggestion !== null )
            {
                $out[ $field ] = $suggestion;
            }
        }
        return $out;
    }
}
