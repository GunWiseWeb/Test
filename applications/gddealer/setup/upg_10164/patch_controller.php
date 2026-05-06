<?php
/**
 * v1.0.164 patch script - paste mode now saves the body to storage and
 * sets the dealer to manual mode, so pasting a feed body is functionally
 * equivalent to uploading it from a file.
 *
 * Why: previously paste mode just stashed the body in wizard_state_json
 * for step 2 to parse, but didn't configure any production import path.
 * Dealers could complete the wizard with delivery_mode=url and no URL
 * set, leading to silent failures - their feed never imports and they
 * don't know why. Option E from our discussion: make paste mode actually
 * accomplish what dealers expect.
 *
 * Three patches:
 *   1. saveStep1: paste branch saves body to \IPS\File and inserts into
 *      gd_dealer_feed_uploads, same path as upload mode.
 *   2. saveStep1 update branch: 'paste' added alongside 'upload' so it
 *      forces delivery_mode=manual + clears URL/auth.
 *   3. performStep2Fetch: paste branch reads from saved \IPS\File instead
 *      of from $state['paste_body']. (paste_body field deprecated but
 *      preserved for backward compat - falls back to it if no file URL.)
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10164/patch_controller.php
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
 * Patch 1 - In saveStep1, after the upload-mode handler block but
 * before the URL-mode validation, add a paste-mode handler that saves
 * the body to storage. We DON'T touch the existing else-branch (paste
 * validation) - that runs separately and the result feeds into our new
 * block here.
 *
 * Anchor: the start of the URL-mode validation block.
 * Approach: add new $pasteFileUrl/Name/Size variables alongside the
 * existing $upload* vars, then in the elseif chain handle paste like
 * upload (save body to file, record in uploads table).
 * ===================================================================== */

$old1 = "        \$uploadFileUrl  = '';
        \$uploadFileName = '';
        \$uploadFileSize = 0;
        \$uploadFormat   = '';

        if ( \$mode === 'upload' )";

$new1 = "        \$uploadFileUrl  = '';
        \$uploadFileName = '';
        \$uploadFileSize = 0;
        \$uploadFormat   = '';

        \$pasteFileUrl  = '';
        \$pasteFileName = '';
        \$pasteFileSize = 0;

        if ( \$mode === 'upload' )";

