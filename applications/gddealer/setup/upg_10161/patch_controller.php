<?php
/**
 * v1.0.161 patch script - Add file upload as a third option in step 1.
 *
 * Three patches:
 *   1. saveStep1: accept mode=upload, validate file, store via
 *      \IPS\File::createFromUploads(), record in gd_dealer_feed_uploads,
 *      auto-detect format from extension, set dealer to manual mode,
 *      stash file URL in wizard_state_json.upload_file_url.
 *   2. performStep2Fetch: add 'upload' branch that reads body from the
 *      saved \IPS\File contents.
 *   3. saveStep1 validation: 'upload' added to valid mode list, format
 *      validation skipped for upload (auto-detected from extension).
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10161/patch_controller.php
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
 * Patch 1 - Update mode validation in saveStep1 to allow 'upload'
 * ===================================================================== */

$old1 = "        if ( !in_array( \$mode, [ 'url', 'paste' ], true ) )
        {
            \$errors[] = 'Please choose either \"Fetch from URL\" or \"Paste feed body\".';
            \$mode = 'url';
        }";

$new1 = "        if ( !in_array( \$mode, [ 'url', 'paste', 'upload' ], true ) )
        {
            \$errors[] = 'Please choose Fetch from URL, Paste feed body, or Upload a file.';
            \$mode = 'url';
        }";

