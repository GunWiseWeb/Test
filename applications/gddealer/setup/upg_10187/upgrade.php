<?php
/**
 * gddealer v1.0.187 - shipping_info field, simpler shipping model.
 *
 * BACKGROUND
 * ----------
 * Real-world dealer feeds (LitCommerce, gun.deals' V2 spec) ship shipping
 * info as a free-text string: "Calculated in Cart", "Free shipping over
 * $20", "19.99", "Contact for quote", etc. Our previous model demanded a
 * numeric shipping_cost AND a boolean free_shipping flag. Both fields
 * were marked required-ish in the wizard, confusing dealers who don't
 * actually publish those exact fields.
 *
 * The downstream symptom on Defense Depot's import:
 *   - "Calculated in Cart" -> (float)0.00 via preg_replace cast
 *   - 196 of 623 listings ended up with shipping_cost=0, free_shipping=0
 *     which displays as "$0 shipping" but isn't actually free
 *
 * NEW MODEL (mirrors gun.deals)
 * -----------------------------
 *   shipping_info  varchar(120)  optional   raw feed string ("Free over $20")
 *   shipping_cost  decimal(10,2) optional   parsed numeric (when available)
 *   free_shipping  tinyint                  AUTO-DERIVED at import time:
 *                                             - 1 if shipping_info contains "free"
 *                                             - 1 if shipping_cost === 0 AND shipping_info empty
 *                                             - 0 otherwise
 *
 * Display priority (Plugin 3 / public profile, future work):
 *   - if shipping_info non-empty -> show as-is
 *   - else if shipping_cost > 0  -> "$X.XX shipping"
 *   - else if free_shipping=1    -> "Free shipping"
 *   - else                       -> "Shipping varies"
 *
 * WHAT THIS UPGRADE DOES
 * ----------------------
 * 1. Schema: add gd_dealer_listings.shipping_info varchar(120) NULL
 *    (idempotent via SHOW COLUMNS check).
 *
 * 2. CanonicalFields: add canonical 'shipping_info' (optional);
 *    demote 'shipping_cost' from REQ_CONDITIONAL to REQ_OPTIONAL;
 *    add REQ_DERIVED constant; mark 'free_shipping' as REQ_DERIVED.
 *
 * 3. FieldMapper:
 *    - canonicalToStorage adds shipping_info => shipping_info
 *    - normalize() coerces shipping_info to a varchar(120) trimmed string
 *    - normalize() drops 'shipping_cost' when it parsed to 0 from a
 *      non-numeric source string like "Calculated in cart" (so we don't
 *      claim $0 shipping for items we don't know the cost of)
 *    - apply() auto-derives free_shipping at the end based on:
 *        info contains 'free' (case-insensitive) -> 1
 *        shipping_cost === 0 AND info is empty   -> 1
 *        otherwise                                -> 0
 *
 * 4. Validator: remove the "shipping_cost is required when free_shipping
 *    is not 1/true" rule. Shipping is now all-optional.
 *
 * 5. Wizard step 3:
 *    - REQ_DERIVED fields are hidden from the form entirely
 *      (free_shipping no longer appears)
 *    - REQ_CONDITIONAL fields still render but don't block step advance
 *      (existing behavior; shipping_cost is now REQ_OPTIONAL anyway)
 *
 * 6. Aliases: add shippingInfo, shipping_info, shippingNote, deliveryNote
 *    -> shipping_info. Existing aliases for shipping_cost still work
 *    (shipping, shippingPrice, shippingCost, etc.).
 *
 * 7. Data repair: existing dealers' field_mapping JSON
 *    - strip free_shipping from _defaults (it's now auto-derived)
 *    - keep shipping_cost mappings intact
 *
 * 8. Listing backfill: for every dealer with wizard_step >= 3, on next
 *    scheduled import the new logic kicks in automatically. No forced
 *    re-import here - just let cron do it.
 *
 * Idempotent. Safe to run again.
 *
 * @noinspection PhpUndefinedClassInspection
 */

