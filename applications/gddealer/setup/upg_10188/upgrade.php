<?php
/**
 * gddealer v1.0.188 - Dealer category mapping (Option B: dashboard tab).
 *
 * BACKGROUND
 * ----------
 * Real dealer feeds (LitCommerce, Shopify, BigCommerce, etc.) ship
 * breadcrumb-style categories like "Firearms, Handguns, Pistols" or
 * "Shop All, Accessories, Optics, Scopes". Our canonical model has 8
 * flat slugs: firearm, ammo, part, accessory, optic, reloading, knife,
 * apparel.
 *
 * Until now, FieldMapper lowercased the raw value but did nothing with
 * it - the importer simply discarded category because there was no
 * category column on gd_dealer_listings. Wizard step 4 (Validator)
 * threw "Invalid category" errors that scared dealers but didn't block
 * imports (importer doesn't call Validator).
 *
 * v188 NEW MODEL
 * --------------
 * 1. gd_dealer_listings gets a `category varchar(20)` column (canonical
 *    value, queryable for Plugin 3 search filters).
 *
 * 2. New table gd_dealer_category_map - per-dealer mapping from raw
 *    source string to canonical slug, plus an auto_mapped flag so the
 *    dealer can see which entries were auto-classified vs reviewed.
 *
 * 3. New helper class CategoryNormalizer with keyword rules.
 *
 * 4. New helper class CategoryMap - load/lookup/save per dealer.
 *
 * 5. Importer change - per-record category lookup BEFORE the
 *    upcExistsInCatalog check. Records mapped to 'skip' increment a
 *    new records_skipped counter and continue.
 *
 * 6. New dashboard tab "Categories" - dealer sees all mappings.
 *
 * 7. Validator: unknown category becomes WARNING not error.
 *
 * 8. RETRY: v187's setupwizard.php REQ_DERIVED guard.
 *
 * 9. records_skipped counter added to Importer stats array.
 *
 * Idempotent throughout. Marker comment: v188-category-map.
 */