if ( str_contains( $content, "[ 'url', 'paste', 'upload' ], true" ) )
{
    echo "  patch 1 (mode validation): already applied\n";
}
elseif ( str_contains( $content, $old1 ) )
{
    $content = str_replace( $old1, $new1, $content );
    echo "  patch 1 (mode validation): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 1 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 2 - Add upload mode handling block in saveStep1.
 * Anchor: insert before the existing else-branch (paste mode validation).
 * The new block handles file upload + recording. For upload mode,
 * format is detected from the file extension (not the form field), and
 * delivery_mode is set to 'manual' on the dealer.
 * ===================================================================== */

$old2 = "        if ( \$mode === 'url' )
        {
            if ( \$feedUrl === '' )
            {";

$new2 = "        \$uploadFileUrl  = '';
        \$uploadFileName = '';
        \$uploadFileSize = 0;
        \$uploadFormat   = '';

        if ( \$mode === 'upload' )
        {
            try
            {
                \$files = \\IPS\\File::createFromUploads(
                    'gddealer_FeedUpload',
                    'upload_file',
                    [ 'csv', 'xml', 'json', 'tsv', 'txt' ],
                    50
                );
            }
            catch ( \\Throwable \$e )
            {
                \$errors[] = 'Upload failed: ' . \$e->getMessage();
                \$files = [];
            }

            if ( empty( \$files ) )
            {
                \$errors[] = 'Please choose a feed file to upload (CSV, XML, JSON, TSV, or TXT, up to 50 MB).';
            }
            else
            {
                /** @var \\IPS\\File \$uploadedFile */
                \$uploadedFile   = reset( \$files );
                \$uploadFileUrl  = (string) \$uploadedFile;
                \$uploadFileName = (string) ( \$uploadedFile->originalFilename ?? \$uploadedFile->filename ?? 'feed' );
                \$uploadFileSize = (int) ( \$uploadedFile->filesize() ?? 0 );

                \$ext = strtolower( pathinfo( \$uploadFileName, PATHINFO_EXTENSION ) );
                \$uploadFormat = match ( \$ext )
                {
                    'xml'  => 'xml',
                    'json' => 'json',
                    'csv', 'tsv', 'txt' => 'csv',
                    default => 'csv',
                };

                /* Override the form's feed_format with the detected one
                 * so subsequent code paths use the right value. */
                \$feedFormat = \$uploadFormat;
            }
        }
        elseif ( \$mode === 'url' )
        {
            if ( \$feedUrl === '' )
            {";

if ( str_contains( $content, "if ( \$mode === 'upload' )" ) )
{
    echo "  patch 2 (upload mode handler): already applied\n";
}
elseif ( str_contains( $content, $old2 ) )
{
    $content = str_replace( $old2, $new2, $content );
    echo "  patch 2 (upload mode handler): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 2 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 3 - When mode=upload, persist upload + dealer changes after
 * validation passes. Anchor: the `$update` array assembly for url mode.
 * We add the upload-mode branch FIRST so the existing url/paste paths
 * don't change.
 * ===================================================================== */

$old3 = "        \$update = [ 'feed_format' => \$feedFormat ];
        if ( \$mode === 'url' )
        {
            \$update['feed_url']         = \$feedUrl;";

$new3 = "        \$update = [ 'feed_format' => \$feedFormat ];
        if ( \$mode === 'upload' )
        {
            /* Upload mode forces delivery to manual + clears any URL/auth
             * that might have been previously configured. */
            \$update['feed_delivery_mode'] = 'manual';
            \$update['feed_url']           = '';
            \$update['auth_type']          = 'none';
            \$update['auth_credentials']   = '';

            /* Record the upload in gd_dealer_feed_uploads so it shows
             * up in upload history AND the import scheduler picks it
             * up on its next run. */
            try
            {
                \\IPS\\Db::i()->insert( 'gd_dealer_feed_uploads', [
                    'dealer_id'       => (int) \$this->dealer->dealer_id,
                    'upload_format'   => \$uploadFormat,
                    'file_url'        => \$uploadFileUrl,
                    'file_name'       => \$uploadFileName,
                    'file_size_bytes' => \$uploadFileSize,
                    'uploaded_at'     => time(),
                    'uploaded_by'     => (int) \\IPS\\Member::loggedIn()->member_id,
                ] );
            }
            catch ( \\Throwable \$e )
            {
                try { \\IPS\\Log::log( 'wizard saveStep1 upload insert failed: ' . \$e->getMessage(), 'gddealer_setupwizard' ); } catch ( \\Throwable ) {}
            }
        }
        elseif ( \$mode === 'url' )
        {
            \$update['feed_url']         = \$feedUrl;";

if ( str_contains( $content, "if ( \$mode === 'upload' )\n        {\n            /* Upload mode forces delivery to manual" ) )
{
    echo "  patch 3 (saveStep1 update branch): already applied\n";
}
elseif ( str_contains( $content, $old3 ) )
{
    $content = str_replace( $old3, $new3, $content );
    echo "  patch 3 (saveStep1 update branch): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 3 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 4 - Stash upload file URL in wizard_state_json.
 * Anchor: where the wizard state is saved at the end of saveStep1.
 * Look for "$state['mode']" to find that block.
 * ===================================================================== */

$old4 = "        \$state['mode']       = \$mode;
        \$state['paste_body'] = \$mode === 'paste' ? \$pasteBody : '';
        unset( \$state['step2_fetch'], \$state['step2_records'], \$state['step2_fields'] );";

$new4 = "        \$state['mode']            = \$mode;
        \$state['paste_body']      = \$mode === 'paste'  ? \$pasteBody     : '';
        \$state['upload_file_url'] = \$mode === 'upload' ? \$uploadFileUrl : '';
        unset( \$state['step2_fetch'], \$state['step2_records'], \$state['step2_fields'], \$state['step4_report'], \$state['step4_rows'] );";

if ( str_contains( $content, "\$state['upload_file_url']" ) )
{
    echo "  patch 4 (state stash): already applied\n";
}
elseif ( str_contains( $content, $old4 ) )
{
    $content = str_replace( $old4, $new4, $content );
    echo "  patch 4 (state stash): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 4 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 5 - performStep2Fetch: add 'upload' branch that reads body
 * from the stored \IPS\File. The structure is currently:
 *     if ( $mode === 'url' ) { ... }
 *     else { ... paste ... }
 * We change to:
 *     if ( $mode === 'url' ) { ... }
 *     elseif ( $mode === 'upload' ) { ... NEW ... }
 *     else { ... paste ... }
 * ===================================================================== */

$old5 = "        else
        {
            \$body = isset( \$state['paste_body'] ) ? (string) \$state['paste_body'] : '';
            \$fetchMeta = [
                'ok'           => \$body !== '',
                'http_status'  => 0,
                'content_type' => '(pasted)',
                'body_bytes'   => strlen( \$body ),
                'truncated'    => false,
                'duration_ms'  => 0,
                'error'        => \$body === '' ? 'No pasted feed body found.' : null,
                'preview'      => substr( \$body, 0, 800 ),
            ];
        }";

$new5 = "        elseif ( \$mode === 'upload' )
        {
            \$fileUrl = isset( \$state['upload_file_url'] ) ? (string) \$state['upload_file_url'] : '';
            \$fileName = '(uploaded file)';
            \$body = '';
            \$err  = null;

            if ( \$fileUrl === '' )
            {
                \$err = 'No uploaded file found in wizard state. Go back to step 1 and upload again.';
            }
            else
            {
                try
                {
                    \$file = \\IPS\\File::get( 'gddealer_FeedUpload', \$fileUrl );
                    \$body = (string) \$file->contents();
                    \$fileName = (string) ( \$file->originalFilename ?? \$file->filename ?? '(uploaded file)' );
                }
                catch ( \\Throwable \$e )
                {
                    \$err = 'Could not read uploaded file: ' . \$e->getMessage();
                }
            }

            \$fetchMeta = [
                'ok'           => \$err === null && \$body !== '',
                'http_status'  => 0,
                'content_type' => '(uploaded: ' . \$fileName . ')',
                'body_bytes'   => strlen( \$body ),
                'truncated'    => false,
                'duration_ms'  => 0,
                'error'        => \$err,
                'preview'      => substr( \$body, 0, 800 ),
            ];
        }
        else
        {
            \$body = isset( \$state['paste_body'] ) ? (string) \$state['paste_body'] : '';
            \$fetchMeta = [
                'ok'           => \$body !== '',
                'http_status'  => 0,
                'content_type' => '(pasted)',
                'body_bytes'   => strlen( \$body ),
                'truncated'    => false,
                'duration_ms'  => 0,
                'error'        => \$body === '' ? 'No pasted feed body found.' : null,
                'preview'      => substr( \$body, 0, 800 ),
            ];
        }";

if ( str_contains( $content, "elseif ( \$mode === 'upload' )" ) )
{
    echo "  patch 5 (performStep2Fetch upload branch): already applied\n";
}
elseif ( str_contains( $content, $old5 ) )
{
    $content = str_replace( $old5, $new5, $content );
    echo "  patch 5 (performStep2Fetch upload branch): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 5 anchor not found\n" );
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