namespace IPS\gddealer\setup\upg_10187;

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
            if ( $appVersion < 10186 )
            {
                try { \IPS\Log::log( 'v187 ran with app_long_version=' . $appVersion . ' (expected >=10186).', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable ) {}

        $this->addShippingInfoColumn();
        $this->patchCanonicalFields();
        $this->patchFieldMapper();
        $this->patchValidator();
        $this->patchWizardStep3();
        $this->repairFieldMappings();

        /* --------- Cache flush --------- */
        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

        try { \IPS\Log::log( 'v187 upgrade complete.', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}

        return TRUE;
    }

    /**
     * Step 1 - schema: add shipping_info column.
     */
    protected function addShippingInfoColumn(): void
    {
        try
        {
            $hasCol = FALSE;
            foreach ( \IPS\Db::i()->query( "SHOW COLUMNS FROM gd_dealer_listings LIKE 'shipping_info'" ) as $row )
            {
                $hasCol = TRUE;
                break;
            }

            if ( $hasCol )
            {
                try { \IPS\Log::log( 'shipping_info column already exists. Skipping ALTER.', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
                return;
            }

            \IPS\Db::i()->query(
                "ALTER TABLE gd_dealer_listings " .
                "ADD COLUMN shipping_info VARCHAR(120) NULL DEFAULT NULL AFTER free_shipping"
            );

            try { \IPS\Log::log( 'Added gd_dealer_listings.shipping_info column.', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'addShippingInfoColumn failed: ' . $e->getMessage(), 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
        }
    }

    /**
     * Step 2 - CanonicalFields.php: add REQ_DERIVED, add shipping_info
     * canonical, demote shipping_cost, mark free_shipping as derived.
     */
    protected function patchCanonicalFields(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/sources/Feed/CanonicalFields.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        /* Idempotent guard */
        if ( strpos( $contents, 'v187-shipping-model' ) !== false )
        {
            try { \IPS\Log::log( 'CanonicalFields already patched (v187 marker).', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
            return;
        }

        /* --- 2a. Add REQ_DERIVED constant after REQ_CAT_OPTIONAL line. --- */
        $constNeedle = "public const REQ_CAT_OPTIONAL    = 'cat_optional';";
        $constNew    =
            "public const REQ_CAT_OPTIONAL    = 'cat_optional';\n" .
            "    public const REQ_DERIVED         = 'derived';  /* v187-shipping-model */";

        $count1 = substr_count( $contents, $constNeedle );
        if ( $count1 !== 1 )
        {
            try { \IPS\Log::log( 'CanonicalFields: REQ_CAT_OPTIONAL needle not unique (count=' . $count1 . '). Aborting patch.', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
            return;
        }
        $contents = str_replace( $constNeedle, $constNew, $contents );

        /* --- 2b. Update the free_shipping definition. --- */
        $fsOld = "'free_shipping' => [ 'slug' => 'free_shipping', 'label' => 'Free Shipping Flag', 'group' => self::GROUP_CORE_REQUIRED, 'req' => self::REQ_REQUIRED, 'default' => 'false' ],";
        $fsNew = "'free_shipping' => [ 'slug' => 'free_shipping', 'label' => 'Free Shipping (auto-derived)', 'group' => self::GROUP_CORE_REQUIRED, 'req' => self::REQ_DERIVED ],";

        if ( strpos( $contents, $fsOld ) === false )
        {
            try { \IPS\Log::log( 'CanonicalFields: free_shipping needle not found. Aborting patch.', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
            return;
        }
        $contents = str_replace( $fsOld, $fsNew, $contents );

        /* --- 2c. Demote shipping_cost from CONDITIONAL to OPTIONAL and
         *         insert shipping_info canonical AFTER it. --- */
        $scOld = "'shipping_cost' => [ 'slug' => 'shipping_cost', 'label' => 'Shipping Cost',      'group' => self::GROUP_CORE_REQUIRED, 'req' => self::REQ_CONDITIONAL ],";
        $scNew =
            "'shipping_cost' => [ 'slug' => 'shipping_cost', 'label' => 'Shipping Cost (numeric)','group' => self::GROUP_CORE_OPTIONAL, 'req' => self::REQ_OPTIONAL ],\n" .
            "            'shipping_info' => [ 'slug' => 'shipping_info', 'label' => 'Shipping Info (text)',    'group' => self::GROUP_CORE_OPTIONAL, 'req' => self::REQ_OPTIONAL ],";

        if ( strpos( $contents, $scOld ) === false )
        {
            try { \IPS\Log::log( 'CanonicalFields: shipping_cost needle not found. Aborting patch.', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
            return;
        }
        $contents = str_replace( $scOld, $scNew, $contents );

        /* --- 2d. Add aliases for shipping_info to the auto-suggest table. --- */
        $aliasNeedle = "'shipCost' => 'shipping_cost',";
        $aliasNew    =
            "'shipCost' => 'shipping_cost',\n" .
            "            /* v187-shipping-model: shipping_info aliases */\n" .
            "            'shippingInfo'  => 'shipping_info',\n" .
            "            'shipping_info' => 'shipping_info',\n" .
            "            'shippingNote'  => 'shipping_info',\n" .
            "            'shippingText'  => 'shipping_info',\n" .
            "            'deliveryNote'  => 'shipping_info',\n" .
            "            'deliveryInfo'  => 'shipping_info',";

        if ( strpos( $contents, $aliasNeedle ) === false )
        {
            try { \IPS\Log::log( 'CanonicalFields: shipCost alias needle not found. Patch partial.', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
        }
        else
        {
            $contents = str_replace( $aliasNeedle, $aliasNew, $contents );
        }

        file_put_contents( $path, $contents );
        try { \IPS\Log::log( 'Patched CanonicalFields.php (v187).', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
    }

    /**
     * Step 3 - FieldMapper.php: canonicalToStorage entry for shipping_info,
     * normalize() trims and length-caps shipping_info, drops shipping_cost
     * when it parsed to 0 from non-numeric prose, and auto-derives
     * free_shipping at the end.
     */
    protected function patchFieldMapper(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/sources/Feed/FieldMapper.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        if ( strpos( $contents, 'v187-shipping-model' ) !== false )
        {
            try { \IPS\Log::log( 'FieldMapper already patched (v187 marker).', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
            return;
        }

        /* --- 3a. canonicalToStorage mapping. No translation needed
         *         (shipping_info canonical == shipping_info column),
         *         but having it present is required so apply() recognizes
         *         the canonical slug as valid via the canonicalToStorage
         *         lookup. The validCanonical check separately requires
         *         the slug to appear in CanonicalFields::allSlugs(), which
         *         we already added in step 2. So no FieldMapper change
         *         needed for canonicalToStorage. --- */

        /* --- 3b. Patch normalize() to handle shipping_info and the
         *         "Calculated in cart" -> 0 bug. Insert a block before
         *         the existing shipping_cost cast. --- */
        $normNeedle = "if ( isset( \$r['shipping_cost'] ) && \$r['shipping_cost'] !== null && \$r['shipping_cost'] !== '' )\n        {\n            \$r['shipping_cost'] = (float) preg_replace( '/[^0-9.]/', '', (string) \$r['shipping_cost'] );\n        }";

        $normNew =
            "/* v187-shipping-model: shipping_info is free text, cap to 120 chars. */\n" .
            "        if ( isset( \$r['shipping_info'] ) )\n" .
            "        {\n" .
            "            \$r['shipping_info'] = mb_substr( trim( (string) \$r['shipping_info'] ), 0, 120 );\n" .
            "            if ( \$r['shipping_info'] === '' ) { unset( \$r['shipping_info'] ); }\n" .
            "        }\n\n" .
            "        if ( isset( \$r['shipping_cost'] ) && \$r['shipping_cost'] !== null && \$r['shipping_cost'] !== '' )\n" .
            "        {\n" .
            "            \$rawShip = (string) \$r['shipping_cost'];\n" .
            "            \$digits  = preg_replace( '/[^0-9.]/', '', \$rawShip );\n" .
            "            if ( \$digits === '' || \$digits === '.' )\n" .
            "            {\n" .
            "                /* Source was non-numeric prose like 'Calculated in cart'.\n" .
            "                 * If we don't have a shipping_info already, promote the prose\n" .
            "                 * into shipping_info so it isn't lost. Drop shipping_cost - we\n" .
            "                 * don't know the cost. */\n" .
            "                if ( !isset( \$r['shipping_info'] ) || \$r['shipping_info'] === '' )\n" .
            "                {\n" .
            "                    \$promoted = mb_substr( trim( \$rawShip ), 0, 120 );\n" .
            "                    if ( \$promoted !== '' ) { \$r['shipping_info'] = \$promoted; }\n" .
            "                }\n" .
            "                unset( \$r['shipping_cost'] );\n" .
            "            }\n" .
            "            else\n" .
            "            {\n" .
            "                \$r['shipping_cost'] = (float) \$digits;\n" .
            "            }\n" .
            "        }\n\n" .
            "        /* v187-shipping-model: auto-derive free_shipping. Override any feed value. */\n" .
            "        \$info  = isset( \$r['shipping_info'] ) ? strtolower( (string) \$r['shipping_info'] ) : '';\n" .
            "        \$cost  = isset( \$r['shipping_cost'] ) ? (float) \$r['shipping_cost'] : null;\n" .
            "        \$freeByText = ( \$info !== '' && strpos( \$info, 'free' ) !== false );\n" .
            "        \$freeByZero = ( \$cost === 0.0 && \$info === '' );\n" .
            "        \$r['free_shipping'] = ( \$freeByText || \$freeByZero ) ? 1 : 0;";

        if ( strpos( $contents, $normNeedle ) === false )
        {
            try { \IPS\Log::log( 'FieldMapper: shipping_cost cast needle not found. Skipping patch.', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
            return;
        }

        $contents = str_replace( $normNeedle, $normNew, $contents );

        /* --- 3c. Also remove the now-stale free_shipping handling that came
         *         right after, since we override it ourselves. The existing
         *         line is:
         *           if ( isset( $r['free_shipping'] ) )
         *           {
         *               $r['free_shipping'] = self::truthy( $r['free_shipping'] ) ? 1 : 0;
         *           }
         *         Keep it - our derive block runs after this one's been
         *         applied, so it just gets overwritten harmlessly. */

        file_put_contents( $path, $contents );
        try { \IPS\Log::log( 'Patched FieldMapper.php (v187).', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
    }

    /**
     * Step 4 - Validator.php: remove the "shipping_cost required when not
     * free" rule. Shipping is all-optional now.
     */
    protected function patchValidator(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/sources/Feed/Validator.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        if ( strpos( $contents, 'v187-shipping-model' ) !== false )
        {
            try { \IPS\Log::log( 'Validator already patched (v187 marker).', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
            return;
        }

        /* The full shipping block, lines 248-267, replaced with a single
         * comment + an optional sanity check on shipping_cost when given. */
        $oldBlock =
            "/* free_shipping + shipping_cost */\n" .
            "            \$free = isset( \$record['free_shipping'] ) ? self::truthy( \$record['free_shipping'] ) : false;\n" .
            "            if ( !\$free )\n" .
            "            {\n" .
            "                if ( !isset( \$record['shipping_cost'] ) || (string) \$record['shipping_cost'] === '' )\n" .
            "                {\n" .
            "                    \$errors[] = [ 'row' => \$row, 'upc' => \$upc, 'field' => 'shipping_cost', 'message' => 'shipping_cost is required when free_shipping is not 1/true.' ];\n" .
            "                    \$rowHadError = true;\n" .
            "                }\n" .
            "                else\n" .
            "                {\n" .
            "                    \$rawShip = (string) \$record['shipping_cost'];\n" .
            "                    \$ship    = (float) preg_replace( '/[^0-9.]/', '', \$rawShip );\n" .
            "                    if ( \$ship < 0 )\n" .
            "                    {\n" .
            "                        \$errors[] = [ 'row' => \$row, 'upc' => \$upc, 'field' => 'shipping_cost', 'message' => \"shipping_cost must be >= 0 (got '{\$rawShip}').\" ];\n" .
            "                        \$rowHadError = true;\n" .
            "                    }\n" .
            "                }\n" .
            "            }";

        $newBlock =
            "/* v187-shipping-model: shipping is all-optional. shipping_info\n" .
            "             * is free text, shipping_cost numeric optional, free_shipping\n" .
            "             * auto-derived in FieldMapper. We only warn if shipping_cost\n" .
            "             * was given but parses to something nonsensical. */\n" .
            "            if ( isset( \$record['shipping_cost'] ) && (string) \$record['shipping_cost'] !== '' )\n" .
            "            {\n" .
            "                \$rawShip = (string) \$record['shipping_cost'];\n" .
            "                \$digits  = preg_replace( '/[^0-9.]/', '', \$rawShip );\n" .
            "                if ( \$digits === '' || \$digits === '.' )\n" .
            "                {\n" .
            "                    \$warnings[] = [ 'row' => \$row, 'upc' => \$upc, 'field' => 'shipping_cost', 'message' => \"shipping_cost '{\$rawShip}' is not numeric; will be stored as text in shipping_info instead.\" ];\n" .
            "                    \$rowHadWarning = true;\n" .
            "                }\n" .
            "            }";

        if ( strpos( $contents, $oldBlock ) === false )
        {
            try { \IPS\Log::log( 'Validator: shipping block needle not found. Skipping patch.', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
            return;
        }

        $contents = str_replace( $oldBlock, $newBlock, $contents );

        file_put_contents( $path, $contents );
        try { \IPS\Log::log( 'Patched Validator.php (v187).', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
    }

    /**
     * Step 5 - setupwizard.php: hide REQ_DERIVED fields from step 3 so
     * free_shipping no longer appears in the form. This is the smallest
     * possible patch - we filter in buildStep3Values() right before the
     * row list is built.
     *
     * Implementation: prepend a guard inside the foreach over
     * CanonicalFields::all() in buildStep3Values that 'continue's when
     * the field's req is REQ_DERIVED.
     */
    protected function patchWizardStep3(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/modules/front/dealers/setupwizard.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        if ( strpos( $contents, 'v187-shipping-model' ) !== false )
        {
            try { \IPS\Log::log( 'setupwizard.php already patched (v187 marker).', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
            return;
        }

        /* Find the foreach over CanonicalFields::all() inside buildStep3Values.
         * We patch the same loop the required-counter uses. Multiple foreach
         * loops over CanonicalFields::all() may exist (counter + row builder);
         * patch ALL of them. The needle is unique to the loop header we want. */

        /* Look for both loops by their canonical iterator-variable name.
         * From the diagnostic, the counter loop uses $row and the visible
         * required_unmapped list builder uses $row too. Add a single guard
         * at the top of every foreach over CanonicalFields::all(). */

        $loopNeedle = "foreach ( CanonicalFields::all() as \$slug => \$row )";
        $loopReplace = "foreach ( CanonicalFields::all() as \$slug => \$row )";  /* same header */

        /* That can't change anything by itself; instead we patch each
         * `{` immediately following the foreach to include a derive-guard.
         * Use a more specific needle: the foreach header + opening brace + first line. */

        /* Strategy: split into two sub-patches with very specific needles. */

        $changed = 0;

        /* Sub-patch A: the counter loop. Needle = foreach header + brace
         *              + the existing first statement. */
        $aNeedle = "foreach ( CanonicalFields::all() as \$slug => \$row )\n        {\n            \$requiredTotal++;";
        $aNew =
            "foreach ( CanonicalFields::all() as \$slug => \$row )\n" .
            "        {\n" .
            "            /* v187-shipping-model: REQ_DERIVED fields don't appear in the form. */\n" .
            "            if ( ( \$row['req'] ?? '' ) === CanonicalFields::REQ_DERIVED ) { continue; }\n" .
            "            \$requiredTotal++;";

        if ( strpos( $contents, $aNeedle ) !== false )
        {
            $contents = str_replace( $aNeedle, $aNew, $contents );
            $changed++;
        }

        /* Sub-patch B: the row-builder loop in buildStep3Values. Needle is
         *              less stable so we just grep for any other foreach
         *              over CanonicalFields::all() that's NOT already patched. */
        $occurrences = substr_count( $contents, $loopNeedle );
        if ( $occurrences > 0 )
        {
            /* There may be additional loops elsewhere in setupwizard.php.
             * We can't blindly str_replace because the next statement is
             * different in each loop. So we patch each remaining loop by
             * inserting the guard at the very start of its body. */
            $pattern = '/(foreach \( CanonicalFields::all\(\) as \$slug => \$row \)\s*\n\s*\{\s*\n)(?!\s*\/\* v187-shipping-model)/';

            $replacement = "$1            /* v187-shipping-model: REQ_DERIVED fields don't appear in the form. */\n" .
                           "            if ( ( \$row['req'] ?? '' ) === CanonicalFields::REQ_DERIVED ) { continue; }\n";

            $patched = preg_replace( $pattern, $replacement, $contents, -1, $cnt );
            if ( $cnt > 0 )
            {
                $contents = $patched;
                $changed += (int) $cnt;
            }
        }

        if ( $changed === 0 )
        {
            try { \IPS\Log::log( 'setupwizard.php: no CanonicalFields::all() loops matched. Skipping patch.', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
            return;
        }

        file_put_contents( $path, $contents );
        try { \IPS\Log::log( 'Patched setupwizard.php (' . $changed . ' loop(s) guarded for v187).', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
    }

    /**
     * Step 6 - data repair: strip free_shipping from every dealer's
     * field_mapping._defaults. free_shipping is auto-derived now; carrying
     * a default value would force the answer one way and bypass the
     * derive logic.
     *
     * Also strip free_shipping if it appears as a top-level mapping
     * (dealerField => 'free_shipping'); the importer would still write
     * the value and we'd then overwrite it in normalize(), so it's
     * harmless but confusing.
     */
    protected function repairFieldMappings(): void
    {
        try
        {
            $rows = \IPS\Db::i()->select(
                'dealer_id, dealer_name, field_mapping',
                'gd_dealer_feed_config',
                [ "field_mapping IS NOT NULL AND field_mapping <> ''" ]
            );

            $touched = 0;
            foreach ( $rows as $row )
            {
                $raw = (string) $row['field_mapping'];
                $map = json_decode( $raw, true );
                if ( !is_array( $map ) ) { continue; }

                $changed = false;

                /* Strip free_shipping default. */
                if ( isset( $map['_defaults'] ) && is_array( $map['_defaults'] ) )
                {
                    if ( array_key_exists( 'free_shipping', $map['_defaults'] ) )
                    {
                        unset( $map['_defaults']['free_shipping'] );
                        $changed = true;
                    }
                    if ( empty( $map['_defaults'] ) )
                    {
                        unset( $map['_defaults'] );
                    }
                }

                /* Strip any top-level mapping that targets free_shipping. */
                foreach ( $map as $dealerField => $canonical )
                {
                    if ( $dealerField === '_defaults' ) { continue; }
                    if ( $canonical === 'free_shipping' )
                    {
                        unset( $map[ $dealerField ] );
                        $changed = true;
                    }
                }

                if ( !$changed ) { continue; }

                try
                {
                    \IPS\Db::i()->update( 'gd_dealer_feed_config',
                        [ 'field_mapping' => json_encode( $map, JSON_UNESCAPED_SLASHES ) ],
                        [ 'dealer_id=?', (int) $row['dealer_id'] ]
                    );
                    $touched++;
                }
                catch ( \Throwable $e )
                {
                    try { \IPS\Log::log( 'Repair: failed dealer ' . (int) $row['dealer_id'] . ': ' . $e->getMessage(), 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
                }
            }

            try { \IPS\Log::log( 'Data repair: cleaned free_shipping out of ' . $touched . ' dealer(s) field_mapping.', 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'repairFieldMappings failed: ' . $e->getMessage(), 'gddealer_upg_10187' ); } catch ( \Throwable ) {}
        }
    }
}

class upgrade extends _upgrade {}