namespace IPS\gddealer\setup\upg_10188;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class _upgrade
{
    public function step1(): bool
    {
        /* --------- Sanity --------- */
        try
        {
            $appVersion = (int) \IPS\Db::i()->select(
                'app_long_version', 'core_applications',
                [ 'app_directory=?', 'gddealer' ]
            )->first();
            if ( $appVersion < 10187 )
            {
                try { \IPS\Log::log( 'v188 ran with app_long_version=' . $appVersion . ' (expected >=10187).', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable ) {}

        $this->addCategoryColumnToListings();
        $this->createCategoryMapTable();
        $this->relaxValidatorCategory();
        $this->retrySetupWizardDerivedGuard();
        $this->bumpImporterStubCounter();

        /* --------- Cache flush --------- */
        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

        try { \IPS\Log::log( 'v188 upgrade complete. NOTE: Importer + dashboard tab + new helper classes ship as plugin files, not patches.', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}

        return TRUE;
    }

    /**
     * Add category column to gd_dealer_listings if missing.
     */
    protected function addCategoryColumnToListings(): void
    {
        try
        {
            $hasCol = FALSE;
            foreach ( \IPS\Db::i()->query( "SHOW COLUMNS FROM gd_dealer_listings LIKE 'category'" ) as $row )
            {
                $hasCol = TRUE;
                break;
            }

            if ( $hasCol )
            {
                try { \IPS\Log::log( 'gd_dealer_listings.category already exists.', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
                return;
            }

            \IPS\Db::i()->query(
                "ALTER TABLE gd_dealer_listings " .
                "ADD COLUMN category VARCHAR(20) NULL DEFAULT NULL AFTER `condition`, " .
                "ADD INDEX idx_category (category)"
            );

            try { \IPS\Log::log( 'Added gd_dealer_listings.category column + index.', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'addCategoryColumnToListings failed: ' . $e->getMessage(), 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
        }
    }

    /**
     * Create gd_dealer_category_map table if missing.
     */
    protected function createCategoryMapTable(): void
    {
        try
        {
            $exists = FALSE;
            foreach ( \IPS\Db::i()->query( "SHOW TABLES LIKE 'gd_dealer_category_map'" ) as $row )
            {
                $exists = TRUE;
                break;
            }

            if ( $exists )
            {
                try { \IPS\Log::log( 'gd_dealer_category_map already exists.', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
                return;
            }

            \IPS\Db::i()->query(
                "CREATE TABLE gd_dealer_category_map (" .
                "  id INT UNSIGNED NOT NULL AUTO_INCREMENT, " .
                "  dealer_id INT UNSIGNED NOT NULL DEFAULT 0, " .
                "  source_value VARCHAR(255) NOT NULL DEFAULT '', " .
                "  canonical VARCHAR(20) NOT NULL DEFAULT 'accessory', " .
                "  auto_mapped TINYINT UNSIGNED NOT NULL DEFAULT 1, " .
                "  occurrence_count INT UNSIGNED NOT NULL DEFAULT 0, " .
                "  first_seen DATETIME NOT NULL, " .
                "  last_seen DATETIME NOT NULL, " .
                "  PRIMARY KEY (id), " .
                "  UNIQUE KEY uq_dealer_source (dealer_id, source_value), " .
                "  KEY idx_dealer (dealer_id), " .
                "  KEY idx_canonical (canonical), " .
                "  KEY idx_auto (auto_mapped)" .
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            try { \IPS\Log::log( 'Created gd_dealer_category_map table.', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'createCategoryMapTable failed: ' . $e->getMessage(), 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
        }
    }

    /**
     * Relax Validator's category rule: unknown -> warning, not error.
     */
    protected function relaxValidatorCategory(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/sources/Feed/Validator.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        if ( strpos( $contents, 'v188-category-map' ) !== false )
        {
            try { \IPS\Log::log( 'Validator already patched (v188 marker).', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
            return;
        }

        $needle =
            "if ( !in_array( \$cat, self::VALID_CATEGORIES, true ) )\n" .
            "                {\n" .
            "                    \$errors[] = [ 'row' => \$row, 'upc' => \$upc, 'field' => 'category', 'message' => \"Invalid category '{\$cat}'. Must be one of: \" . implode( ', ', self::VALID_CATEGORIES ) ];\n" .
            "                    \$rowHadError = true;\n" .
            "                    \$cat = '';\n" .
            "                }";

        $replacement =
            "if ( !in_array( \$cat, self::VALID_CATEGORIES, true ) )\n" .
            "                {\n" .
            "                    /* v188-category-map: unknown raw categories are normalized at\n" .
            "                     * import time via gd_dealer_category_map. Emit a warning, not\n" .
            "                     * an error - the importer will auto-classify (and the dealer\n" .
            "                     * can review on the Categories tab). */\n" .
            "                    \$warnings[] = [ 'row' => \$row, 'upc' => \$upc, 'field' => 'category', 'message' => \"Category '{\$cat}' will be auto-classified. Review on the Categories tab after import.\" ];\n" .
            "                    \$rowHadWarning = true;\n" .
            "                    \$cat = '';\n" .
            "                }";

        if ( strpos( $contents, $needle ) === false )
        {
            try { \IPS\Log::log( 'Validator category needle not found. Skipping patch.', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
            return;
        }

        $contents = str_replace( $needle, $replacement, $contents );
        file_put_contents( $path, $contents );

        try { \IPS\Log::log( 'Patched Validator.php category rule (v188).', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
    }

    /**
     * Retry v187's setupwizard.php REQ_DERIVED guard with correct needle.
     */
    protected function retrySetupWizardDerivedGuard(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/modules/front/dealers/setupwizard.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        if ( strpos( $contents, 'v188-derived-guard' ) !== false )
        {
            try { \IPS\Log::log( 'setupwizard.php already patched with v188-derived-guard.', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
            return;
        }

        $pattern = '/(foreach \( CanonicalFields::all\(\) as \$slug => \$field \)\s*\n\s*\{\s*\n)(?!\s*\/\* v18[78]-)/';
        $replacement = "$1            /* v188-derived-guard: REQ_DERIVED fields don't appear in the form. */\n" .
                       "            if ( ( \$field['req'] ?? '' ) === CanonicalFields::REQ_DERIVED ) { continue; }\n";

        $patched = preg_replace( $pattern, $replacement, $contents, -1, $count );

        if ( $count === 0 || $patched === null )
        {
            try { \IPS\Log::log( 'setupwizard.php: no CanonicalFields loops matched the v188 pattern (count=' . $count . ').', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
            return;
        }

        file_put_contents( $path, $patched );

        try { \IPS\Log::log( 'Patched setupwizard.php (' . $count . ' loop(s) guarded for v188).', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
    }

    /**
     * Add records_skipped key to Importer::run() stats array.
     */
    protected function bumpImporterStubCounter(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/sources/Feed/Importer.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        if ( strpos( $contents, 'v188-records-skipped' ) !== false )
        {
            try { \IPS\Log::log( 'Importer.php already has v188-records-skipped marker.', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
            return;
        }

        $needle = "'records_unmatched' => 0,";
        $replacement =
            "'records_unmatched' => 0,\n" .
            "                                /* v188-records-skipped: dealer category map can flag records to skip. */\n" .
            "                                'records_skipped'   => 0,";

        $count = substr_count( $contents, $needle );
        if ( $count !== 1 )
        {
            try { \IPS\Log::log( 'Importer.php records_unmatched needle count=' . $count . '. Skipping counter add.', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
            return;
        }

        $contents = str_replace( $needle, $replacement, $contents );
        file_put_contents( $path, $contents );

        try { \IPS\Log::log( 'Patched Importer.php stats array to include records_skipped (v188).', 'gddealer_upg_10188' ); } catch ( \Throwable ) {}
    }
}

class upgrade extends _upgrade {}
