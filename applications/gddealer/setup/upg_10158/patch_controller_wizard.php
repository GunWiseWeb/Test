<?php
/**
 * v1.0.158 patch script for the wizard controller (setupwizard.php).
 *
 * Adds:
 *   - step5() and saveStep5() methods (Preview & Save - the wizard finale)
 *   - buildStep5Values() helper
 *   - step5 + save_step5 URLs in wizardUrls()
 *   - manage() route for wizard_step >= 4 -> step5(); also routes COMPLETED
 *     dealers (wizard_completed_at NOT NULL) to dashboard
 *   - saveStep4() now redirects forward to step 5 (was looping back to step 4)
 *
 * On Finish:
 *   - sets gd_dealer_feed_config.wizard_completed_at to current time
 *   - sets gd_dealer_feed_config.wizard_step to 5
 *   - redirects to dashboard with a 'wizard_done' query flag
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10158/patch_controller_wizard.php
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
 * Patch 1 - Add step5 URLs to wizardUrls()
 * ===================================================================== */

$old1 = "            'step4'         => (string) \\IPS\\Http\\Url::internal( \$base . '&do=step4', 'front', \$seo ),
            'step4_revalidate' => (string) \\IPS\\Http\\Url::internal( \$base . '&do=step4&revalidate=1', 'front', \$seo ),
            'save_step4'    => (string) \\IPS\\Http\\Url::internal( \$base . '&do=saveStep4', 'front', \$seo ),
            'dashboard'";

$new1 = "            'step4'         => (string) \\IPS\\Http\\Url::internal( \$base . '&do=step4', 'front', \$seo ),
            'step4_revalidate' => (string) \\IPS\\Http\\Url::internal( \$base . '&do=step4&revalidate=1', 'front', \$seo ),
            'save_step4'    => (string) \\IPS\\Http\\Url::internal( \$base . '&do=saveStep4', 'front', \$seo ),
            'step5'         => (string) \\IPS\\Http\\Url::internal( \$base . '&do=step5', 'front', \$seo ),
            'save_step5'    => (string) \\IPS\\Http\\Url::internal( \$base . '&do=saveStep5', 'front', \$seo ),
            'dashboard'";

