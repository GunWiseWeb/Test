<?php
/**
 * @brief       GD Dealer Manager — Canonical Templates enforcement
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.212
 *
 * Single source of truth for the 10 most-volatile gddealer templates.
 * Loads body content from data/canonical_templates/*.tpl files and
 * forces core_theme_templates rows to match.
 *
 * Called by install.php at the BOTTOM of the fresh-install cascade,
 * AND by every upgrade.php from v212 onward, as the final step. This
 * guarantees that no matter which install/upgrade path runs, these
 * templates always end up with the canonical body content.
 *
 * To update a canonical template body: edit the corresponding
 * .tpl file in data/canonical_templates/ and bump version. That's
 * the only place to edit. Old overlay files and install.php's
 * $gddealerTemplates array entries are ignored (their bodies still
 * get written first, but this class overwrites them at the end).
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
     * The 10 templates managed by this class.
     *
     * Each entry maps template_name to expected template_data signature.
     * Bodies are loaded from data/canonical_templates/{name}.tpl
     */
    public const TEMPLATES = [
        'feedSettings'        => '$data',
        'overview'            => '$data',
        'listings'            => '$data',
        'analytics'           => '$data',
        'dealerNavIcon'       => '$icon',
        'dealerShell'         => '$dealer, $activeTab, $nav, $body',
        'dealerSidebar'       => '$dealer, $activeTab, $nav',
        'dashboardCustomize'  => '$data',
        'dealerProfile'       => '$data',
        'unmatched'           => '$data',
    ];

    /**
     * Force every managed template's core_theme_templates row to match
     * its corresponding .tpl file. Idempotent. Safe to call repeatedly.
     *
     * Returns a summary array: ['written' => N, 'skipped' => N, 'errors' => [...]]
     */
    public static function ensure(): array
    {
        $dir = \IPS\ROOT_PATH . '/applications/gddealer/data/canonical_templates';
        $written = 0;
        $skipped = 0;
        $errors  = [];

        foreach ( self::TEMPLATES as $name => $sig )
        {
            $path = $dir . '/' . $name . '.tpl';

            if ( !is_readable( $path ) )
            {
                $errors[] = "missing or unreadable: $path";
                continue;
            }

            $body = file_get_contents( $path );
            if ( $body === false || $body === '' )
            {
                $errors[] = "empty body: $path";
                continue;
            }

            try
            {
                /* Drop any set_id=0 stray row that could compete with set_id=1 */
                try {
                    \IPS\Db::i()->delete( 'core_theme_templates', [
                        'template_app=? AND template_location=? AND template_group=? AND template_name=? AND template_set_id=?',
                        'gddealer', 'front', 'dealers', $name, 0
                    ] );
                } catch ( \Throwable ) {}

                \IPS\Db::i()->replace( 'core_theme_templates', [
                    'template_set_id'   => 1,
                    'template_app'      => 'gddealer',
                    'template_location' => 'front',
                    'template_group'    => 'dealers',
                    'template_name'     => $name,
                    'template_data'     => $sig,
                    'template_content'  => $body,
                    'template_updated'  => time(),
                    'template_version'  => '1.0.212',
                ] );
                $written++;
            }
            catch ( \Throwable $e )
            {
                $errors[] = "$name: " . $e->getMessage();
            }
        }

        try {
            \IPS\Log::log(
                "CanonicalTemplates::ensure() — written=$written skipped=$skipped errors=" . count($errors),
                'gddealer_canonical_templates'
            );
        } catch ( \Throwable ) {}

        return [ 'written' => $written, 'skipped' => $skipped, 'errors' => $errors ];
    }

    /**
     * Clear template-related caches. Call after ensure() if invoked
     * outside the IPS upgrade flow (which already busts caches).
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