if ( str_contains( $content, "\$pasteFileUrl  = '';" ) )
{
    echo "  patch 1 (paste vars): already applied\n";
}
elseif ( str_contains( $content, $old1 ) )
{
    $content = str_replace( $old1, $new1, $content );
    echo "  patch 1 (paste vars): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 1 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 2 - After the paste validation else-branch, save the body to
 * storage. The validation already happened by then so we know body is
 * non-empty and within size limits. Anchor: the end of the paste
 * validation else-branch (closing the body length check).
 *
 * Note: paste mode currently runs in the else branch of "if mode===url".
 * That branch validates $pasteBody but does nothing with it. We add
 * file-creation AFTER validation passes (i.e., when no errors yet OR
 * when we're about to write to gd_dealer_feed_uploads in patch 3 below).
 *
 * Cleanest place: just before the "if (!empty($errors))" early-return
 * block, save the file IF mode is paste AND no errors so far. We also
 * preserve $pasteBody in the form-recovery values (needed for re-render
 * on validation error).
 * ===================================================================== */

$old2 = "        if ( !empty( \$errors ) )
        {
            \$values = [
                'mode' => \$mode, 'feed_url' => \$feedUrl, 'feed_format' => \$feedFormat,
                'auth_type' => \$authType, 'auth_credentials' => \$authCredentials,
                'paste_body' => \$pasteBody,
                'urls' => \$this->wizardUrls(),
                'csrfKey' => \\IPS\\Session::i()->csrfKey,
            ];";

$new2 = "        /* Paste mode: save body to storage so it acts like an upload.
         * Only do this AFTER validation passes (otherwise we'd write
         * orphan files for failed submissions). */
        if ( \$mode === 'paste' && empty( \$errors ) && \$pasteBody !== '' )
        {
            \$ts        = date( 'Y-m-d-His' );
            \$ext       = in_array( \$feedFormat, [ 'xml', 'json', 'csv' ], true ) ? \$feedFormat : 'csv';
            \$filename  = \"pasted-feed-{\$ts}.{\$ext}\";

            try
            {
                \$pastedFile = \\IPS\\File::create( 'gddealer_FeedUpload', \$filename, \$pasteBody );
                \$pasteFileUrl  = (string) \$pastedFile;
                \$pasteFileName = \$filename;
                \$pasteFileSize = strlen( \$pasteBody );
            }
            catch ( \\Throwable \$e )
            {
                \$errors[] = 'Could not save your pasted feed body: ' . \$e->getMessage();
            }
        }

        if ( !empty( \$errors ) )
        {
            \$values = [
                'mode' => \$mode, 'feed_url' => \$feedUrl, 'feed_format' => \$feedFormat,
                'auth_type' => \$authType, 'auth_credentials' => \$authCredentials,
                'paste_body' => \$pasteBody,
                'urls' => \$this->wizardUrls(),
                'csrfKey' => \\IPS\\Session::i()->csrfKey,
            ];";

if ( str_contains( $content, "\\IPS\\File::create( 'gddealer_FeedUpload', \$filename, \$pasteBody );" ) )
{
    echo "  patch 2 (paste save to storage): already applied\n";
}
elseif ( str_contains( $content, $old2 ) )
{
    $content = str_replace( $old2, $new2, $content );
    echo "  patch 2 (paste save to storage): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 2 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 3 - The upload-mode update branch already sets feed_delivery_mode
 * to manual and inserts into gd_dealer_feed_uploads. Add 'paste' to
 * that same branch so it does the same thing.
 *
 * Anchor: the existing upload branch.
 * ===================================================================== */

$old3 = "        \$update = [ 'feed_format' => \$feedFormat ];
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
        elseif ( \$mode === 'url' )";

$new3 = "        \$update = [ 'feed_format' => \$feedFormat ];
        if ( \$mode === 'upload' || \$mode === 'paste' )
        {
            /* Upload AND paste modes both force delivery to manual +
             * clear any URL/auth that might have been previously
             * configured. The pasted body has already been written to
             * storage above. */
            \$update['feed_delivery_mode'] = 'manual';
            \$update['feed_url']           = '';
            \$update['auth_type']          = 'none';
            \$update['auth_credentials']   = '';

            /* Pick the right metadata depending on mode. */
            if ( \$mode === 'upload' )
            {
                \$insertFormat = \$uploadFormat;
                \$insertUrl    = \$uploadFileUrl;
                \$insertName   = \$uploadFileName;
                \$insertSize   = \$uploadFileSize;
            }
            else
            {
                \$insertFormat = \$feedFormat;
                \$insertUrl    = \$pasteFileUrl;
                \$insertName   = \$pasteFileName;
                \$insertSize   = \$pasteFileSize;
            }

            /* Record in gd_dealer_feed_uploads so it shows up in upload
             * history AND the import scheduler picks it up on its next
             * run. */
            try
            {
                \\IPS\\Db::i()->insert( 'gd_dealer_feed_uploads', [
                    'dealer_id'       => (int) \$this->dealer->dealer_id,
                    'upload_format'   => \$insertFormat,
                    'file_url'        => \$insertUrl,
                    'file_name'       => \$insertName,
                    'file_size_bytes' => \$insertSize,
                    'uploaded_at'     => time(),
                    'uploaded_by'     => (int) \\IPS\\Member::loggedIn()->member_id,
                ] );
            }
            catch ( \\Throwable \$e )
            {
                try { \\IPS\\Log::log( 'wizard saveStep1 upload insert failed: ' . \$e->getMessage(), 'gddealer_setupwizard' ); } catch ( \\Throwable ) {}
            }
        }
        elseif ( \$mode === 'url' )";

if ( str_contains( $content, "if ( \$mode === 'upload' || \$mode === 'paste' )" ) )
{
    echo "  patch 3 (paste shares upload update branch): already applied\n";
}
elseif ( str_contains( $content, $old3 ) )
{
    $content = str_replace( $old3, $new3, $content );
    echo "  patch 3 (paste shares upload update branch): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 3 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 4 - Stash paste file URL in wizard_state_json (so step 2 can
 * read body from storage instead of from inline paste_body).
 * ===================================================================== */

$old4 = "        \$state['mode']            = \$mode;
        \$state['paste_body']      = \$mode === 'paste'  ? \$pasteBody     : '';
        \$state['upload_file_url'] = \$mode === 'upload' ? \$uploadFileUrl : '';
        unset( \$state['step2_fetch'], \$state['step2_records'], \$state['step2_fields'], \$state['step4_report'], \$state['step4_rows'] );";

$new4 = "        \$state['mode']            = \$mode;
        \$state['paste_body']      = '';
        \$state['paste_file_url']  = \$mode === 'paste'  ? \$pasteFileUrl  : '';
        \$state['upload_file_url'] = \$mode === 'upload' ? \$uploadFileUrl : '';
        unset( \$state['step2_fetch'], \$state['step2_records'], \$state['step2_fields'], \$state['step4_report'], \$state['step4_rows'] );";

if ( str_contains( $content, "\$state['paste_file_url']" ) )
{
    echo "  patch 4 (paste state stash): already applied\n";
}
elseif ( str_contains( $content, $old4 ) )
{
    $content = str_replace( $old4, $new4, $content );
    echo "  patch 4 (paste state stash): applied\n";
    $applied++;
}
else
{
    fwrite( STDERR, "ERROR: patch 4 anchor not found\n" );
    exit( 1 );
}

/* =====================================================================
 * Patch 5 - performStep2Fetch: paste branch reads from saved file.
 * Anchor: the existing else branch (paste).
 *
 * The body now comes from the saved \IPS\File. Falls back to inline
 * paste_body if no file URL is set (defensive; shouldn't happen with
 * v164+ saved state but harmless for transitions).
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

$new5 = "        else
        {
            \$pasteFileUrl = isset( \$state['paste_file_url'] ) ? (string) \$state['paste_file_url'] : '';
            \$body = '';
            \$err  = null;
            \$fileName = '(pasted feed)';

            if ( \$pasteFileUrl !== '' )
            {
                try
                {
                    \$file = \\IPS\\File::get( 'gddealer_FeedUpload', \$pasteFileUrl );
                    \$body = (string) \$file->contents();
                    \$fileName = (string) ( \$file->originalFilename ?? \$file->filename ?? '(pasted feed)' );
                }
                catch ( \\Throwable \$e )
                {
                    \$err = 'Could not read saved paste body: ' . \$e->getMessage();
                }
            }
            else
            {
                /* Backward-compat: if state was saved before v164, the
                 * body might still be in the inline paste_body field. */
                \$body = isset( \$state['paste_body'] ) ? (string) \$state['paste_body'] : '';
                if ( \$body === '' )
                {
                    \$err = 'No pasted feed body found. Go back to step 1 and paste again.';
                }
            }

            \$fetchMeta = [
                'ok'           => \$err === null && \$body !== '',
                'http_status'  => 0,
                'content_type' => '(pasted as ' . \$fileName . ')',
                'body_bytes'   => strlen( \$body ),
                'truncated'    => false,
                'duration_ms'  => 0,
                'error'        => \$err,
                'preview'      => substr( \$body, 0, 800 ),
            ];
        }";

if ( str_contains( $content, "Could not read saved paste body:" ) )
{
    echo "  patch 5 (performStep2Fetch paste branch): already applied\n";
}
elseif ( str_contains( $content, $old5 ) )
{
    $content = str_replace( $old5, $new5, $content );
    echo "  patch 5 (performStep2Fetch paste branch): applied\n";
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
