<?php
namespace IPS\gddealer\setup\upg_10167;

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
        /* Task class signatures were patched at build time. The class
         * file changes are already on disk after the patch_tasks.php run.
         * This step ALSO normalizes the core_tasks frequency column for
         * any install where IPS seeded the abbreviated form (PT15M, P1D)
         * instead of the expanded form IPS\DateInterval expects, AND
         * resets next_run + last_run + running so stuck tasks fire
         * immediately on the next scheduler tick. */

        try
        {
            /* Normalize 15-min task frequency. */
            \IPS\Db::i()->update( 'core_tasks',
                [
                    'frequency' => 'P0Y0M0DT0H15M0S',
                    'next_run'  => time(),
                    'last_run'  => 0,
                    'running'   => 0,
                    'lock_count'=> 0,
                ],
                [ '`key`=? AND `app`=?', 'DealerImportFeeds', 'gddealer' ]
            );

            /* Normalize daily-task frequencies. */
            \IPS\Db::i()->update( 'core_tasks',
                [
                    'frequency' => 'P0Y0M1DT0H0M0S',
                    'next_run'  => time(),
                    'last_run'  => 0,
                    'running'   => 0,
                    'lock_count'=> 0,
                ],
                [ '`key` IN (?,?,?) AND `app`=?',
                  'ExpireTrials', 'ResolveExpiredDisputes', 'ComputeRankSnapshots',
                  'gddealer' ]
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'v1.0.167 frequency normalize failed: ' . $e->getMessage(), 'gddealer_upg_10167' ); } catch ( \Throwable ) {}
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
        return 'Fix task execute() return type signatures (PHP 8 compat) + normalize task frequencies in core_tasks';
    }
}

class upgrade extends _upgrade {}
