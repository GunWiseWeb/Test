<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.164 PART 1 of 1 - Reword step 1 mode tiles + intro text to make
 * the three options easier to understand for first-time dealers.
 *
 * Goal: dealers should immediately understand:
 *   - URL = automatic ongoing imports
 *   - Upload = save a file, scheduler imports it, repeat for updates
 *   - Paste = save your pasted content, scheduler imports it, repeat
 *
 * The two "manual" modes (upload, paste) now have parallel structure
 * that clearly says "we save -> scheduler imports -> re-do from Feed
 * Settings when your inventory changes."
 *
 * Also: now that paste is functionally an upload, the "Feed body" label
 * becomes "Paste your feed content" and the help text clarifies that
 * pasting acts the same as uploading.
 */

function gd164_patchTemplate( string $name, array $replacements ): void
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
        try { \IPS\Log::log( "v1.0.164 fetch template {$name} failed: " . $e->getMessage(), 'gddealer_upg_10164' ); } catch ( \Throwable ) {}
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
        try { \IPS\Log::log( "v1.0.164 update template {$name} failed: " . $e->getMessage(), 'gddealer_upg_10164' ); } catch ( \Throwable ) {}
    }
}

gd164_patchTemplate( 'setupWizardStep1', [
    /* Intro - clarify three options up front */
    "Choose how you'd like to provide your product feed. If you have a public URL where your feed lives, use Fetch Mode. If you're still setting things up and want to test with a local sample, use Paste or Upload Mode."
        => "Choose how you want to give us your product feed. We support automatic syncing from a public URL, or one-time delivery via file upload or pasted content. The latter two options can be repeated whenever your inventory changes.",

    /* URL tile - clarify it's the automatic option */
    "We pull the feed from a public HTTPS URL on a schedule. Best for production."
        => "We fetch your feed from a public URL on a schedule. Best if your platform exposes a feed URL (Magento, WooCommerce, RSR, custom).",

    /* Paste tile - clarify it's the same as upload, just typed in */
    "Paste a sample directly. Use this for testing if your feed isn't deployed yet."
        => "Paste your feed content directly. We save it and import on the next scheduled run. Re-paste from the wizard or upload from Feed Settings when your inventory changes.",

    /* Upload tile - clarify it's manual but works the same way */
    "Upload a feed file from your computer. Best for dealers without API access (BigCommerce, Square, Shopify Lite)."
        => "Upload a feed file from your computer. We save it and import on the next scheduled run. Re-upload from Feed Settings when your inventory changes. Best if your platform has no public feed URL (BigCommerce, Square, Shopify Lite).",

    /* Paste pane heading */
    'Paste Feed Body'
        => 'Paste Feed Content',

    /* Paste pane field label */
    'Feed body <span class="gdSetupWizard__req">*</span>'
        => 'Feed content <span class="gdSetupWizard__req">*</span>',

    /* Paste pane help text - clarify what happens to the pasted content */
    "Paste up to 10 MB. We'll parse this and discover your fields in the next step."
        => "Paste up to 10 MB. We'll save what you paste and import it on the next scheduled run, the same as if you'd uploaded a file.",

    /* Paste pane placeholder - leave as-is, it's already clear */
] );
