<?php
/**
 * v1.0.163 - Drop TXT from supported upload types.
 *
 * The wizard officially supports XML / JSON / CSV. TSV genuinely works
 * because CsvParser auto-detects tab vs comma delimiter from the first
 * line. But TXT is ambiguous (could be anything) and the parsers don't
 * have a sensible way to handle it.
 *
 * Three patches:
 *   1. Wizard saveStep1: drop 'txt' from createFromUploads allowed types
 *      and from the format-detection match.
 *   2. Dashboard uploadFeed: drop 'txt' from allowedFileTypes and the
 *      format-detection match (kept consistent across both upload paths).
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10163/patch_controllers.php
 *
 * Idempotent.
 */

$applied = 0;

/* =====================================================================
 * Wizard controller (setupwizard.php)
 * ===================================================================== */

$wizPath = dirname( __DIR__, 2 ) . '/modules/front/dealers/setupwizard.php';

if ( !file_exists( $wizPath ) )
{
    fwrite( STDERR, "ERROR: setupwizard.php not found at {$wizPath}\n" );
    exit( 1 );
}

$wizContent = file_get_contents( $wizPath );

/* Patch 1a: createFromUploads allowed types - drop 'txt' */
$old1a = "                \$files = \\IPS\\File::createFromUploads(
                    'gddealer_FeedUpload',
                    'upload_file',
                    [ 'csv', 'xml', 'json', 'tsv', 'txt' ],
                    50
                );";
$new1a = "                \$files = \\IPS\\File::createFromUploads(
                    'gddealer_FeedUpload',
                    'upload_file',
                    [ 'csv', 'xml', 'json', 'tsv' ],
                    50
                );";

if ( str_contains( $wizContent, "[ 'csv', 'xml', 'json', 'tsv' ],\n                    50" ) )
{
    echo "  patch 1a (wizard allowed types): already applied\n";
}
elseif ( str_contains( $wizContent, $old1a ) )
{
    $wizContent = str_replace( $old1a, $new1a, $wizContent );
    echo "  patch 1a (wizard allowed types): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 1a anchor not found in wizard\n" );
    exit( 1 );
}

/* Patch 1b: error message - mention only the kept formats */
$old1b = "                \$errors[] = 'Please choose a feed file to upload (CSV, XML, JSON, TSV, or TXT, up to 50 MB).';";
$new1b = "                \$errors[] = 'Please choose a feed file to upload (CSV, XML, JSON, or TSV, up to 50 MB).';";

if ( str_contains( $wizContent, "(CSV, XML, JSON, or TSV, up to 50 MB)" ) )
{
    echo "  patch 1b (wizard error message): already applied\n";
}
elseif ( str_contains( $wizContent, $old1b ) )
{
    $wizContent = str_replace( $old1b, $new1b, $wizContent );
    echo "  patch 1b (wizard error message): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 1b anchor not found in wizard\n" );
    exit( 1 );
}

/* Patch 1c: extension-to-format match - drop the txt path */
$old1c = "                \$uploadFormat = match ( \$ext )
                {
                    'xml'  => 'xml',
                    'json' => 'json',
                    'csv', 'tsv', 'txt' => 'csv',
                    default => 'csv',
                };";
$new1c = "                \$uploadFormat = match ( \$ext )
                {
                    'xml'  => 'xml',
                    'json' => 'json',
                    'csv', 'tsv' => 'csv',
                    default => 'csv',
                };";

if ( str_contains( $wizContent, "'csv', 'tsv' => 'csv',\n                    default" ) )
{
    echo "  patch 1c (wizard format match): already applied\n";
}
elseif ( str_contains( $wizContent, $old1c ) )
{
    $wizContent = str_replace( $old1c, $new1c, $wizContent );
    echo "  patch 1c (wizard format match): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 1c anchor not found in wizard\n" );
    exit( 1 );
}

if ( file_put_contents( $wizPath, $wizContent ) === false )
{
    fwrite( STDERR, "ERROR: could not write {$wizPath}\n" );
    exit( 1 );
}

/* =====================================================================
 * Dashboard controller (dashboard.php) - same change for the Feed
 * Settings manual-upload widget so behavior is consistent.
 * ===================================================================== */

$dashPath = dirname( __DIR__, 2 ) . '/modules/front/dealers/dashboard.php';

if ( !file_exists( $dashPath ) )
{
    fwrite( STDERR, "ERROR: dashboard.php not found at {$dashPath}\n" );
    exit( 1 );
}

$dashContent = file_get_contents( $dashPath );

/* Patch 2a: allowedFileTypes drop txt */
$old2a = "\t\t\t'allowedFileTypes' => [ 'csv', 'xml', 'json', 'tsv', 'txt' ],";
$new2a = "\t\t\t'allowedFileTypes' => [ 'csv', 'xml', 'json', 'tsv' ],";

if ( str_contains( $dashContent, "[ 'csv', 'xml', 'json', 'tsv' ]," ) )
{
    echo "  patch 2a (dashboard allowed types): already applied\n";
}
elseif ( str_contains( $dashContent, $old2a ) )
{
    $dashContent = str_replace( $old2a, $new2a, $dashContent );
    echo "  patch 2a (dashboard allowed types): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 2a anchor not found in dashboard\n" );
    exit( 1 );
}

/* Patch 2b: extension-to-format match - drop the txt path */
$old2b = "\t\t\t\t'csv','tsv','txt' => 'csv',";
$new2b = "\t\t\t\t'csv','tsv' => 'csv',";

if ( str_contains( $dashContent, "'csv','tsv' => 'csv'," ) )
{
    echo "  patch 2b (dashboard format match): already applied\n";
}
elseif ( str_contains( $dashContent, $old2b ) )
{
    $dashContent = str_replace( $old2b, $new2b, $dashContent );
    echo "  patch 2b (dashboard format match): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 2b anchor not found in dashboard\n" );
    exit( 1 );
}

if ( file_put_contents( $dashPath, $dashContent ) === false )
{
    fwrite( STDERR, "ERROR: could not write {$dashPath}\n" );
    exit( 1 );
}

if ( $applied === 0 )
{
    echo "All patches already applied. No changes written.\n";
    exit( 0 );
}

echo "\nApplied {$applied} patch(es).\n";

$lint1 = shell_exec( 'php -l ' . escapeshellarg( $wizPath ) . ' 2>&1' );
$lint2 = shell_exec( 'php -l ' . escapeshellarg( $dashPath ) . ' 2>&1' );
echo "Wizard lint:    " . trim( (string) $lint1 ) . "\n";
echo "Dashboard lint: " . trim( (string) $lint2 ) . "\n";

if ( !str_contains( (string) $lint1, 'No syntax errors' ) || !str_contains( (string) $lint2, 'No syntax errors' ) )
{
    fwrite( STDERR, "WARNING: lint failed\n" );
    exit( 1 );
}

exit( 0 );
