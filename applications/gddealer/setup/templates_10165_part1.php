<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.165 - Update URL-mode tile platform list.
 *
 * Removed:
 *   - RSR (B2B distributor, not a platform with a customer feed URL)
 *
 * Added the actual platforms that expose product feed URLs:
 *   Magento, WooCommerce, Shopify, Coreware, Gearfire, AmmoReady,
 *   plus a "custom" catch-all for everything else.
 *
 * Pure template content patch, no controller changes.
 */

try
{
    $existing = (string) \IPS\Db::i()->select( 'template_content', 'core_theme_templates',
        [ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
          'gddealer', 'front', 'dealers', 'setupWizardStep1' ]
    )->first();
}
catch ( \Throwable $e )
{
    try { \IPS\Log::log( 'v1.0.165 fetch template failed: ' . $e->getMessage(), 'gddealer_upg_10165' ); } catch ( \Throwable ) {}
    return;
}

$replacements = [
    /* v164 wording (current production) */
    'We fetch your feed from a public URL on a schedule. Best if your platform exposes a feed URL (Magento, WooCommerce, RSR, custom).'
        => 'We fetch your feed from a public URL on a schedule. Best if your platform exposes a feed URL — Magento, WooCommerce, Shopify, Coreware, Gearfire, AmmoReady, or custom integrations.',

    /* v152 wording (in case it's still there from older state) */
    'We pull the feed from a public HTTPS URL on a schedule. Best for production.'
        => 'We fetch your feed from a public URL on a schedule. Best if your platform exposes a feed URL — Magento, WooCommerce, Shopify, Coreware, Gearfire, AmmoReady, or custom integrations.',
];

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
          'gddealer', 'front', 'dealers', 'setupWizardStep1' ]
    );
}
catch ( \Throwable $e )
{
    try { \IPS\Log::log( 'v1.0.165 update template failed: ' . $e->getMessage(), 'gddealer_upg_10165' ); } catch ( \Throwable ) {}
}
