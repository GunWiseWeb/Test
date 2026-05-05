<?php
/**
 * v1.0.157 patch script - one-line fix for the missing FieldMapper
 * import in the wizard controller.
 *
 * In v155 I added "use ...Validator;" alongside FieldMapper expecting
 * FieldMapper was already imported. It wasn't. The wizard never called
 * FieldMapper before v155, so there was no use statement to begin with.
 * Result: runStep4Validation() throws "Class FieldMapper not found"
 * because PHP looks in the controller's namespace
 * (IPS\gddealer\modules\front\dealers) instead of the Feed namespace.
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10157/patch_controller.php
 *
 * Idempotent.
 */

$path = dirname( __DIR__, 2 ) . '/modules/front/dealers/setupwizard.php';

if ( !file_exists( $path ) )
{
    fwrite( STDERR, "ERROR: setupwizard.php not found at {$path}\n" );
    exit( 1 );
}

$content = file_get_contents( $path );
if ( $content === false )
{
    fwrite( STDERR, "ERROR: could not read {$path}\n" );
    exit( 1 );
}

$applied = 0;

$old = "use IPS\\gddealer\\Feed\\CanonicalFields;
use IPS\\gddealer\\Feed\\Validator;";
$new = "use IPS\\gddealer\\Feed\\CanonicalFields;
use IPS\\gddealer\\Feed\\FieldMapper;
use IPS\\gddealer\\Feed\\Validator;";

if ( str_contains( $content, "use IPS\\gddealer\\Feed\\FieldMapper;" ) )
{
    echo "  patch (FieldMapper use): already applied\n";
}
elseif ( str_contains( $content, $old ) )
{
    $content = str_replace( $old, $new, $content );
    echo "  patch (FieldMapper use): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: anchor not found - is the v155+ controller in place?\n" );
    exit( 1 );
}

if ( $applied === 0 )
{
    echo "All patches already applied. No changes written.\n";
    exit( 0 );
}

if ( file_put_contents( $path, $content ) === false )
{
    fwrite( STDERR, "ERROR: failed to write {$path}\n" );
    exit( 1 );
}

$lint = shell_exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1' );
echo "Lint: " . trim( (string) $lint ) . "\n";

if ( !str_contains( (string) $lint, 'No syntax errors' ) )
{
    fwrite( STDERR, "WARNING: lint failed\n" );
    exit( 1 );
}

exit( 0 );
