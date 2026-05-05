<?php
/**
 * v1.0.160 patch script - let completed dealers re-run the wizard.
 *
 * In v158 I added a redirect in manage() that sends completed dealers
 * (wizard_completed_at NOT NULL) to the dashboard overview. The sidebar
 * nav link goes to /dealers/setup-wizard with no specific step, which
 * hits manage() and bounces them. They have no way back into the wizard
 * to reconfigure.
 *
 * Fix:
 *   1. Remove the early-return redirect in manage(). Completed dealers
 *      land on step 5 (their highest step) and can navigate back to edit.
 *   2. Pass is_completed + completed_at to the step 5 template so the
 *      page framing is appropriate. The template still works the same
 *      whether dealer is finishing for the first time or revisiting.
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10160/patch_controller.php
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
 * Patch 1 - Remove the completed-dealer redirect in manage()
 * ===================================================================== */

$old1 = "        \$cfg = \$this->loadFeedConfig();
        \$highest = isset( \$cfg['wizard_step'] ) ? (int) \$cfg['wizard_step'] : 0;
        /* Completed dealers shouldn't see the wizard at all - redirect
         * to dashboard. They can re-enter via the explicit step links. */
        if ( !empty( \$cfg['wizard_completed_at'] ) )
        {
            \\IPS\\Output::i()->redirect(
                \\IPS\\Http\\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=overview', 'front', 'dealer_dashboard' )
            );
            return;
        }

        if ( \$highest >= 4 )      { \$this->step5(); }";

$new1 = "        \$cfg = \$this->loadFeedConfig();
        \$highest = isset( \$cfg['wizard_step'] ) ? (int) \$cfg['wizard_step'] : 0;

        /* v160: Completed dealers can re-enter the wizard to reconfigure.
         * They land on step 5 (their highest step) where they can review
         * their current setup and navigate back to make changes. */

        if ( \$highest >= 4 )      { \$this->step5(); }";

if ( str_contains( $content, "v160: Completed dealers can re-enter the wizard" ) )
{
    echo "  patch 1 (manage redirect removal): already applied\n";
}
elseif ( str_contains( $content, $old1 ) )
{
    $content = str_replace( $old1, $new1, $content );
    echo "  patch 1 (manage redirect removal): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 1 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 2 - Add is_completed + completed_at to buildStep5Values output.
 * Anchor on the closing of the return array (smaller, more stable).
 * ===================================================================== */

$old2 = "            'has_errors'        => \$errorRecords > 0,
            'errors'            => \$errors,
        ];
    }

    /* ============================================================
     * Helpers
     * ============================================================ */";

$new2 = "            'has_errors'        => \$errorRecords > 0,
            'errors'            => \$errors,
            'is_completed'      => \$completedAt !== '',
            'completed_at'      => \$completedAt,
        ];
    }

    /* ============================================================
     * Helpers
     * ============================================================ */";

if ( str_contains( $content, "'is_completed'      => \$completedAt !== ''" ) )
{
    echo "  patch 2 (is_completed values): already applied\n";
}
elseif ( str_contains( $content, $old2 ) )
{
    /* Also need to add the $completedAt assignment somewhere before the return.
     * Insert it just before the return statement. */
    $returnAnchor = "        return [
            'urls'              => \$this->wizardUrls(),";
    $returnAnchorWithAssign = "        \$completedAt = isset( \$cfg['wizard_completed_at'] ) ? (string) \$cfg['wizard_completed_at'] : '';

        return [
            'urls'              => \$this->wizardUrls(),";

    if ( !str_contains( $content, $returnAnchor ) )
    {
        fwrite( STDERR, "ERROR: patch 2 return anchor not found\n" );
        exit( 1 );
    }

    $content = str_replace( $old2, $new2, $content );
    $content = str_replace( $returnAnchor, $returnAnchorWithAssign, $content );
    echo "  patch 2 (is_completed values): applied\n";
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

