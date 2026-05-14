<?php
/**
 * gddealer v1.0.186 - Fix feed_delivery_mode wizard bug.
 *
 * BUG
 * ---
 * The wizard's saveStep1() in modules/front/dealers/setupwizard.php writes
 * `feed_delivery_mode = 'manual'` ONLY in the upload branch. The url and
 * paste branches never touch the column. The DB column default is 'url',
 * so first-time dealers are fine - but any dealer who ever chose 'upload'
 * is permanently stuck in 'manual' mode even if they later re-run the
 * wizard and choose 'url'. The importer reads the stale upload forever.
 *
 * REAL-WORLD IMPACT
 * -----------------
 * Defense Depot's wizard set feed_url to a 453 KB LitCommerce feed with
 * 1299 records. feed_delivery_mode stayed 'manual'. Every 15-minute import
 * read a 6 KB test file with 8 records (the first upload from weeks ago).
 * All 8 records had UPCs not in gd_catalog, so listings count stayed 0.
 *
 * FIX
 * ---
 * 1. Patch setupwizard.php saveStep1() so feed_delivery_mode is written
 *    for ALL modes (url -> 'url'; paste/upload -> 'manual').
 *
 * 2. Data repair: any dealer with feed_delivery_mode='manual' but an
 *    http(s) feed_url AND wizard_step >= 3 (meaning the wizard's URL
 *    parse step succeeded) gets flipped back to 'url'. Conservative
 *    rule - we don't touch dealers who legitimately upload files.
 *
 * Idempotent: the patch carries a v186-mode-write marker comment and
 * re-runs are no-ops.
 */

