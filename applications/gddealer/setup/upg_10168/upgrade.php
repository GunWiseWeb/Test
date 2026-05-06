<?php
namespace IPS\gddealer\setup\upg_10168;

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
        /* Source patch (UnmatchedUpc::sweepMatched fix) was applied at
         * build time. Patched source on disk.
         *
         * Also: clean up failed import_log rows that came from the v167
         * "first time imports actually ran but hit the rowCount bug"
         * window, so the dashboard health card doesn't permanently
         * show "Last import failed". We only delete rows where the
         * error_log is exactly the rowCount-on-string error, to avoid
         * accidentally clearing legitimate failures from a later run.
         *
         * Reset gd_dealer_feed_config.last_run for any dealer whose
         * latest import was that specific error so they re-run on
         * the next scheduler tick. */
        try
        {
            \IPS\Db::i()->delete( 'gd_dealer_import_log',
                [ 'error_log=?', 'Call to a member function rowCount() on string' ]
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'v1.0.168 import log cleanup failed: ' . $e->getMessage(), 'gddealer_upg_10168' ); } catch ( \Throwable ) {}
        }

        try
        {
            \IPS\Db::i()->update( 'gd_dealer_feed_config',
                [ 'last_run' => null, 'last_run_status' => null ],
                [ 'last_run_status=?', 'failed' ]
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'v1.0.168 feed_config last_run reset failed: ' . $e->getMessage(), 'gddealer_upg_10168' ); } catch ( \Throwable ) {}
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
        return 'Fix UnmatchedUpc::sweepMatched() rowCount-on-int fatal that blocked all imports after v167';
    }
}

class upgrade extends _upgrade {}
