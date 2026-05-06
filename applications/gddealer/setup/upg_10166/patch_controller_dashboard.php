<?php
/**
 * v1.0.166 patch script - dashboard.php feedSettings()
 *
 * Better sync_health state machine on Feed Settings.
 *
 * Currently the logic only knows two states:
 *   - "Feed not configured yet" if !$latest (no imports yet)
 *   - "Feed is healthy" if $latest succeeded
 *
 * That's wrong when the wizard IS completed and a file IS in the
 * upload queue waiting for the next 15-min scheduler tick - dealers
 * see "not configured" when they JUST configured everything.
 *
 * New 6-state model:
 *   1. Wizard not done -> "Feed not configured yet" / run wizard
 *   2a. Wizard done, manual mode, no uploads -> "ready for first upload"
 *   2b. Wizard done, URL mode, no imports -> "awaiting first sync"
 *   3. Wizard done, uploads pending, no imports -> "Feed pending first
 *      import" + "scheduler runs every 15 min"
 *   4. Wizard done, latest succeeded -> "Feed is healthy"
 *   5. Wizard done, latest failed -> "Last import failed"
 *   6. Wizard done, latest partial -> "Last import was partial"
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10166/patch_controller_dashboard.php
 *
 * Idempotent.
 */

$path = dirname( __DIR__, 2 ) . '/modules/front/dealers/dashboard.php';

if ( !file_exists( $path ) )
{
    fwrite( STDERR, "ERROR: dashboard.php not found at {$path}\n" );
    exit( 1 );
}

$content = file_get_contents( $path );
if ( $content === false )
{
    fwrite( STDERR, "ERROR: could not read {$path}\n" );
    exit( 1 );
}

$applied = 0;

/* =====================================================================
 * Replace the entire sync_health calculation block.
 * ===================================================================== */

$old = "\t\t\$latest = \$importLog[0] ?? null;
\t\t\$syncHealth = 'healthy';
\t\t\$syncTitle  = 'Feed is healthy';
\t\t\$syncSub    = 'Ready to import when your feed updates';
\t\tif ( !\$latest ) {
\t\t\t\$syncHealth = 'warn';
\t\t\t\$syncTitle  = 'Feed not configured yet';
\t\t\t\$syncSub    = \$currentMode === 'manual'
\t\t\t\t? 'Upload your first feed file to start syncing'
\t\t\t\t: 'Enter your feed URL below to start syncing';
\t\t} elseif ( \$latest['status'] === 'failed' ) {
\t\t\t\$syncHealth = 'error';
\t\t\t\$syncTitle  = 'Last import failed';
\t\t\t\$syncSub    = \$latest['when_ago'] . ' — ' . ( \$latest['error'] ?: 'Check configuration' );
\t\t} elseif ( \$latest['status'] === 'partial' ) {
\t\t\t\$syncHealth = 'warn';
\t\t\t\$syncTitle  = 'Last import was partial';
\t\t\t\$syncSub    = \$latest['when_ago'];
\t\t} else {
\t\t\t\$syncTitle  = 'Feed is healthy';
\t\t\t\$syncSub    = 'Last imported ' . \$latest['when_ago'];
\t\t}";

$new = "\t\t\$latest = \$importLog[0] ?? null;
\t\t\$wizardCompleted = !empty( \$wizardCfg['wizard_completed_at'] );
\t\t\$pendingUploads  = is_array( \$recentUploads ) ? count( \$recentUploads ) : 0;

\t\t\$syncHealth = 'healthy';
\t\t\$syncTitle  = 'Feed is healthy';
\t\t\$syncSub    = 'Ready to import when your feed updates';

\t\tif ( !\$wizardCompleted ) {
\t\t\t/* State 1: wizard not finished. */
\t\t\t\$syncHealth = 'warn';
\t\t\t\$syncTitle  = 'Feed not configured yet';
\t\t\t\$syncSub    = 'Run the Setup Wizard to get started.';
\t\t} elseif ( !\$latest ) {
\t\t\t/* States 2 & 3: wizard done but no imports have run yet. */
\t\t\tif ( \$pendingUploads > 0 ) {
\t\t\t\t/* State 3: file uploaded, awaiting next scheduler tick. */
\t\t\t\t\$syncHealth = 'warn';
\t\t\t\t\$syncTitle  = 'Feed pending first import';
\t\t\t\t\$syncSub    = 'Your uploaded file is queued. The scheduler runs every 15 minutes - your data will appear shortly.';
\t\t\t} elseif ( \$currentMode === 'manual' ) {
\t\t\t\t/* State 2a: manual mode but no uploads yet. */
\t\t\t\t\$syncHealth = 'warn';
\t\t\t\t\$syncTitle  = 'Feed configured, ready for first upload';
\t\t\t\t\$syncSub    = 'Upload a feed file below to start syncing.';
\t\t\t} else {
\t\t\t\t/* State 2b: URL mode, awaiting first scheduled fetch. */
\t\t\t\t\$syncHealth = 'warn';
\t\t\t\t\$syncTitle  = 'Feed configured, awaiting first sync';
\t\t\t\t\$syncSub    = 'The scheduler runs every 15 minutes - your first import will happen shortly.';
\t\t\t}
\t\t} elseif ( \$latest['status'] === 'failed' ) {
\t\t\t/* State 5: last import failed. */
\t\t\t\$syncHealth = 'error';
\t\t\t\$syncTitle  = 'Last import failed';
\t\t\t\$syncSub    = \$latest['when_ago'] . ' — ' . ( \$latest['error'] ?: 'Check configuration' );
\t\t} elseif ( \$latest['status'] === 'partial' ) {
\t\t\t/* State 6: last import was partial. */
\t\t\t\$syncHealth = 'warn';
\t\t\t\$syncTitle  = 'Last import was partial';
\t\t\t\$syncSub    = \$latest['when_ago'];
\t\t} else {
\t\t\t/* State 4: latest import succeeded. */
\t\t\t\$syncTitle  = 'Feed is healthy';
\t\t\t\$syncSub    = 'Last imported ' . \$latest['when_ago'];
\t\t}";

if ( str_contains( $content, "Feed pending first import" ) )
{
    echo "  patch (sync_health states): already applied\n";
}
elseif ( str_contains( $content, $old ) )
{
    $content = str_replace( $old, $new, $content );
    echo "  patch (sync_health states): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: anchor not found - dashboard sync_health block looks different than expected. Are you running on top of v158+?\n" );
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