namespace IPS\gddealer\setup\upg_10186;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class _upgrade
{
    public function step1(): bool
    {
        /* --------- Sanity: previous version installed --------- */
        try
        {
            $appVersion = (int) \IPS\Db::i()->select(
                'app_long_version', 'core_applications',
                [ 'app_directory=?', 'gddealer' ]
            )->first();
            if ( $appVersion < 10185 )
            {
                try
                {
                    \IPS\Log::log(
                        'v186 ran but app_long_version=' . $appVersion . ' (expected >=10185).',
                        'gddealer_upg_10186'
                    );
                }
                catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable ) {}

        /* --------- Patch 1: setupwizard.php --------- */
        $this->patchWizard();

        /* --------- Patch 2: data repair --------- */
        $this->repairData();

        /* --------- Cache flush --------- */
        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

        try { \IPS\Log::log( 'v186 upgrade complete.', 'gddealer_upg_10186' ); } catch ( \Throwable ) {}

        return TRUE;
    }

    /**
     * Patch the wizard so it writes feed_delivery_mode for ALL modes,
     * not just upload.
     */
    protected function patchWizard(): void
    {
        $path = \IPS\ROOT_PATH .
            '/applications/gddealer/modules/front/dealers/setupwizard.php';

        if ( !is_file( $path ) )
        {
            try { \IPS\Log::log( 'setupwizard.php not found at ' . $path, 'gddealer_upg_10186' ); } catch ( \Throwable ) {}
            return;
        }

        $contents = (string) file_get_contents( $path );

        /* Idempotent guard. */
        if ( strpos( $contents, 'v186-mode-write' ) !== false )
        {
            try { \IPS\Log::log( 'setupwizard.php already patched (v186 marker present).', 'gddealer_upg_10186' ); } catch ( \Throwable ) {}
            return;
        }

        /* The existing single write line. saveStep1() is the only place
         * in the wizard where this assignment exists, so the count is 1. */
        $needle = "\$update['feed_delivery_mode'] = 'manual';";

        $count = substr_count( $contents, $needle );
        if ( $count !== 1 )
        {
            try
            {
                \IPS\Log::log(
                    'Expected exactly 1 occurrence of feed_delivery_mode write in setupwizard.php; ' .
                    'found ' . $count . '. Patch skipped.',
                    'gddealer_upg_10186'
                );
            }
            catch ( \Throwable ) {}
            return;
        }

        /* Replace the single existing write with the same write
         * plus an immediate mode-derived override.
         *
         * Order of evaluation matters: the original line runs first
         * (preserving behavior for 'upload' if the if-tree falls
         * through), then the if-tree overwrites the value based on
         * $mode. If $mode is 'upload', the elseif branch matches and
         * re-writes 'manual', which is a no-op. */
        $replacement =
            "\$update['feed_delivery_mode'] = 'manual';\n" .
            "            /* v186-mode-write: ensure feed_delivery_mode is written for ALL modes */\n" .
            "            if ( \$mode === 'url' )        { \$update['feed_delivery_mode'] = 'url'; }\n" .
            "            elseif ( \$mode === 'paste' )  { \$update['feed_delivery_mode'] = 'manual'; }\n" .
            "            elseif ( \$mode === 'upload' ) { \$update['feed_delivery_mode'] = 'manual'; }";

        $patched = str_replace( $needle, $replacement, $contents, $applied );

        if ( $applied !== 1 || $patched === $contents )
        {
            try
            {
                \IPS\Log::log(
                    'setupwizard.php str_replace failed (applied=' . $applied . ').',
                    'gddealer_upg_10186'
                );
            }
            catch ( \Throwable ) {}
            return;
        }

        $bytesBefore = strlen( $contents );
        $bytesAfter  = strlen( $patched );

        $ok = (bool) file_put_contents( $path, $patched );

        try
        {
            \IPS\Log::log(
                'Patched setupwizard.php saveStep1() (v186). Bytes ' .
                $bytesBefore . ' -> ' . $bytesAfter . '; write=' . ( $ok ? 'ok' : 'FAILED' ),
                'gddealer_upg_10186'
            );
        }
        catch ( \Throwable ) {}
    }

    /**
     * Find dealers stuck in 'manual' mode despite having a real URL
     * feed config, and flip them back to 'url'. Conservative rule:
     * only touch dealers whose wizard_step >= 3 (meaning the URL
     * parse step succeeded so the URL is known-good) AND whose
     * feed_url starts with http(s)://.
     */
    protected function repairData(): void
    {
        try
        {
            $stuck = \IPS\Db::i()->select(
                'dealer_id, dealer_name, feed_url',
                'gd_dealer_feed_config',
                [
                    "feed_delivery_mode='manual' " .
                    "AND feed_url IS NOT NULL " .
                    "AND feed_url <> '' " .
                    "AND ( feed_url LIKE 'http://%' OR feed_url LIKE 'https://%' ) " .
                    "AND wizard_step >= 3"
                ]
            );

            $fixed = 0;
            $names = [];
            foreach ( $stuck as $row )
            {
                try
                {
                    \IPS\Db::i()->update( 'gd_dealer_feed_config',
                        [ 'feed_delivery_mode' => 'url' ],
                        [ 'dealer_id=?', (int) $row['dealer_id'] ]
                    );
                    $fixed++;
                    $names[] = '#' . (int) $row['dealer_id'] . ' (' . (string) $row['dealer_name'] . ')';
                }
                catch ( \Throwable $e )
                {
                    try
                    {
                        \IPS\Log::log(
                            'Failed to repair dealer ' . (int) $row['dealer_id'] . ': ' . $e->getMessage(),
                            'gddealer_upg_10186'
                        );
                    }
                    catch ( \Throwable ) {}
                }
            }

            try
            {
                \IPS\Log::log(
                    'Data repair: flipped ' . $fixed . ' dealer(s) from manual to url mode' .
                    ( $fixed > 0 ? '. Dealers: ' . implode( ', ', $names ) : '.' ),
                    'gddealer_upg_10186'
                );
            }
            catch ( \Throwable ) {}
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'Data repair query failed: ' . $e->getMessage(), 'gddealer_upg_10186' ); } catch ( \Throwable ) {}
        }
    }
}

class upgrade extends _upgrade {}
