<?php
/**
 * @brief       GD Dealer Manager - Feed Setup Wizard Controller
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.149
 * @updated     v1.0.152 - Build URLs in PHP and pass them as strings to
 *              templates instead of using IPS {url=...} template
 *              directives. The {url=...} directive's inner quoting was
 *              breaking HTML attribute parsing, causing form targets and
 *              link hrefs to render as literal text.
 *
 * Multi-step wizard that walks dealers through configuring their feed.
 *
 * Steps:
 *   1. Feed Input        - URL or paste, format, auth (v149)
 *   2. Test Fetch + Parse - HTTP fetch, format detection, field discovery (v150)
 *   3. Field Mapping      - auto-suggest + manual override per field (v153)
 *   4. Validate Sample    - run validator on parsed records (v154)
 *   5. Preview + Save     - show 5-row preview, persist field map (v155)
 *
 * State is persisted in gd_dealer_feed_config.wizard_state_json.
 */

namespace IPS\gddealer\modules\front\dealers;

use IPS\gddealer\Dealer\Dealer;
use IPS\gddealer\Feed\FeedFetcher;
use IPS\gddealer\Feed\CanonicalFields;
use IPS\gddealer\Feed\FieldMapper;
use IPS\gddealer\Feed\Validator;
use IPS\gddealer\Feed\Parser\XmlParser;
use IPS\gddealer\Feed\Parser\JsonParser;
use IPS\gddealer\Feed\Parser\CsvParser;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class _setupwizard extends \IPS\Dispatcher\Controller
{
    use \IPS\gddealer\Traits\DealerShellTrait;

    public static bool $csrfProtected = TRUE;

    /** Current dealer loaded from the logged-in member */
    protected ?Dealer $dealer = null;

    public const TOTAL_STEPS = 5;

    public function execute(): void
    {
        $member = \IPS\Member::loggedIn();

        if ( !$member->member_id )
        {
            \IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join' ) );
            return;
        }

        if ( $member->isAdmin() )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dealers', 'admin' )
            );
            return;
        }

        try
        {
            $this->dealer = Dealer::load( (int) $member->member_id );
        }
        catch ( \OutOfRangeException )
        {
            $this->dealer = null;
        }

        if ( $this->dealer === null && Dealer::isDealerMember( $member ) )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join&do=register' )
            );
            return;
        }

        if ( $this->dealer === null )
        {
            \IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=join' ) );
            return;
        }

        parent::execute();
    }

    /**
     * Build the full set of wizard URLs as strings, using the IPS URL
     * builder. Templates receive these as plain string values, avoiding
     * any need for {url=...} directives in the template body.
     *
     * @return array<string, string>
     */
    protected function wizardUrls(): array
    {
        $base = 'app=gddealer&module=dealers&controller=setupwizard';
        $seo  = 'dealers_setup_wizard';

        return [
            'step1'         => (string) \IPS\Http\Url::internal( $base . '&do=step1', 'front', $seo ),
            'step2'         => (string) \IPS\Http\Url::internal( $base . '&do=step2', 'front', $seo ),
            'step2_refetch' => (string) \IPS\Http\Url::internal( $base . '&do=step2&refetch=1', 'front', $seo ),
            'step3'         => (string) \IPS\Http\Url::internal( $base . '&do=step3', 'front', $seo ),
            'save_step1'    => (string) \IPS\Http\Url::internal( $base . '&do=saveStep1', 'front', $seo ),
            'save_step2'    => (string) \IPS\Http\Url::internal( $base . '&do=saveStep2', 'front', $seo ),
            'save_step3'    => (string) \IPS\Http\Url::internal( $base . '&do=saveStep3', 'front', $seo ),
            'reset_step3'   => (string) \IPS\Http\Url::internal( $base . '&do=step3&reset=1', 'front', $seo ),
            'step4'         => (string) \IPS\Http\Url::internal( $base . '&do=step4', 'front', $seo ),
            'step4_revalidate' => (string) \IPS\Http\Url::internal( $base . '&do=step4&revalidate=1', 'front', $seo ),
            'save_step4'    => (string) \IPS\Http\Url::internal( $base . '&do=saveStep4', 'front', $seo ),
            'step5'         => (string) \IPS\Http\Url::internal( $base . '&do=step5', 'front', $seo ),
            'save_step5'    => (string) \IPS\Http\Url::internal( $base . '&do=saveStep5', 'front', $seo ),
            'dashboard'     => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=overview', 'front', 'dealer_dashboard' ),
            'feed_schema'   => (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=feedschema', 'front', 'dealers_feed_schema' ),
        ];
    }

    protected function manage(): void
    {
        $cfg = $this->loadFeedConfig();
        $highest = isset( $cfg['wizard_step'] ) ? (int) $cfg['wizard_step'] : 0;

        /* v160: Completed dealers can re-enter the wizard to reconfigure.
         * They land on step 5 (their highest step) where they can review
         * their current setup and navigate back to make changes. */

        if ( $highest >= 4 )      { $this->step5(); }
        elseif ( $highest >= 3 )  { $this->step4(); }
        elseif ( $highest >= 2 )  { $this->step3(); }
        elseif ( $highest >= 1 )  { $this->step2(); }
        else                      { $this->step1(); }
    }

    /* ============================================================
     * Step 1 - Feed Input
     * ============================================================ */

    protected function step1(): void
    {
        $cfg = $this->loadFeedConfig();

        $values = [
            'mode'             => 'url',
            'feed_url'         => isset( $cfg['feed_url'] ) ? (string) $cfg['feed_url'] : '',
            'feed_format'      => isset( $cfg['feed_format'] ) && (string) $cfg['feed_format'] !== '' ? (string) $cfg['feed_format'] : 'xml',
            'auth_type'        => isset( $cfg['auth_type'] ) && (string) $cfg['auth_type'] !== '' ? (string) $cfg['auth_type'] : 'none',
            'auth_credentials' => isset( $cfg['auth_credentials'] ) ? (string) $cfg['auth_credentials'] : '',
            'paste_body'       => '',
            'urls'             => $this->wizardUrls(),
            'csrfKey'          => \IPS\Session::i()->csrfKey,
        ];

        $state = $this->loadWizardState();
        if ( !empty( $state['mode'] ) )       { $values['mode']       = (string) $state['mode']; }
        if ( !empty( $state['paste_body'] ) ) { $values['paste_body'] = (string) $state['paste_body']; }

        $body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep1(
            $this->wizardData( 1 ),
            $values
        );
        $this->output( 'setupWizard', $body );
    }

    protected function saveStep1(): void
    {
        \IPS\Session::i()->csrfCheck();

        $mode             = strtolower( trim( (string) ( \IPS\Request::i()->mode ?? 'url' ) ) );
        $feedUrl          = trim( (string) ( \IPS\Request::i()->feed_url ?? '' ) );
        $feedFormat       = strtolower( trim( (string) ( \IPS\Request::i()->feed_format ?? '' ) ) );
        $authType         = strtolower( trim( (string) ( \IPS\Request::i()->auth_type ?? 'none' ) ) );
        $authCredentials  = trim( (string) ( \IPS\Request::i()->auth_credentials ?? '' ) );
        $pasteBody        = (string) ( \IPS\Request::i()->paste_body ?? '' );

        $errors = [];

        if ( !in_array( $mode, [ 'url', 'paste', 'upload' ], true ) )
        {
            $errors[] = 'Please choose Fetch from URL, Paste feed body, or Upload a file.';
            $mode = 'url';
        }

        if ( !in_array( $feedFormat, [ 'xml', 'json', 'csv' ], true ) )
        {
            $errors[] = 'Please choose a feed format (XML, JSON, or CSV).';
        }

        $uploadFileUrl  = '';
        $uploadFileName = '';
        $uploadFileSize = 0;
        $uploadFormat   = '';

        if ( $mode === 'upload' )
        {
            try
            {
                $files = \IPS\File::createFromUploads(
                    'gddealer_FeedUpload',
                    'upload_file',
                    [ 'csv', 'xml', 'json', 'tsv', 'txt' ],
                    50
                );
            }
            catch ( \Throwable $e )
            {
                $errors[] = 'Upload failed: ' . $e->getMessage();
                $files = [];
            }

            if ( empty( $files ) )
            {
                $errors[] = 'Please choose a feed file to upload (CSV, XML, JSON, TSV, or TXT, up to 50 MB).';
            }
            else
            {
                /** @var \IPS\File $uploadedFile */
                $uploadedFile   = reset( $files );
                $uploadFileUrl  = (string) $uploadedFile;
                $uploadFileName = (string) ( $uploadedFile->originalFilename ?? $uploadedFile->filename ?? 'feed' );
                $uploadFileSize = (int) ( $uploadedFile->filesize() ?? 0 );

                $ext = strtolower( pathinfo( $uploadFileName, PATHINFO_EXTENSION ) );
                $uploadFormat = match ( $ext )
                {
                    'xml'  => 'xml',
                    'json' => 'json',
                    'csv', 'tsv', 'txt' => 'csv',
                    default => 'csv',
                };

                /* Override the form's feed_format with the detected one
                 * so subsequent code paths use the right value. */
                $feedFormat = $uploadFormat;
            }
        }
        elseif ( $mode === 'url' )
        {
            if ( $feedUrl === '' )
            {
                $errors[] = 'Feed URL is required when fetching from a URL.';
            }
            elseif ( !preg_match( '#^https?://#i', $feedUrl ) )
            {
                $errors[] = 'Feed URL must start with http:// or https://.';
            }

            if ( !in_array( $authType, [ 'none', 'basic', 'api_key' ], true ) )
            {
                $errors[] = 'Invalid authentication type.';
                $authType = 'none';
            }

            if ( $authType !== 'none' && $authCredentials === '' )
            {
                $errors[] = 'Authentication credentials are required when auth type is set.';
            }
        }
        else
        {
            if ( $pasteBody === '' )
            {
                $errors[] = 'Please paste a feed body, or switch to "Fetch from URL".';
            }
            elseif ( strlen( $pasteBody ) > 10 * 1024 * 1024 )
            {
                $errors[] = 'Pasted feed body exceeds 10 MB. Use the URL fetch mode for large feeds.';
            }
        }

        if ( !empty( $errors ) )
        {
            $values = [
                'mode' => $mode, 'feed_url' => $feedUrl, 'feed_format' => $feedFormat,
                'auth_type' => $authType, 'auth_credentials' => $authCredentials,
                'paste_body' => $pasteBody,
                'urls' => $this->wizardUrls(),
                'csrfKey' => \IPS\Session::i()->csrfKey,
            ];
            $body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep1(
                $this->wizardData( 1 ),
                $values,
                $errors
            );
            $this->output( 'setupWizard', $body );
            return;
        }

        $update = [ 'feed_format' => $feedFormat ];
        if ( $mode === 'upload' )
        {
            /* Upload mode forces delivery to manual + clears any URL/auth
             * that might have been previously configured. */
            $update['feed_delivery_mode'] = 'manual';
            $update['feed_url']           = '';
            $update['auth_type']          = 'none';
            $update['auth_credentials']   = '';

            /* Record the upload in gd_dealer_feed_uploads so it shows
             * up in upload history AND the import scheduler picks it
             * up on its next run. */
            try
            {
                \IPS\Db::i()->insert( 'gd_dealer_feed_uploads', [
                    'dealer_id'       => (int) $this->dealer->dealer_id,
                    'upload_format'   => $uploadFormat,
                    'file_url'        => $uploadFileUrl,
                    'file_name'       => $uploadFileName,
                    'file_size_bytes' => $uploadFileSize,
                    'uploaded_at'     => time(),
                    'uploaded_by'     => (int) \IPS\Member::loggedIn()->member_id,
                ] );
            }
            catch ( \Throwable $e )
            {
                try { \IPS\Log::log( 'wizard saveStep1 upload insert failed: ' . $e->getMessage(), 'gddealer_setupwizard' ); } catch ( \Throwable ) {}
            }
        }
        elseif ( $mode === 'url' )
        {
            $update['feed_url']         = $feedUrl;
            $update['auth_type']        = $authType;
            $update['auth_credentials'] = $authCredentials;
        }
        $update['wizard_step'] = max( 1, (int) ( $this->loadFeedConfig()['wizard_step'] ?? 0 ) );

        try
        {
            \IPS\Db::i()->update( 'gd_dealer_feed_config', $update,
                [ 'dealer_id=?', (int) $this->dealer->dealer_id ]
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'wizard saveStep1 update failed: ' . $e->getMessage(), 'gddealer_setupwizard' ); } catch ( \Throwable ) {}
            $values = [
                'mode' => $mode, 'feed_url' => $feedUrl, 'feed_format' => $feedFormat,
                'auth_type' => $authType, 'auth_credentials' => $authCredentials,
                'paste_body' => $pasteBody,
                'urls' => $this->wizardUrls(),
                'csrfKey' => \IPS\Session::i()->csrfKey,
            ];
            $body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep1(
                $this->wizardData( 1 ),
                $values,
                [ 'Could not save your input. Please try again or contact support.' ]
            );
            $this->output( 'setupWizard', $body );
            return;
        }

        $state = $this->loadWizardState();
        $state['mode']            = $mode;
        $state['paste_body']      = $mode === 'paste'  ? $pasteBody     : '';
        $state['upload_file_url'] = $mode === 'upload' ? $uploadFileUrl : '';
        unset( $state['step2_fetch'], $state['step2_records'], $state['step2_fields'], $state['step4_report'], $state['step4_rows'] );
        $this->saveWizardState( $state );

        \IPS\Output::i()->redirect(
            \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step2', 'front', 'dealers_setup_wizard' )
        );
    }

    /* ============================================================
     * Step 2 - Test Fetch + Parse + Field Discovery
     * ============================================================ */

    protected function step2(): void
    {
        $cfg = $this->loadFeedConfig();
        $highest = isset( $cfg['wizard_step'] ) ? (int) $cfg['wizard_step'] : 0;
        if ( $highest < 1 )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step1', 'front', 'dealers_setup_wizard' )
            );
            return;
        }

        $state = $this->loadWizardState();

        $forceRefetch = (int) ( \IPS\Request::i()->refetch ?? 0 ) === 1;
        $hasCached = !empty( $state['step2_fetch'] );

        if ( !$hasCached || $forceRefetch )
        {
            $this->performStep2Fetch();
            $state = $this->loadWizardState();
        }

        $body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep2(
            $this->wizardData( 2 ),
            [
                'mode'              => isset( $state['mode'] ) ? (string) $state['mode'] : 'url',
                'feed_url'          => isset( $cfg['feed_url'] ) ? (string) $cfg['feed_url'] : '',
                'feed_format'       => isset( $cfg['feed_format'] ) ? (string) $cfg['feed_format'] : '',
                'feed_format_label' => isset( $cfg['feed_format'] ) && (string) $cfg['feed_format'] !== ''
                    ? strtoupper( (string) $cfg['feed_format'] )
                    : '(none)',
                'fetch'             => isset( $state['step2_fetch'] ) && is_array( $state['step2_fetch'] ) ? $state['step2_fetch'] : null,
                'records'           => isset( $state['step2_records'] ) && is_array( $state['step2_records'] ) ? $state['step2_records'] : [],
                'fields'            => isset( $state['step2_fields'] ) && is_array( $state['step2_fields'] ) ? $state['step2_fields'] : [],
                'parse_error'       => isset( $state['step2_parse_error'] ) ? (string) $state['step2_parse_error'] : '',
                'field_count'       => isset( $state['step2_fields'] ) && is_array( $state['step2_fields'] ) ? count( $state['step2_fields'] ) : 0,
                'sample_count'      => isset( $state['step2_fields'][0]['count'] ) ? (int) $state['step2_fields'][0]['count'] : 0,
                'body_bytes_fmt'    => isset( $state['step2_fetch']['body_bytes'] ) ? number_format( (int) $state['step2_fetch']['body_bytes'] ) : '0',
                'urls'              => $this->wizardUrls(),
                'csrfKey'           => \IPS\Session::i()->csrfKey,
            ]
        );
        $this->output( 'setupWizard', $body );
    }

    protected function saveStep2(): void
    {
        \IPS\Session::i()->csrfCheck();

        $state = $this->loadWizardState();

        if ( empty( $state['step2_fetch'] ) || empty( $state['step2_fetch']['ok'] ) || !empty( $state['step2_parse_error'] ) )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step2', 'front', 'dealers_setup_wizard' )
            );
            return;
        }

        $cfg = $this->loadFeedConfig();
        $update = [ 'wizard_step' => max( 2, (int) ( $cfg['wizard_step'] ?? 0 ) ) ];

        try
        {
            \IPS\Db::i()->update( 'gd_dealer_feed_config', $update,
                [ 'dealer_id=?', (int) $this->dealer->dealer_id ]
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'wizard saveStep2 update failed: ' . $e->getMessage(), 'gddealer_setupwizard' ); } catch ( \Throwable ) {}
        }

        /* v159: invalidate downstream cached validation. New parsed
         * records mean step 4's prior report is stale. */
        $state = $this->loadWizardState();
        unset( $state['step4_report'], $state['step4_rows'] );
        $this->saveWizardState( $state );

        \IPS\Output::i()->redirect(
            \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step3', 'front', 'dealers_setup_wizard' )
        );
    }

    protected function performStep2Fetch(): void
    {
        $cfg = $this->loadFeedConfig();
        $state = $this->loadWizardState();

        $mode       = isset( $state['mode'] ) ? (string) $state['mode'] : 'url';
        $feedFormat = isset( $cfg['feed_format'] ) ? (string) $cfg['feed_format'] : 'xml';
        $body       = '';
        $fetchMeta  = null;

        if ( $mode === 'url' )
        {
            $url       = isset( $cfg['feed_url'] ) ? (string) $cfg['feed_url'] : '';
            $authType  = isset( $cfg['auth_type'] ) ? (string) $cfg['auth_type'] : 'none';
            $authCreds = isset( $cfg['auth_credentials'] ) ? (string) $cfg['auth_credentials'] : '';

            $fetch = FeedFetcher::fetch( $url, $authType, $authCreds );
            $body  = (string) $fetch['body'];

            $fetchMeta = [
                'ok'           => $fetch['ok'],
                'http_status'  => $fetch['http_status'],
                'content_type' => $fetch['content_type'],
                'body_bytes'   => $fetch['body_bytes'],
                'truncated'    => $fetch['truncated'],
                'duration_ms'  => $fetch['duration_ms'],
                'error'        => $fetch['error'],
                'preview'      => substr( $body, 0, 800 ),
            ];
        }
        elseif ( $mode === 'upload' )
        {
            $fileUrl = isset( $state['upload_file_url'] ) ? (string) $state['upload_file_url'] : '';
            $fileName = '(uploaded file)';
            $body = '';
            $err  = null;

            if ( $fileUrl === '' )
            {
                $err = 'No uploaded file found in wizard state. Go back to step 1 and upload again.';
            }
            else
            {
                try
                {
                    $file = \IPS\File::get( 'gddealer_FeedUpload', $fileUrl );
                    $body = (string) $file->contents();
                    $fileName = (string) ( $file->originalFilename ?? $file->filename ?? '(uploaded file)' );
                }
                catch ( \Throwable $e )
                {
                    $err = 'Could not read uploaded file: ' . $e->getMessage();
                }
            }

            $fetchMeta = [
                'ok'           => $err === null && $body !== '',
                'http_status'  => 0,
                'content_type' => '(uploaded: ' . $fileName . ')',
                'body_bytes'   => strlen( $body ),
                'truncated'    => false,
                'duration_ms'  => 0,
                'error'        => $err,
                'preview'      => substr( $body, 0, 800 ),
            ];
        }
        else
        {
            $body = isset( $state['paste_body'] ) ? (string) $state['paste_body'] : '';
            $fetchMeta = [
                'ok'           => $body !== '',
                'http_status'  => 0,
                'content_type' => '(pasted)',
                'body_bytes'   => strlen( $body ),
                'truncated'    => false,
                'duration_ms'  => 0,
                'error'        => $body === '' ? 'No pasted feed body found.' : null,
                'preview'      => substr( $body, 0, 800 ),
            ];
        }

        if ( !$fetchMeta['ok'] || $body === '' )
        {
            $state['step2_fetch']       = $fetchMeta;
            $state['step2_records']     = [];
            $state['step2_fields']      = [];
            $state['step2_parse_error'] = '';
            $this->saveWizardState( $state );
            return;
        }

        $records = [];
        $parseError = '';
        try
        {
            $records = match ( strtolower( $feedFormat ) ) {
                'xml'  => XmlParser::parse( $body ),
                'json' => JsonParser::parse( $body ),
                'csv'  => CsvParser::parse( $body ),
                default => throw new \RuntimeException( "Unknown feed format: '{$feedFormat}'." ),
            };
        }
        catch ( \Throwable $e )
        {
            $parseError = $e->getMessage();
        }

        $sample = array_slice( $records, 0, 10 );
        $fields = FeedFetcher::discoverFields( $sample );

        $state['step2_fetch']       = $fetchMeta;
        $state['step2_records']     = $sample;
        $state['step2_fields']      = $fields;
        $state['step2_parse_error'] = $parseError;
        $state['step2_total_count'] = count( $records );
        $this->saveWizardState( $state );
    }

    /* ============================================================
     * Step 3 - Field Mapping with Auto-Suggest (v154 with defaults)
     * ============================================================ */

    /**
     * Render step 3. Reads cached discovered fields from
     * wizard_state_json and existing field_mapping from
     * gd_dealer_feed_config. Inverse-view mapping UI: one row per
     * canonical field, dropdown of all dealer fields per row,
     * plus an optional "use default" toggle for canonical fields
     * that publish a default value (condition, free_shipping,
     * in_stock as of v154).
     *
     * Gating: requires wizard_step >= 2.
     *
     * Query params:
     *   ?reset=1  - clear current mapping and re-run auto-suggest
     */
    protected function step3(): void
    {
        $cfg = $this->loadFeedConfig();
        $highest = isset( $cfg['wizard_step'] ) ? (int) $cfg['wizard_step'] : 0;
        if ( $highest < 2 )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step2', 'front', 'dealers_setup_wizard' )
            );
            return;
        }

        $state = $this->loadWizardState();

        if ( empty( $state['step2_fields'] ) )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step2&refetch=1', 'front', 'dealers_setup_wizard' )
            );
            return;
        }

        $reset = (int) ( \IPS\Request::i()->reset ?? 0 ) === 1;

        $body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep3(
            $this->wizardData( 3 ),
            $this->buildStep3Values( $cfg, $state, $reset )
        );
        $this->output( 'setupWizard', $body );
    }

    protected function saveStep3(): void
    {
        \IPS\Session::i()->csrfCheck();

        $cfg = $this->loadFeedConfig();
        $highest = isset( $cfg['wizard_step'] ) ? (int) $cfg['wizard_step'] : 0;
        if ( $highest < 2 )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step2', 'front', 'dealers_setup_wizard' )
            );
            return;
        }

        /* Form submits 3 inputs per canonical field:
         *   source[CANONICAL_SLUG]   - 'feed' | 'default' | 'none'
         *   mapping[CANONICAL_SLUG]  - dealer field name (when source=feed)
         *   default[CANONICAL_SLUG]  - override default value (when source=default)
         *
         * Stored shape (compatible with FieldMapper::apply):
         *   {
         *     "_defaults": { canonical: value, ... },
         *     dealerField: canonicalSlug,
         *     ...
         *   }
         */
        $sources    = \IPS\Request::i()->source ?? [];
        $mappingPos = \IPS\Request::i()->mapping ?? [];
        $defaultsIn = \IPS\Request::i()->{'default'} ?? [];

        if ( !is_array( $sources ) )    { $sources    = []; }
        if ( !is_array( $mappingPos ) ) { $mappingPos = []; }
        if ( !is_array( $defaultsIn ) ) { $defaultsIn = []; }

        $state = $this->loadWizardState();
        $discoveredFieldNames = [];
        if ( !empty( $state['step2_fields'] ) && is_array( $state['step2_fields'] ) )
        {
            foreach ( $state['step2_fields'] as $f )
            {
                if ( isset( $f['field'] ) ) { $discoveredFieldNames[] = (string) $f['field']; }
            }
        }
        $discoveredSet = array_flip( $discoveredFieldNames );

        $allCanonical = CanonicalFields::all();

        $fieldMap = [];   /* dealerField => canonicalSlug */
        $defaults = [];   /* canonicalSlug => value */
        $errors   = [];

        foreach ( $allCanonical as $canonicalSlug => $fieldDef )
        {
            $source = isset( $sources[ $canonicalSlug ] ) ? (string) $sources[ $canonicalSlug ] : 'none';

            if ( $source === 'feed' )
            {
                $dealerField = trim( (string) ( $mappingPos[ $canonicalSlug ] ?? '' ) );
                if ( $dealerField === '' )
                {
                    /* Source said feed but no field picked - silently treat as unmapped. */
                    continue;
                }
                if ( !isset( $discoveredSet[ $dealerField ] ) )
                {
                    $errors[] = "Invalid dealer field '{$dealerField}' for {$canonicalSlug}.";
                    continue;
                }
                /* Storage shape is {dealerField: canonical} - if multiple
                 * canonicals point to the same dealer field, last write wins.
                 * That's a UX concern at form time, not here. */
                $fieldMap[ $dealerField ] = $canonicalSlug;
            }
            elseif ( $source === 'default' )
            {
                /* The dealer accepted the default, optionally with an override value. */
                if ( !isset( $fieldDef['default'] ) )
                {
                    /* Field doesn't publish a default - silently skip. */
                    continue;
                }
                $publishedDefault = (string) $fieldDef['default'];
                $userOverride     = isset( $defaultsIn[ $canonicalSlug ] ) ? trim( (string) $defaultsIn[ $canonicalSlug ] ) : '';
                $defaults[ $canonicalSlug ] = $userOverride !== '' ? $userOverride : $publishedDefault;
            }
            /* source === 'none' means dealer is leaving the field unmapped - do nothing. */
        }

        if ( !empty( $errors ) )
        {
            $body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep3(
                $this->wizardData( 3 ),
                $this->buildStep3Values( $cfg, $state, false, [
                    'sources'  => $sources,
                    'mapping'  => $mappingPos,
                    'defaults' => $defaultsIn,
                ], $errors )
            );
            $this->output( 'setupWizard', $body );
            return;
        }

        /* Final stored shape. _defaults goes first only for human readability
         * in the admin TextArea; JSON ordering is irrelevant to readers. */
        $stored = [];
        if ( !empty( $defaults ) )
        {
            $stored['_defaults'] = $defaults;
        }
        foreach ( $fieldMap as $dealerField => $canonical )
        {
            $stored[ $dealerField ] = $canonical;
        }

        $update = [
            'field_mapping' => json_encode( $stored, JSON_UNESCAPED_SLASHES ),
            'wizard_step'   => max( 3, (int) ( $cfg['wizard_step'] ?? 0 ) ),
        ];

        try
        {
            \IPS\Db::i()->update( 'gd_dealer_feed_config', $update,
                [ 'dealer_id=?', (int) $this->dealer->dealer_id ]
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'wizard saveStep3 update failed: ' . $e->getMessage(), 'gddealer_setupwizard' ); } catch ( \Throwable ) {}
            $body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep3(
                $this->wizardData( 3 ),
                $this->buildStep3Values( $cfg, $state, false, [
                    'sources'  => $sources,
                    'mapping'  => $mappingPos,
                    'defaults' => $defaultsIn,
                ], [ 'Could not save your mapping. Please try again.' ] )
            );
            $this->output( 'setupWizard', $body );
            return;
        }

        /* v159: invalidate cached step 4 validation. The new field
         * mapping changes which canonical fields each record produces,
         * so the prior report is stale. */
        unset( $state['step4_report'], $state['step4_rows'] );
        $this->saveWizardState( $state );

        \IPS\Output::i()->redirect(
            \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step4', 'front', 'dealers_setup_wizard' )
        );
    }

    /**
     * Build $values array for the step 3 template.
     *
     * Saved field_mapping shape (read at load):
     *   {
     *     "_defaults": { canonicalSlug: value },
     *     dealerField: canonicalSlug,
     *     ...
     *   }
     *
     * For each canonical field we compute:
     *   - source: 'feed' | 'default' | 'none'
     *   - selected_dealer_field: which dealer field is mapped (when source=feed)
     *   - default_value: published default (if any)
     *   - default_override: user's override for the default (when source=default)
     *
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $state
     * @param bool $reset
     * @param array<string, array<string,string>>|null $override Form POST that failed validation.
     *        Shape: ['sources' => [], 'mapping' => [], 'defaults' => []]
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    protected function buildStep3Values( array $cfg, array $state, bool $reset, ?array $override = null, array $errors = [] ): array
    {
        $discovered = [];
        $sampleFor  = [];
        if ( !empty( $state['step2_fields'] ) && is_array( $state['step2_fields'] ) )
        {
            foreach ( $state['step2_fields'] as $f )
            {
                if ( !isset( $f['field'] ) ) { continue; }
                $name = (string) $f['field'];
                $discovered[] = $name;
                $sampleFor[ $name ] = isset( $f['sample'] ) ? (string) $f['sample'] : '';
            }
        }

        /* Saved field_mapping (when revisiting). Inverted into
         * {canonical: dealerField} for easier per-row lookup, plus a
         * separate $savedDefaults map for canonicals served by defaults. */
        $savedFieldMap = [];   /* canonical => dealerField */
        $savedDefaults = [];   /* canonical => value */
        if ( !$reset && !empty( $cfg['field_mapping'] ) )
        {
            $decoded = json_decode( (string) $cfg['field_mapping'], true );
            if ( is_array( $decoded ) )
            {
                if ( isset( $decoded['_defaults'] ) && is_array( $decoded['_defaults'] ) )
                {
                    foreach ( $decoded['_defaults'] as $canonical => $value )
                    {
                        $savedDefaults[ (string) $canonical ] = (string) $value;
                    }
                }
                foreach ( $decoded as $key => $value )
                {
                    if ( $key === '_defaults' ) { continue; }
                    /* Saved shape is {dealerField: canonical}. */
                    $savedFieldMap[ (string) $value ] = (string) $key;
                }
            }
        }

        /* Auto-suggest for any canonical not already mapped. */
        $suggestions = CanonicalFields::buildSuggestionMap( $discovered );
        $autoMapping = [];   /* canonical => dealerField */
        foreach ( $suggestions as $dealerField => $canonicalSlug )
        {
            if ( !isset( $autoMapping[ $canonicalSlug ] ) )
            {
                $autoMapping[ $canonicalSlug ] = $dealerField;
            }
        }

        /* Decide source + values for each canonical field.
         * Priority: form override > saved > auto > default-if-published > none. */
        $rows = [];
        foreach ( CanonicalFields::all() as $slug => $field )
        {
            $hasDefault       = isset( $field['default'] );
            $publishedDefault = $hasDefault ? (string) $field['default'] : '';

            $source                = 'none';
            $selectedDealerField   = '';
            $defaultOverride       = '';
            $isAutoSuggested       = false;

            if ( $override !== null )
            {
                /* The form was just submitted with errors. Restore exactly
                 * what the user entered, even if invalid. */
                $source              = isset( $override['sources'][ $slug ] ) ? (string) $override['sources'][ $slug ] : 'none';
                $selectedDealerField = isset( $override['mapping'][ $slug ] ) ? (string) $override['mapping'][ $slug ] : '';
                $defaultOverride     = isset( $override['defaults'][ $slug ] ) ? (string) $override['defaults'][ $slug ] : '';
            }
            elseif ( isset( $savedFieldMap[ $slug ] ) )
            {
                $source              = 'feed';
                $selectedDealerField = $savedFieldMap[ $slug ];
            }
            elseif ( isset( $savedDefaults[ $slug ] ) )
            {
                $source          = 'default';
                $defaultOverride = $savedDefaults[ $slug ] === $publishedDefault ? '' : $savedDefaults[ $slug ];
            }
            elseif ( isset( $autoMapping[ $slug ] ) )
            {
                $source              = 'feed';
                $selectedDealerField = $autoMapping[ $slug ];
                $isAutoSuggested     = true;
            }
            elseif ( $hasDefault && ( $field['req'] ?? '' ) === CanonicalFields::REQ_REQUIRED )
            {
                /* Required field with no feed match - pre-select the default
                 * so the dealer doesn't have to do anything. */
                $source = 'default';
            }

            $isBooleanDefault = $hasDefault && in_array( $publishedDefault, [ 'true', 'false' ], true );

            $rows[ $slug ] = [
                'slug'                => $slug,
                'label'               => $field['label'] ?? $slug,
                'group'               => $field['group'] ?? '',
                'req'                 => $field['req'] ?? '',
                'has_default'         => $hasDefault,
                'published_default'   => $publishedDefault,
                'is_boolean_default'  => $isBooleanDefault,
                'source'              => $source,
                'selected_dealer_field' => $selectedDealerField,
                'default_override'    => $defaultOverride,
                'is_auto_suggested'   => $isAutoSuggested,
            ];
        }

        /* Build grouped structure with rows nested by group. */
        $grouped = [];
        foreach ( CanonicalFields::grouped() as $groupKey => $group )
        {
            if ( empty( $group['fields'] ) ) { continue; }
            $groupRows = [];
            foreach ( $group['fields'] as $field )
            {
                $slug = $field['slug'];
                if ( isset( $rows[ $slug ] ) ) { $groupRows[] = $rows[ $slug ]; }
            }
            $grouped[] = [
                'key'    => $groupKey,
                'label'  => $group['label'],
                'rows'   => $groupRows,
            ];
        }

        /* Required-field summary. A required field counts as "satisfied"
         * if source=feed (with valid dealer field) OR source=default. */
        $requiredTotal    = 0;
        $requiredMapped   = 0;
        $requiredUnmapped = [];
        foreach ( $rows as $slug => $row )
        {
            if ( $row['req'] !== CanonicalFields::REQ_REQUIRED ) { continue; }
            $requiredTotal++;
            $satisfied = ( $row['source'] === 'feed' && $row['selected_dealer_field'] !== '' )
                      || ( $row['source'] === 'default' );
            if ( $satisfied )
            {
                $requiredMapped++;
            }
            else
            {
                $requiredUnmapped[] = [ 'slug' => $slug, 'label' => $row['label'] ];
            }
        }

        /* Used dealer fields count (for the "X of Y of your fields used" stat). */
        $usedDealerFields = [];
        foreach ( $rows as $row )
        {
            if ( $row['source'] === 'feed' && $row['selected_dealer_field'] !== '' )
            {
                $usedDealerFields[ $row['selected_dealer_field'] ] = true;
            }
        }

        /* Auto-suggested count. */
        $autoCount = 0;
        foreach ( $rows as $row )
        {
            if ( $row['is_auto_suggested'] ) { $autoCount++; }
        }

        $completedAt = isset( $cfg['wizard_completed_at'] ) ? (string) $cfg['wizard_completed_at'] : '';

        return [
            'urls'              => $this->wizardUrls(),
            'csrfKey'           => \IPS\Session::i()->csrfKey,
            'discovered'        => $discovered,
            'discovered_count'  => count( $discovered ),
            'sample_for'        => $sampleFor,
            'sample_for_json'   => json_encode( $sampleFor, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS ),
            'grouped'           => $grouped,
            'auto_count'        => $autoCount,
            'required_total'    => $requiredTotal,
            'required_mapped'   => $requiredMapped,
            'required_unmapped' => $requiredUnmapped,
            'used_dealer_count' => count( $usedDealerFields ),
            'errors'            => $errors,
        ];
    }

    /* ============================================================
     * Step 4 - Validate Sample (v155)
     * ============================================================ */

    /**
     * Render step 4. Reads cached step 2 records and saved field_mapping
     * from gd_dealer_feed_config. Applies the field map (with _defaults)
     * to each record producing canonical-shape records, then runs them
     * through Validator::validate() and renders the report.
     *
     * Validation results are cached in wizard_state_json so revisiting
     * the page doesn't re-run validation. Pass ?revalidate=1 to refresh.
     *
     * Gating: requires wizard_step >= 3.
     */
    protected function step4(): void
    {
        $cfg = $this->loadFeedConfig();
        $highest = isset( $cfg['wizard_step'] ) ? (int) $cfg['wizard_step'] : 0;
        if ( $highest < 3 )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step3', 'front', 'dealers_setup_wizard' )
            );
            return;
        }

        $state = $this->loadWizardState();

        if ( empty( $state['step2_records'] ) )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step2&refetch=1', 'front', 'dealers_setup_wizard' )
            );
            return;
        }

        $forceRevalidate = (int) ( \IPS\Request::i()->revalidate ?? 0 ) === 1;
        $hasCached = !empty( $state['step4_report'] );

        if ( !$hasCached || $forceRevalidate )
        {
            $this->runStep4Validation();
            $state = $this->loadWizardState();
        }

        $report = isset( $state['step4_report'] ) && is_array( $state['step4_report'] ) ? $state['step4_report'] : null;
        $rows   = isset( $state['step4_rows'] ) && is_array( $state['step4_rows'] ) ? $state['step4_rows'] : [];

        /* Pre-compute the 3 stat-pill class strings. We can't use
         * {{if expr > 0}} inside an HTML class attribute - the IPS
         * template parser misreads the > as end-of-tag and produces
         * EX0. Computing here and passing as plain strings is safe. */
        $errorRecords   = isset( $report['summary']['error_records'] ) ? (int) $report['summary']['error_records'] : 0;
        $warningRecords = isset( $report['summary']['warning_records'] ) ? (int) $report['summary']['warning_records'] : 0;
        $validRecords   = isset( $report['summary']['valid_records'] ) ? (int) $report['summary']['valid_records'] : 0;

        $body = (string) \IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->setupWizardStep4(
            $this->wizardData( 4 ),
            [
                'urls'           => $this->wizardUrls(),
                'csrfKey'        => \IPS\Session::i()->csrfKey,
                'report'         => $report,
                'rows'           => $rows,
                'error_class'    => $errorRecords > 0 ? 'gdSetupWizard__stat--bad'  : 'gdSetupWizard__stat--neutral',
                'warn_class'     => $warningRecords > 0 ? 'gdSetupWizard__stat--warn' : 'gdSetupWizard__stat--neutral',
                'continue_ready' => $validRecords > 0,
            ]
        );
        $this->output( 'setupWizard', $body );
    }

    protected function saveStep4(): void
    {
        \IPS\Session::i()->csrfCheck();

        $cfg = $this->loadFeedConfig();
        $highest = isset( $cfg['wizard_step'] ) ? (int) $cfg['wizard_step'] : 0;
        if ( $highest < 3 )
        {
            \IPS\Output::i()->redirect(
                \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step3', 'front', 'dealers_setup_wizard' )
            );
            return;
        }

        $update = [ 'wizard_step' => max( 4, (int) ( $cfg['wizard_step'] ?? 0 ) ) ];
        try
        {
            \IPS\Db::i()->update( 'gd_dealer_feed_config', $update,
                [ 'dealer_id=?', (int) $this->dealer->dealer_id ]
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'wizard saveStep4 update failed: ' . $e->getMessage(), 'gddealer_setupwizard' ); } catch ( \Throwable ) {}
        }

        \IPS\Output::i()->redirect(
            \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=setupwizard&do=step5', 'front', 'dealers_setup_wizard' )
        );
    }

    /**
     * Apply the field map to cached step 2 records (producing canonical
     * shape), pass through Validator, cache report + per-row data in
     * wizard_state_json.
     */
    protected function runStep4Validation(): void
    {
        $cfg = $this->loadFeedConfig();
        $state = $this->loadWizardState();

        $rawRecords = isset( $state['step2_records'] ) && is_array( $state['step2_records'] ) ? $state['step2_records'] : [];

        $fieldMap = [];
        if ( !empty( $cfg['field_mapping'] ) )
        {
            $decoded = json_decode( (string) $cfg['field_mapping'], true );
            if ( is_array( $decoded ) ) { $fieldMap = $decoded; }
        }

        /* Apply field map (canonical shape, suitable for Validator). */
        $canonical = [];
        foreach ( $rawRecords as $raw )
        {
            $canonical[] = FieldMapper::applyCanonical( $raw, $fieldMap );
        }

        /* Run validation. */
        $report = Validator::validate( $canonical );

        /* Build per-row data for the template. Each row gets its raw
         * record (for "your data" display), canonical record (for
         * "what we'd import"), and any errors/warnings tied to it. */
        $errorsByRow   = [];
        $warningsByRow = [];
        foreach ( $report['errors'] as $err )
        {
            $r = (int) ( $err['row'] ?? 0 );
            if ( $r > 0 ) { $errorsByRow[ $r ][] = $err; }
        }
        foreach ( $report['warnings'] as $warn )
        {
            $r = (int) ( $warn['row'] ?? 0 );
            if ( $r > 0 ) { $warningsByRow[ $r ][] = $warn; }
        }

        $rows = [];
        foreach ( $rawRecords as $i => $raw )
        {
            $rowNum = $i + 1;
            $rows[] = [
                'row'         => $rowNum,
                'raw'         => $raw,
                'canonical'   => $canonical[ $i ] ?? [],
                'errors'      => $errorsByRow[ $rowNum ] ?? [],
                'warnings'    => $warningsByRow[ $rowNum ] ?? [],
                'has_errors'  => !empty( $errorsByRow[ $rowNum ] ),
                'has_warnings' => !empty( $warningsByRow[ $rowNum ] ),
            ];
        }

        $state['step4_report'] = $report;
        $state['step4_rows']   = $rows;
        $this->saveWizardState( $state );
    }

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

        $completedAt = isset( $cfg['wizard_completed_at'] ) ? (string) $cfg['wizard_completed_at'] : '';

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
            'is_completed'      => $completedAt !== '',
            'completed_at'      => $completedAt,
        ];
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    /**
     * @return array<string, mixed>
     */
    protected function loadFeedConfig(): array
    {
        try
        {
            $row = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config',
                [ 'dealer_id=?', (int) $this->dealer->dealer_id ]
            )->first();
            return is_array( $row ) ? $row : [];
        }
        catch ( \Throwable )
        {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadWizardState(): array
    {
        $cfg = $this->loadFeedConfig();
        if ( empty( $cfg['wizard_state_json'] ) ) { return []; }
        $decoded = json_decode( (string) $cfg['wizard_state_json'], true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    protected function saveWizardState( array $state ): void
    {
        try
        {
            \IPS\Db::i()->update( 'gd_dealer_feed_config',
                [ 'wizard_state_json' => json_encode( $state, JSON_UNESCAPED_SLASHES ) ],
                [ 'dealer_id=?', (int) $this->dealer->dealer_id ]
            );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'saveWizardState failed: ' . $e->getMessage(), 'gddealer_setupwizard' ); } catch ( \Throwable ) {}
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function wizardData( int $currentStep ): array
    {
        $cfg = $this->loadFeedConfig();
        $highestSaved = isset( $cfg['wizard_step'] ) ? (int) $cfg['wizard_step'] : 0;

        $steps = [
            [ 'num' => 1, 'key' => 'step1', 'label' => 'Feed Input',     'desc' => 'URL or paste your feed body' ],
            [ 'num' => 2, 'key' => 'step2', 'label' => 'Test & Parse',   'desc' => 'Fetch and discover fields' ],
            [ 'num' => 3, 'key' => 'step3', 'label' => 'Field Mapping',  'desc' => 'Match your fields to ours' ],
            [ 'num' => 4, 'key' => 'step4', 'label' => 'Validate',       'desc' => 'Check sample records' ],
            [ 'num' => 5, 'key' => 'step5', 'label' => 'Preview & Save', 'desc' => 'Confirm and finish' ],
        ];

        return [
            'totalSteps'     => self::TOTAL_STEPS,
            'currentStep'    => $currentStep,
            'highestSaved'   => $highestSaved,
            'completed'      => !empty( $cfg['wizard_completed_at'] ),
            'steps'          => $steps,
            'savedFlash'     => (bool) (int) ( \IPS\Request::i()->saved ?? 0 ),
        ];
    }
}

class setupwizard extends _setupwizard {}
