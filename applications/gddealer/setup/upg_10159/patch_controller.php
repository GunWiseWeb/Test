<?php
/**
 * v1.0.159 patch script - invalidate the cached step 4 validation
 * report when step 2 or step 3 saves.
 *
 * The bug: step 4 caches its validation report in
 * wizard_state_json.step4_report. The cache is only invalidated when
 * the dealer explicitly clicks "Re-validate" (?revalidate=1).
 *
 * If the dealer (a) hits step 4 once with an empty/incomplete mapping,
 * the report gets cached with errors, then (b) goes back to step 3,
 * fixes the mapping, clicks Save, then (c) returns to step 4 - they
 * still see the OLD cached report. Has to click Re-validate manually.
 *
 * Fix: clear step4_report and step4_rows from wizard_state_json
 * whenever saveStep2 or saveStep3 successfully writes. Same for the
 * step 5 report cache (which doesn't exist yet but might in the future).
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10159/patch_controller.php
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

/* =====================================================================
 * Patch 1 - saveStep2: clear step 4 cache before redirecting to step 3
 * ===================================================================== */

$old1 = "        try
        {
            \\IPS\\Db::i()->update( 'gd_dealer_feed_config', \$update,
                [ 'dealer_id=?', (int) \$this->dealer->dealer_id ]
            );
        }
        catch ( \\Throwable \$e )
        {
            try { \\IPS\\Log::log( 'wizard saveStep2 update failed: ' . \$e->getMessage(), 'gddealer_setupwizard' ); } catch ( \\Throwable ) {}
        }

        \\IPS\\Output::i()->redirect(
            \\IPS\\Http\\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step3', 'front', 'dealers_setup_wizard' )
        );
    }";

$new1 = "        try
        {
            \\IPS\\Db::i()->update( 'gd_dealer_feed_config', \$update,
                [ 'dealer_id=?', (int) \$this->dealer->dealer_id ]
            );
        }
        catch ( \\Throwable \$e )
        {
            try { \\IPS\\Log::log( 'wizard saveStep2 update failed: ' . \$e->getMessage(), 'gddealer_setupwizard' ); } catch ( \\Throwable ) {}
        }

        /* v159: invalidate downstream cached validation. New parsed
         * records mean step 4's prior report is stale. */
        \$state = \$this->loadWizardState();
        unset( \$state['step4_report'], \$state['step4_rows'] );
        \$this->saveWizardState( \$state );

        \\IPS\\Output::i()->redirect(
            \\IPS\\Http\\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step3', 'front', 'dealers_setup_wizard' )
        );
    }";

if ( str_contains( $content, "v159: invalidate downstream cached validation. New parsed" ) )
{
    echo "  patch 1 (saveStep2 cache invalidate): already applied\n";
}
elseif ( str_contains( $content, $old1 ) )
{
    $content = str_replace( $old1, $new1, $content );
    echo "  patch 1 (saveStep2 cache invalidate): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 1 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 2 - saveStep3: clear step 4 cache before redirecting to step 4
 * ===================================================================== */

$old2 = "        try
        {
            \\IPS\\Db::i()->update( 'gd_dealer_feed_config', \$update,
                [ 'dealer_id=?', (int) \$this->dealer->dealer_id ]
            );
        }
        catch ( \\Throwable \$e )
        {
            try { \\IPS\\Log::log( 'wizard saveStep3 update failed: ' . \$e->getMessage(), 'gddealer_setupwizard' ); } catch ( \\Throwable ) {}
            \$body = (string) \\IPS\\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep3(
                \$this->wizardData( 3 ),
                \$this->buildStep3Values( \$cfg, \$state, false, [
                    'sources'  => \$sources,
                    'mapping'  => \$mappingPos,
                    'defaults' => \$defaultsIn,
                ], [ 'Could not save your mapping. Please try again.' ] )
            );
            \$this->output( 'setupWizard', \$body );
            return;
        }

        \\IPS\\Output::i()->redirect(
            \\IPS\\Http\\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step4', 'front', 'dealers_setup_wizard' )
        );
    }";

$new2 = "        try
        {
            \\IPS\\Db::i()->update( 'gd_dealer_feed_config', \$update,
                [ 'dealer_id=?', (int) \$this->dealer->dealer_id ]
            );
        }
        catch ( \\Throwable \$e )
        {
            try { \\IPS\\Log::log( 'wizard saveStep3 update failed: ' . \$e->getMessage(), 'gddealer_setupwizard' ); } catch ( \\Throwable ) {}
            \$body = (string) \\IPS\\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep3(
                \$this->wizardData( 3 ),
                \$this->buildStep3Values( \$cfg, \$state, false, [
                    'sources'  => \$sources,
                    'mapping'  => \$mappingPos,
                    'defaults' => \$defaultsIn,
                ], [ 'Could not save your mapping. Please try again.' ] )
            );
            \$this->output( 'setupWizard', \$body );
            return;
        }

        /* v159: invalidate cached step 4 validation. The new field
         * mapping changes which canonical fields each record produces,
         * so the prior report is stale. */
        unset( \$state['step4_report'], \$state['step4_rows'] );
        \$this->saveWizardState( \$state );

        \\IPS\\Output::i()->redirect(
            \\IPS\\Http\\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step4', 'front', 'dealers_setup_wizard' )
        );
    }";

if ( str_contains( $content, "v159: invalidate cached step 4 validation. The new field" ) )
{
    echo "  patch 2 (saveStep3 cache invalidate): already applied\n";
}
elseif ( str_contains( $content, $old2 ) )
{
    $content = str_replace( $old2, $new2, $content );
    echo "  patch 2 (saveStep3 cache invalidate): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 2 anchor not found\n" );
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