if ( str_contains( $content, "'step5'         => (string) \\IPS\\Http\\Url::internal" ) )
{
    echo "  patch 1 (step5 URLs): already applied\n";
}
elseif ( str_contains( $content, $old1 ) )
{
    $content = str_replace( $old1, $new1, $content );
    echo "  patch 1 (step5 URLs): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 1 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 2 - Update manage() to route to step 5 + completed dealers
 * ===================================================================== */

$old2 = "        if ( \$highest >= 3 )      { \$this->step4(); }
        elseif ( \$highest >= 2 )  { \$this->step3(); }
        elseif ( \$highest >= 1 )  { \$this->step2(); }
        else                      { \$this->step1(); }";

$new2 = "        /* Completed dealers shouldn't see the wizard at all - redirect
         * to dashboard. They can re-enter via the explicit step links. */
        if ( !empty( \$cfg['wizard_completed_at'] ) )
        {
            \\IPS\\Output::i()->redirect(
                \\IPS\\Http\\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=overview', 'front', 'dealer_dashboard' )
            );
            return;
        }

        if ( \$highest >= 4 )      { \$this->step5(); }
        elseif ( \$highest >= 3 )  { \$this->step4(); }
        elseif ( \$highest >= 2 )  { \$this->step3(); }
        elseif ( \$highest >= 1 )  { \$this->step2(); }
        else                      { \$this->step1(); }";

if ( str_contains( $content, "if ( \$highest >= 4 )      { \$this->step5(); }" ) )
{
    echo "  patch 2 (manage routing): already applied\n";
}
elseif ( str_contains( $content, $old2 ) )
{
    $content = str_replace( $old2, $new2, $content );
    echo "  patch 2 (manage routing): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 2 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 3 - saveStep4 redirects forward to step 5 (was looping back)
 * ===================================================================== */

$old3 = "        /* v155 ships step 4 only - step 5 doesn't exist yet. Land back
         * on step 4 with a success flash. */
        \\IPS\\Output::i()->redirect(
            \\IPS\\Http\\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step4', 'front', 'dealers_setup_wizard' )
                ->setQueryString( 'saved', 1 )
        );";

$new3 = "        \\IPS\\Output::i()->redirect(
            \\IPS\\Http\\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step5', 'front', 'dealers_setup_wizard' )
        );";

if ( str_contains( $content, "do=step5', 'front', 'dealers_setup_wizard' )\n        );\n    }\n\n    /**\n     * Apply the field map to cached step 2 records" ) )
{
    echo "  patch 3 (saveStep4 redirect): already applied\n";
}
elseif ( str_contains( $content, $old3 ) )
{
    $content = str_replace( $old3, $new3, $content );
    echo "  patch 3 (saveStep4 redirect): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 3 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 4 - Insert step5(), saveStep5(), buildStep5Values() methods
 *           BEFORE the Helpers section comment.
 * ===================================================================== */

$step5Block = <<<'PHPCODE'
    /* ============================================================
     * Step 5 - Preview & Save (v158)
     * ============================================================ */

    /**
     * Render step 5 - the wizard finale. Shows:
     *   - Field mapping summary (canonical -> source: feed | default | none)
     *   - 5-record canonical preview (what's about to be imported)
     *   - Validation reminder (X valid / Y errors / Z warnings, link to step 4)
     *   - Finish button (always allowed - wizard configures, doesn't enforce)
     *
     * Gating: requires wizard_step >= 4.
     */
    protected function step5(): void
    {
        $cfg = $this->loadFeedConfig();
        $highest = isset( $cfg['wizard_step'] ) ? (int) $cfg['wizard_step'] : 0;
        if ( $highest < 4 )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step4', 'front', 'dealers_setup_wizard' )
            );
            return;
        }

        $state = $this->loadWizardState();

        $body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep5(
            $this->wizardData( 5 ),
            $this->buildStep5Values( $cfg, $state )
        );
        $this->output( 'setupWizard', $body );
    }

    /**
     * Finish the wizard. Sets wizard_completed_at and redirects to
     * dashboard with a success flash flag.
     */
    protected function saveStep5(): void
    {
        \IPS\Session::i()->csrfCheck();

        $cfg = $this->loadFeedConfig();
        $highest = isset( $cfg['wizard_step'] ) ? (int) $cfg['wizard_step'] : 0;
        if ( $highest < 4 )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step4', 'front', 'dealers_setup_wizard' )
            );
            return;
        }

        $update = [
            'wizard_step'         => 5,
            'wizard_completed_at' => date( 'Y-m-d H:i:s' ),
        ];

        try
        {
            \IPS\Db::i()->update( 'gd_dealer_feed_config', $update,
                [ 'dealer_id=?', (int) $this->dealer->dealer_id ]
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'wizard saveStep5 update failed: ' . $e->getMessage(), 'gddealer_setupwizard' ); } catch ( \Throwable ) {}
            $body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep5(
                $this->wizardData( 5 ),
                $this->buildStep5Values( $cfg, $this->loadWizardState(), [ 'Could not finalize. Please try again.' ] )
            );
            $this->output( 'setupWizard', $body );
            return;
        }

        \IPS\Output::i()->redirect(
            \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=feedSettings', 'front' )
                ->setQueryString( 'wizard_done', 1 )
        );
    }

    /**
     * Build $values for step 5 template.
     *
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $state
     * @param array<int, string> $errors
     * @return array<string, mixed>
     */
    protected function buildStep5Values( array $cfg, array $state, array $errors = [] ): array
    {
        /* Decode the saved field_mapping into:
         *   - $feedMap:  canonical => dealerField   (source = feed)
         *   - $defaults: canonical => value         (source = default)
         */
        $feedMap  = [];
        $defaults = [];
        if ( !empty( $cfg['field_mapping'] ) )
        {
            $decoded = json_decode( (string) $cfg['field_mapping'], true );
            if ( is_array( $decoded ) )
            {
                if ( isset( $decoded['_defaults'] ) && is_array( $decoded['_defaults'] ) )
                {
                    foreach ( $decoded['_defaults'] as $canonical => $value )
                    {
                        $defaults[ (string) $canonical ] = (string) $value;
                    }
                }
                foreach ( $decoded as $key => $value )
                {
                    if ( $key === '_defaults' ) { continue; }
                    /* Saved shape is {dealerField: canonical}. */
                    $feedMap[ (string) $value ] = (string) $key;
                }
            }
        }

        /* Build the per-canonical-row mapping summary. Only show fields
         * that are actually mapped or defaulted - hide noise. */
        $mappingRows = [];
        foreach ( CanonicalFields::all() as $slug => $field )
        {
            $source = 'none';
            $value  = '';

            if ( isset( $feedMap[ $slug ] ) )
            {
                $source = 'feed';
                $value  = $feedMap[ $slug ];
            }
            elseif ( isset( $defaults[ $slug ] ) )
            {
                $source = 'default';
                $value  = $defaults[ $slug ];
            }

            if ( $source === 'none' && ( $field['req'] ?? '' ) !== CanonicalFields::REQ_REQUIRED )
            {
                /* Skip optional fields that aren't mapped - they'd just be noise. */
                continue;
            }

            $mappingRows[] = [
                'slug'    => $slug,
                'label'   => $field['label'] ?? $slug,
                'req'     => $field['req'] ?? '',
                'source'  => $source,
                'value'   => $value,
            ];
        }

        /* 5-record preview from cached step 4 rows. Each row already has
         * the canonical record applied; we just need to flatten it for
         * display. */
        $previewRecords = [];
        $previewColumns = [];
        if ( !empty( $state['step4_rows'] ) && is_array( $state['step4_rows'] ) )
        {
            $rows = array_slice( $state['step4_rows'], 0, 5 );

            /* Determine which columns to show: union of all canonical
             * fields populated across the 5 records, ordered by
             * CanonicalFields::all() order. */
            $populated = [];
            foreach ( $rows as $row )
            {
                if ( !isset( $row['canonical'] ) || !is_array( $row['canonical'] ) ) { continue; }
                foreach ( $row['canonical'] as $key => $value )
                {
                    $populated[ (string) $key ] = true;
                }
            }
            foreach ( CanonicalFields::all() as $slug => $field )
            {
                if ( isset( $populated[ $slug ] ) )
                {
                    $previewColumns[] = [ 'slug' => $slug, 'label' => $field['label'] ?? $slug ];
                }
            }

            foreach ( $rows as $row )
            {
                $canonical = isset( $row['canonical'] ) && is_array( $row['canonical'] ) ? $row['canonical'] : [];
                $cells = [];
                foreach ( $previewColumns as $col )
                {
                    $cells[ $col['slug'] ] = isset( $canonical[ $col['slug'] ] ) ? (string) $canonical[ $col['slug'] ] : '';
                }
                $previewRecords[] = [
                    'row'         => (int) ( $row['row'] ?? 0 ),
                    'cells'       => $cells,
                    'has_errors'  => !empty( $row['has_errors'] ),
                    'has_warnings' => !empty( $row['has_warnings'] ),
                ];
            }
        }

        /* Validation summary from cached step 4 report. */
        $report = isset( $state['step4_report'] ) && is_array( $state['step4_report'] ) ? $state['step4_report'] : null;
        $validRecords   = isset( $report['summary']['valid_records'] ) ? (int) $report['summary']['valid_records'] : 0;
        $errorRecords   = isset( $report['summary']['error_records'] ) ? (int) $report['summary']['error_records'] : 0;
        $warningRecords = isset( $report['summary']['warning_records'] ) ? (int) $report['summary']['warning_records'] : 0;
        $totalRecords   = isset( $report['summary']['total_records'] ) ? (int) $report['summary']['total_records'] : 0;

        return [
            'urls'              => $this->wizardUrls(),
            'csrfKey'           => \IPS\Session::i()->csrfKey,
            'mapping_rows'      => $mappingRows,
            'preview_records'   => $previewRecords,
            'preview_count'     => count( $previewRecords ),
            'preview_columns'   => $previewColumns,
            'valid_records'     => $validRecords,
            'error_records'     => $errorRecords,
            'warning_records'   => $warningRecords,
            'total_records'     => $totalRecords,
            'has_errors'        => $errorRecords > 0,
            'errors'            => $errors,
        ];
    }

    /* ============================================================
     * Helpers
     * ============================================================ */
PHPCODE;

if ( str_contains( $content, "function step5(): void" ) )
{
    echo "  patch 4 (step5 methods): already applied\n";
}
else
{
    $helpersMarker = "    /* ============================================================\n     * Helpers\n     * ============================================================ */";
    if ( substr_count( $content, $helpersMarker ) === 1 )
    {
        $content = str_replace( $helpersMarker, $step5Block, $content );
        echo "  patch 4 (step5 methods): applied\n";
        $applied++;
    }
    else
    {
        fwrite( STDERR, "ERROR: helpers marker not found or non-unique\n" );
        exit( 1 );
    }
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
