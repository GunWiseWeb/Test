<?php
namespace IPS\gddealer\setup\upg_10160;

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
        /* Controller patch (remove completed-dealer redirect, add
         * is_completed values) was applied at build time. Patched
         * controller on disk. Just seed the updated template + clear
         * caches. */
        try
        {
            require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10160_part1.php';
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'v1.0.160 templates failed: ' . $e->getMessage(), 'gddealer_upg_10160' ); } catch ( \Throwable ) {}
        }

        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}

        foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*dealers*' ) ?: [] as $f )
        {
            @unlink( $f );
        }

        try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
        try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
        try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

        try
        {
            if ( method_exists( '\IPS\Theme', 'deleteCompiledTemplate' ) )
            {
                \IPS\Theme::deleteCompiledTemplate( 'gddealer', 'front', 'dealers' );
            }
        }
        catch ( \Throwable ) {}

        return TRUE;
    }

    public function step1CustomTitle()
    {
        return 'Allow completed dealers to re-enter the wizard; step 5 adapts framing for revisits';
    }
}

class upgrade extends _upgrade {}

