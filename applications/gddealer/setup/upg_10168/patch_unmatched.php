<?php
/**
 * v1.0.168 patch script - fix UnmatchedUpc::sweepMatched().
 *
 * The method calls \IPS\Db::i()->delete(...)->rowCount(), but
 * \IPS\Db::i()->delete() returns an integer (number of affected rows),
 * not a mysqli_stmt. PHP throws "Call to a member function rowCount()
 * on string" (the error message is misleading - it's actually an int).
 *
 * This bug has been there since the file was written but only fires
 * when an import actually runs successfully through to the end.
 * Until v167 fixed the task signature bug, no import ever reached this
 * line. Now that imports run, this is the next blocker.
 *
 * Fix: \IPS\Db::i()->delete() already returns affected row count;
 * just cast and return.
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10168/patch_unmatched.php
 *
 * Idempotent.
 */

$path = dirname( __DIR__, 2 ) . '/sources/Unmatched/UnmatchedUpc.php';

if ( !file_exists( $path ) )
{
    fwrite( STDERR, "ERROR: UnmatchedUpc.php not found at {$path}\n" );
    exit( 1 );
}

$content = file_get_contents( $path );
if ( $content === false )
{
    fwrite( STDERR, "ERROR: could not read {$path}\n" );
    exit( 1 );
}

$applied = 0;

$old = "\tpublic static function sweepMatched(): int
\t{
\t\t\$stmt = \\IPS\\Db::i()->delete(
\t\t\t'gd_unmatched_upcs',
\t\t\t'upc IN ( SELECT upc FROM ' . \\IPS\\Db::i()->prefix . 'gd_catalog )'
\t\t);
\t\treturn \$stmt->rowCount();
\t}";

$new = "\tpublic static function sweepMatched(): int
\t{
\t\t/* v168: \\IPS\\Db::i()->delete() returns the affected row count
\t\t * directly (an int). Calling ->rowCount() on it threw fatal. */
\t\treturn (int) \\IPS\\Db::i()->delete(
\t\t\t'gd_unmatched_upcs',
\t\t\t'upc IN ( SELECT upc FROM ' . \\IPS\\Db::i()->prefix . 'gd_catalog )'
\t\t);
\t}";

if ( str_contains( $content, "v168: \\IPS\\Db::i()->delete() returns the affected" ) )
{
    echo "  patch (sweepMatched): already applied\n";
}
elseif ( str_contains( $content, $old ) )
{
    $content = str_replace( $old, $new, $content );
    echo "  patch (sweepMatched): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: anchor not found\n" );
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

echo "\nApplied {$applied} patch(es).\n";
$lint = shell_exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1' );
echo "Lint: " . trim( (string) $lint ) . "\n";

if ( !str_contains( (string) $lint, 'No syntax errors' ) )
{
    fwrite( STDERR, "WARNING: lint failed\n" );
    exit( 1 );
}

exit( 0 );
