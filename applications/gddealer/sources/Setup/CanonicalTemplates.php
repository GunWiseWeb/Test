<?php
/**
 * @brief       GD Dealer Manager — Canonical Templates registry
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.213
 *
 * Maps 12 frequently-edited templates to the canonical overlay file(s)
 * that produce their correct body. `ensure()` re-runs those overlay
 * files. Used at the end of install.php (for fresh installs) and at
 * the end of every upgrade.php from v213 onward.
 *
 * To change a managed template body:
 *   1. Edit the relevant overlay file (the one in SOURCES below)
 *   2. Bump version, ship
 *   3. ensure() will re-run the overlay automatically on the upgrade
 *
 * If a new overlay version is added for a template:
 *   1. Update the SOURCES entry to point to the NEW overlay file
 *   2. The old overlay file stays on disk (history) but is no longer
 *      referenced by ensure().
 *
 * DO NOT embed template bodies in this class. The overlay files are
 * the source of truth.
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
     * Map: template_name => [list of overlay files (relative to setup/)
     *                       to require in order, last-wins].
     *
     * Empty array means the template's body is already canonical in
     * install.php's $gddealerTemplates array — no overlay needed.
     */
    public const SOURCES = [
        'overview'            => [],
        'unmatched'           => [],

        'feedSettings'        => [ 'templates_10158_part3.php', 'templates_10158_part4.php' ],
        'listings'            => [ 'templates_10074.php' ],
        'analytics'           => [ 'templates_10078.php' ],
        'dealerNavIcon'       => [ 'templates_10149_part3.php' ],
        'dealerShell'         => [ 'templates_10071.php' ],
        'dealerSidebar'       => [ 'templates_10071.php' ],
        'dashboardCustomize'  => [ 'templates_10088.php' ],
        'dealerProfile'       => [ 'templates_10298.php' ],
        'help'                => [ 'templates_10147_help.php' ],
        'supportTicketView'   => [ 'templates_10213_supportTicketView.php' ],
    ];

    /**
     * Re-run every canonical overlay file in order. Deduped — files
     * shared across templates (e.g. templates_10071 serves both
     * dealerShell and dealerSidebar) run once.
     *
     * @return array{ran: string[], errors: string[]}
     */
    public static function ensure(): array
    {
        $setupDir = \IPS\ROOT_PATH . '/applications/gddealer/setup';
        $ran      = [];
        $errors   = [];

        $files = [];
        foreach ( self::SOURCES as $tpl => $list ) {
            foreach ( $list as $f ) {
                if ( !in_array( $f, $files, true ) ) {
                    $files[] = $f;
                }
            }
        }

        foreach ( $files as $relPath ) {
            $absPath = $setupDir . '/' . $relPath;
            if ( !is_readable( $absPath ) ) {
                $errors[] = "missing: $relPath";
                continue;
            }
            try {
                require $absPath;
                $ran[] = $relPath;
            }
            catch ( \Throwable $e ) {
                $errors[] = "$relPath: " . $e->getMessage();
            }
        }

        try {
            \IPS\Log::log(
                'CanonicalTemplates::ensure ran=' . count($ran)
                . ' errors=' . count($errors)
                . ( !empty($errors) ? ' details: ' . implode('; ', $errors) : '' ),
                'gddealer_canonical_templates'
            );
        } catch ( \Throwable ) {}

        return [ 'ran' => $ran, 'errors' => $errors ];
    }

    /**
     * Hard-force a template to its canonical body. Used as a final
     * safety net after ensure(). Reads body from the source overlay
     * file (parses its nowdoc), then does REPLACE INTO with set_id=1.
     * Deletes stray rows at other set_ids. Logs byte counts.
     */
    public static function verifyAndForce(): array
    {
        $setupDir = \IPS\ROOT_PATH . '/applications/gddealer/setup';
        $results = [];

        $forced = [
            'dealerNavIcon'      => [ 'templates_10149_part3.php', 'front', 'dealers', '$icon' ],
            'feedSettings'       => [ 'templates_10158_part4.php', 'front', 'dealers', '$data' ],
            'listings'           => [ 'templates_10074.php',       'front', 'dealers', '$data' ],
            'analytics'          => [ 'templates_10078.php',       'front', 'dealers', '$data' ],
            'dashboardCustomize' => [ 'templates_10088.php',       'front', 'dealers', '$data' ],
            'dealerProfile'      => [ 'templates_10298.php',       'front', 'dealers', '$data' ],
            'help'               => [ 'templates_10147_help.php',  'front', 'dealers', '$data' ],
        ];

        foreach ( $forced as $name => [ $sourceFile, $location, $group, $dataSig ] ) {
            $path = $setupDir . '/' . $sourceFile;
            if ( !is_readable( $path ) ) {
                $results[ $name ] = [ 'status' => 'missing_source', 'bytes' => 0 ];
                continue;
            }

            $src = file_get_contents( $path );

            $body = null;
            if ( preg_match( "/\\\$\\w+Tpl\\s*=\\s*<<<'TEMPLATE_EOT'\\n(.*?)\\nTEMPLATE_EOT;/s", $src, $m ) ) {
                $body = $m[1];
            }
            elseif ( preg_match( "/'template_name'\\s*=>\\s*'" . preg_quote( $name, '/' ) . "'.*?'template_content'\\s*=>\\s*<<<'TEMPLATE_EOT'\\n(.*?)\\nTEMPLATE_EOT[,;]/s", $src, $m ) ) {
                $body = $m[1];
            }
            elseif ( preg_match( "/'template_content'\\s*=>\\s*<<<'TEMPLATE_EOT'\\n(.*?)\\nTEMPLATE_EOT[,;]/s", $src, $m ) ) {
                $body = $m[1];
            }

            if ( $body === null || $body === '' ) {
                $results[ $name ] = [ 'status' => 'parse_failed', 'bytes' => 0 ];
                continue;
            }

            try {
                \IPS\Db::i()->delete( 'core_theme_templates', [
                    'template_app=? AND template_location=? AND template_group=? AND template_name=? AND template_set_id<>?',
                    'gddealer', $location, $group, $name, 1
                ] );
            } catch ( \Throwable ) {}

            try {
                \IPS\Db::i()->replace( 'core_theme_templates', [
                    'template_set_id'   => 1,
                    'template_app'      => 'gddealer',
                    'template_location' => $location,
                    'template_group'    => $group,
                    'template_name'     => $name,
                    'template_data'     => $dataSig,
                    'template_content'  => $body,
                    'template_updated'  => time(),
                    'template_version'  => '1.0.214',
                ] );
                $results[ $name ] = [ 'status' => 'written', 'bytes' => strlen( $body ) ];
            } catch ( \Throwable $e ) {
                $results[ $name ] = [ 'status' => 'replace_failed', 'bytes' => strlen( $body ), 'error' => $e->getMessage() ];
            }
        }

        try {
            $summary = '';
            foreach ( $results as $n => $r ) {
                $summary .= "$n=" . $r['bytes'] . '/' . $r['status'] . ' ';
            }
            \IPS\Log::log( 'CanonicalTemplates::verifyAndForce ' . trim( $summary ), 'gddealer_canonical_templates' );
        } catch ( \Throwable ) {}

        return $results;
    }

    /**
     * Clear template-related caches. Call after ensure().
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
