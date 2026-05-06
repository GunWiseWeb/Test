<?php
/**
 * v1.0.169 patch script - add per-class $multitons + $multitonMap to
 * each ActiveRecord subclass so they don't share IPS\Patterns\ActiveRecord's
 * static cache.
 *
 * Why: IPS' ActiveRecord::load() reads static::$multitons[$id] for cache
 * lookups. If Dealer (table=gd_dealer_feed_config, PK=dealer_id=2) and
 * ImportLog (table=gd_dealer_import_log, PK=id=2) are both used in the
 * same request, and BOTH classes inherit the parent's $multitons array
 * without declaring their own, they SHARE that array. Loading Dealer
 * id=2 caches the dealer at multitons[2]; later loading ImportLog id=2
 * returns the cached Dealer -> TypeError.
 *
 * Fix: each ActiveRecord subclass declares its own static $multitons and
 * $multitonMap so PHP's late static binding gives each class its own
 * cache namespace.
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10169/patch_multitons.php
 *
 * Idempotent.
 */

$files = [
    'sources/Dealer/Dealer.php'        => '_Dealer',
    'sources/Log/ImportLog.php'        => '_ImportLog',
    'sources/Listing/Listing.php'      => '_Listing',
];

$applied = 0;

foreach ( $files as $relPath => $className )
{
    $path = dirname( __DIR__, 2 ) . '/' . $relPath;
    if ( !file_exists( $path ) )
    {
        fwrite( STDERR, "ERROR: {$relPath} not found\n" );
        exit( 1 );
    }

    $content = file_get_contents( $path );

    if ( str_contains( $content, 'protected static array $multitons = [];' ) )
    {
        echo "  {$relPath}: already patched\n";
        continue;
    }

    /* Anchor on the databaseColumnId line (every ActiveRecord subclass
     * has it). Insert the multiton declarations right after it. */
    $anchorRegex = '/(public static string \$databaseColumnId = [\'"][^\'"]+[\'"];)/';

    if ( preg_match( $anchorRegex, $content ) !== 1 )
    {
        fwrite( STDERR, "ERROR: anchor not found in {$relPath}\n" );
        exit( 1 );
    }

    $insertion = "\$1\n\n\t/* v169: per-class multiton cache so we don't accidentally\n\t * return another ActiveRecord subclass cached at the same PK. */\n\tprotected static array \$multitons = [];\n\tprotected static ?array \$multitonMap = NULL;";

    $newContent = preg_replace( $anchorRegex, $insertion, $content, 1 );

    if ( $newContent === null || $newContent === $content )
    {
        fwrite( STDERR, "ERROR: regex replace failed for {$relPath}\n" );
        exit( 1 );
    }

    if ( file_put_contents( $path, $newContent ) === false )
    {
        fwrite( STDERR, "ERROR: failed to write {$path}\n" );
        exit( 1 );
    }

    $lint = shell_exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1' );
    if ( !str_contains( (string) $lint, 'No syntax errors' ) )
    {
        fwrite( STDERR, "ERROR: lint failed for {$relPath}: " . trim( (string) $lint ) . "\n" );
        exit( 1 );
    }

    echo "  {$relPath}: applied + lint ok\n";
    $applied++;
}

if ( $applied === 0 )
{
    echo "All patches already applied. No changes written.\n";
    exit( 0 );
}

echo "\nApplied {$applied} patch(es).\n";
exit( 0 );
