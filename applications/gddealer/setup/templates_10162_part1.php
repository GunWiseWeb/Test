<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.162 - Hotfix for the step 1 template signature.
 *
 * v161 set template_data to '$wizardData,$values,$errors' but the
 * controller calls setupWizardStep1() with only 2 args in the success
 * path (initial step 1 render). The compiled template function had
 * $errors as a required parameter -> "Too few arguments" runtime error
 * -> IPS shows the generic "template is throwing an error" message.
 *
 * Fix: change the signature to '$wizardData,$values,$errors=array()'
 * (matches the original v149/v152 signature). The template body
 * doesn't need any changes - it already handles $errors being empty
 * via {{if count($errors) > 0}}.
 */

try
{
    /* Pull the existing step 1 template content (keep body + CSS as
     * v161 left it). Just update the template_data column. */
    $existing = \IPS\Db::i()->select( 'template_content', 'core_theme_templates',
        [ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
          'gddealer', 'front', 'dealers', 'setupWizardStep1' ]
    )->first();

    \IPS\Db::i()->update( 'core_theme_templates',
        [
            'template_data'    => '$wizardData,$values,$errors=array()',
            'template_content' => (string) $existing,
            'template_updated' => time(),
        ],
        [ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
          'gddealer', 'front', 'dealers', 'setupWizardStep1' ]
    );
}
catch ( \Throwable $e )
{
    try { \IPS\Log::log( 'templates_10162_part1.php update failed: ' . $e->getMessage(), 'gddealer_upg_10162' ); }
    catch ( \Throwable ) {}
}
