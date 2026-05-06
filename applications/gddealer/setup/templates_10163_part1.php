<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.163 PART 1 of 1 - Update step 1 + feedSettings template content
 * to drop TXT from the upload widgets' accept attribute and hint text.
 *
 * Template body changes are SAFE because we only modify the upload pane
 * markup, not the surrounding form structure.
 */

function gd163_patchTemplate( string $name, array $replacements ): void
{
    try
    {
        $existing = (string) \IPS\Db::i()->select( 'template_content', 'core_theme_templates',
            [ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
              'gddealer', 'front', 'dealers', $name ]
        )->first();
    }
    catch ( \Throwable $e )
    {
        try { \IPS\Log::log( "v1.0.163 fetch template {$name} failed: " . $e->getMessage(), 'gddealer_upg_10163' ); } catch ( \Throwable ) {}
        return;
    }

    $updated = $existing;
    foreach ( $replacements as $old => $new )
    {
        if ( str_contains( $updated, $old ) )
        {
            $updated = str_replace( $old, $new, $updated );
        }
    }

    if ( $updated === $existing )
    {
        return;
    }

    try
    {
        \IPS\Db::i()->update( 'core_theme_templates',
            [
                'template_content' => $updated,
                'template_updated' => time(),
            ],
            [ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
              'gddealer', 'front', 'dealers', $name ]
        );
    }
    catch ( \Throwable $e )
    {
        try { \IPS\Log::log( "v1.0.163 update template {$name} failed: " . $e->getMessage(), 'gddealer_upg_10163' ); } catch ( \Throwable ) {}
    }
}

/* Step 1 (wizard upload pane) */
gd163_patchTemplate( 'setupWizardStep1', [
    'accept=".csv,.xml,.json,.tsv,.txt"' => 'accept=".csv,.xml,.json,.tsv"',
    'CSV, XML, JSON, TSV, TXT &middot; up to 50 MB' => 'CSV, XML, JSON, TSV &middot; up to 50 MB',
] );

/* Feed Settings (manual upload widget) */
gd163_patchTemplate( 'feedSettings', [
    'accept=".csv,.xml,.json,.tsv,.txt"' => 'accept=".csv,.xml,.json,.tsv"',
    'CSV, XML, JSON, TSV, or TXT'        => 'CSV, XML, JSON, or TSV',
    'CSV, XML, JSON, TSV, TXT'           => 'CSV, XML, JSON, TSV',
] );
