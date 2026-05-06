<?php
/**
 * v1.0.167 patch script - fix task execute() return type signatures.
 *
 * IPS\Task::execute() declares `: mixed` return type. PHP 8.x strict
 * inheritance rules require child class overrides to declare compatible
 * return types - `: mixed` (or any subtype). My task classes were
 * declared as `public function execute()` with NO return type, which
 * is incompatible with `: mixed` parent.
 *
 * Result: when IPS tries to instantiate any of the affected tasks via
 * reflection, PHP throws a fatal error and the task is silently skipped.
 * That's why `last_run` never advanced for any gddealer task.
 *
 * Fix: change `public function execute()` to `public function execute(): mixed`
 * in three task files. ComputeRankSnapshots already declares `: ?string`
 * which IS compatible (covariant subtype) so it stays as-is.
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10167/patch_tasks.php
 *
 * Idempotent.
 */

$tasksDir = dirname( __DIR__, 2 ) . '/tasks';

if ( !is_dir( $tasksDir ) )
{
    fwrite( STDERR, "ERROR: tasks dir not found at {$tasksDir}\n" );
    exit( 1 );
}

$applied = 0;
$files = [
    'DealerImportFeeds.php',
    'ExpireTrials.php',
    'ResolveExpiredDisputes.php',
];

foreach ( $files as $file )
{
    $path = $tasksDir . '/' . $file;
    if ( !file_exists( $path ) )
    {
        fwrite( STDERR, "ERROR: {$file} not found\n" );
        exit( 1 );
    }

    $content = file_get_contents( $path );

    /* If already fixed, skip. */
    if ( str_contains( $content, "public function execute(): mixed" ) )
    {
        echo "  {$file}: already fixed\n";
        continue;
    }

    /* The exact form found in the source (tab-indented). */
    $old = "\tpublic function execute()\n\t{";
    $new = "\tpublic function execute(): mixed\n\t{";

    if ( !str_contains( $content, $old ) )
    {
        fwrite( STDERR, "ERROR: anchor not found in {$file}. Manual fix needed.\n" );
        exit( 1 );
    }

    $content = str_replace( $old, $new, $content );

    if ( file_put_contents( $path, $content ) === false )
    {
        fwrite( STDERR, "ERROR: failed to write {$path}\n" );
        exit( 1 );
    }

    $lint = shell_exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1' );
    if ( !str_contains( (string) $lint, 'No syntax errors' ) )
    {
        fwrite( STDERR, "ERROR: lint failed for {$file}: " . trim( (string) $lint ) . "\n" );
        exit( 1 );
    }

    echo "  {$file}: applied + lint ok\n";
    $applied++;
}

if ( $applied === 0 )
{
    echo "All patches already applied. No changes written.\n";
    exit( 0 );
}

echo "\nApplied {$applied} patch(es).\n";
exit( 0 );
