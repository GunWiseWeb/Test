<?php
namespace IPS\gddealer\setup\upg_10169;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class _upgrade
{
    public function step1(): bool
    {
        /* Source patches (per-class $multitons + $multitonMap) were
         * applied at build time. Just clean up any partial state from
         * the v167-v168 import attempts and clear caches. */

        try
        {
            /* Delete the broken "running" status import_log row that
             * crashed mid-flight and never finished. */
            \IPS\Db::i()->delete( 'gd_dealer_import_log',
                [ 'status=?', 'running' ]
            );

            /* Clean any other failed rows from earlier debugging. */
            \IPS\Db::i()->delete( 'gd_dealer_import_log',
                [ 'status=? AND (error_log LIKE ? OR error_log IS NULL)',
                  'failed', '%rowCount%' ]
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'v1.0.169 import_log cleanup failed: ' . $e->getMessage(), 'gddealer_upg_10169' ); } catch ( \Throwable ) {}
        }

        try
        {
            /* Reset any dealers stuck on failed status so the next
             * scheduler tick re-imports cleanly. */
            \IPS\Db::i()->update( 'gd_dealer_feed_config',
                [ 'last_run' => null, 'last_run_status' => null ],
                [ 'last_run_status=?', 'failed' ]
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'v1.0.169 feed_config reset failed: ' . $e->getMessage(), 'gddealer_upg_10169' ); } catch ( \Throwable ) {}
        }

        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}

        try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
        try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
        try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

        return TRUE;
    }

    public function step1CustomTitle()
    {
        return 'Per-class multiton cache for Dealer/ImportLog/Listing (fixes cross-class load() returning wrong type)';
    }
}

class upgrade extends _upgrade {}
