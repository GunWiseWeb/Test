<?php
/**
 * @brief       GD Dealer Manager — Canonical Templates registry
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.213
 *
 * The authoritative template bodies live in dev/html/front/dealers/*.phtml.
 * IPS reads those files directly in dev mode.
 *
 * Historical overlay files (setup/templates_100XX.php) remain on disk for
 * reference but are NO LONGER executed by ensure(). The overlay-write
 * mechanism was the root cause of the v298 dashboard regression: stale
 * overlay content overwrote current templates.
 *
 * ensure() now PURGES any cached .tpl files from data/canonical_templates/
 * and clears IPS template caches, forcing IPS to read from dev/html.
 */

namespace IPS\gddealer\Setup;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class _CanonicalTemplates
{
    /**
     * Purge stale .tpl cache files from data/canonical_templates/.
     * IPS in dev mode reads these in preference to dev/html/*.phtml,
     * so they must not exist if dev/html is the source of truth.
     *
     * @return array{deleted: string[], errors: string[]}
     */
    public static function purgeCanonicalTemplates(): array
    {
        $dir     = \IPS\ROOT_PATH . '/applications/gddealer/data/canonical_templates';
        $deleted = [];
        $errors  = [];

        if ( !is_dir( $dir ) )
        {
            return [ 'deleted' => $deleted, 'errors' => $errors ];
        }

        $files = glob( $dir . '/*.tpl' );
        if ( !is_array( $files ) || empty( $files ) )
        {
            return [ 'deleted' => $deleted, 'errors' => $errors ];
        }

        foreach ( $files as $f )
        {
            try
            {
                if ( is_writable( $f ) )
                {
                    @unlink( $f );
                    $deleted[] = basename( $f );
                }
                else
                {
                    $errors[] = 'not_writable: ' . basename( $f );
                }
            }
            catch ( \Throwable $e )
            {
                $errors[] = basename( $f ) . ': ' . $e->getMessage();
            }
        }

        try {
            \IPS\Log::log(
                'CanonicalTemplates::purge deleted=' . count( $deleted )
                . ' errors=' . count( $errors )
                . ( !empty( $errors ) ? ' details: ' . implode( '; ', $errors ) : '' ),
                'gddealer_canonical_templates'
            );
        } catch ( \Throwable ) {}

        return [ 'deleted' => $deleted, 'errors' => $errors ];
    }

    /**
     * Ensure templates are in a clean state: purge stale .tpl caches
     * and clear IPS template caches so dev/html is the sole source.
     *
     * @return array{deleted: string[], errors: string[]}
     */
    public static function ensure(): array
    {
        $result = self::purgeCanonicalTemplates();
        self::clearCaches();
        return $result;
    }

    /**
     * Clear template-related caches.
     */
    public static function clearCaches(): void
    {
        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
        foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
        try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
        try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
        try { unset( \IPS\Data\Store::i()->themes ); }       catch ( \Throwable ) {}
        try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
    }
}

class CanonicalTemplates extends _CanonicalTemplates {}
