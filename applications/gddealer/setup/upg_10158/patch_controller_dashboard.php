<?php
/**
 * v1.0.158 patch script for the dashboard controller (dashboard.php).
 *
 * Rewrites the feedSettings() method to read-only mode:
 *   - No more editable form (the wizard handles all configuration)
 *   - Read-only display of: delivery mode, feed URL, format, auth type,
 *     auth credentials presence, field mapping summary
 *   - Manual upload widget (still active for manual-mode dealers)
 *   - Upload history table (unchanged)
 *   - Import log + sync health card (unchanged)
 *   - "Open Setup Wizard" button to reconfigure
 *
 * The new template signature is the same ($data) but with new fields:
 *   - mapping_summary: count of mapped fields + count of defaults
 *   - has_credentials: bool, true if auth_credentials is non-empty
 *   - wizard_url: URL to launch the setup wizard
 *   - wizard_completed_at: timestamp when wizard finished (or null)
 *
 * Run from the repo root:
 *   php applications/gddealer/setup/upg_10158/patch_controller_dashboard.php
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
 * Replace the entire feedSettings() method.
 * ===================================================================== */

$startMarker = "\tprotected function feedSettings()\n\t{";

if ( str_contains( $content, "/* v158: read-only feed settings page" ) )
{
    echo "  patch (feedSettings rewrite): already applied\n";
}
else
{
    $startPos = strpos( $content, $startMarker );
    if ( $startPos === false )
    {
        fwrite( STDERR, "ERROR: feedSettings method start marker not found\n" );
        exit( 1 );
    }

    /* Find the matching closing brace by tracking depth from the opening. */
    $i = $startPos + strlen( $startMarker );
    $depth = 1;
    while ( $i < strlen( $content ) && $depth > 0 )
    {
        $c = $content[ $i ];
        if ( $c === '{' ) { $depth++; }
        elseif ( $c === '}' ) { $depth--; }
        $i++;
    }
    if ( $depth !== 0 )
    {
        fwrite( STDERR, "ERROR: could not find matching close brace for feedSettings\n" );
        exit( 1 );
    }

    $oldMethod = substr( $content, $startPos, $i - $startPos );

    $newMethod = <<<'PHPCODE'
	protected function feedSettings()
	{
		/* v158: read-only feed settings page. All editing happens in the
		 * setup wizard now. This page shows current config + the manual
		 * upload widget + recent activity. */
		$dealer = $this->dealer;
		$currentMode = (string) ( $dealer->feed_delivery_mode ?? 'url' );

		/* Pull wizard config row to surface completion status + mapping. */
		$wizardCfg = [];
		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config',
				[ 'dealer_id=?', (int) $dealer->dealer_id ]
			)->first();
			$wizardCfg = is_array( $row ) ? $row : [];
		}
		catch ( \Throwable ) {}

		/* Count mapped fields + defaults from the saved field_mapping JSON. */
		$mappedCount  = 0;
		$defaultCount = 0;
		$rawMappingJson = (string) ( $dealer->field_mapping ?? '' );
		if ( $rawMappingJson !== '' )
		{
			$decoded = json_decode( $rawMappingJson, true );
			if ( is_array( $decoded ) )
			{
				if ( isset( $decoded['_defaults'] ) && is_array( $decoded['_defaults'] ) )
				{
					$defaultCount = count( $decoded['_defaults'] );
				}
				foreach ( $decoded as $key => $value )
				{
					if ( $key === '_defaults' ) { continue; }
					$mappedCount++;
				}
			}
		}

		$creds = $dealer->getCredentials() ?? '';
		$hasCredentials = trim( (string) $creds ) !== '';

		$recentUploads = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_dealer_feed_uploads',
				[ 'dealer_id=?', (int) $dealer->dealer_id ],
				'uploaded_at DESC',
				[ 0, 10 ]
			) as $u )
			{
				$recentUploads[] = [
					'upload_id'       => (int) $u['upload_id'],
					'upload_format'   => (string) $u['upload_format'],
					'file_name'       => (string) ( $u['file_name'] ?? '' ),
					'file_size_bytes' => (int) ( $u['file_size_bytes'] ?? 0 ),
					'uploaded_at'     => (int) $u['uploaded_at'],
					'uploaded_ago'    => \IPS\DateTime::ts( (int) $u['uploaded_at'] )->relative(),
				];
			}
		}
		catch ( \Exception ) {}

		$importLog = [];
		try {
			foreach ( ImportLog::loadForDealer( (int) $dealer->dealer_id, 15 ) as $log )
			{
				$importLog[] = [
					'when'      => (string) $log->run_start,
					'when_ago'  => \IPS\DateTime::ts( strtotime( (string) $log->run_start ) )->relative(),
					'status'    => (string) $log->status,
					'records'   => (int) $log->records_total,
					'new'       => (int) ( $log->records_created ?? 0 ),
					'updated'   => (int) ( $log->records_updated ?? 0 ),
					'unmatched' => (int) ( $log->records_unmatched ?? 0 ),
					'error'     => (string) ( $log->error_log ?? '' ),
				];
			}
		} catch ( \Exception ) {}

		$latest = $importLog[0] ?? null;
		$syncHealth = 'healthy';
		$syncTitle  = 'Feed is healthy';
		$syncSub    = 'Ready to import when your feed updates';
		if ( !$latest ) {
			$syncHealth = 'warn';
			$syncTitle  = 'Feed not configured yet';
			$syncSub    = $currentMode === 'manual'
				? 'Upload your first feed file to start syncing'
				: 'Run the Setup Wizard to configure your feed';
		} elseif ( $latest['status'] === 'failed' ) {
			$syncHealth = 'error';
			$syncTitle  = 'Last import failed';
			$syncSub    = $latest['when_ago'] . ' — ' . ( $latest['error'] ?: 'Check configuration' );
		} elseif ( $latest['status'] === 'partial' ) {
			$syncHealth = 'warn';
			$syncTitle  = 'Last import was partial';
			$syncSub    = $latest['when_ago'];
		} else {
			$syncTitle  = 'Feed is healthy';
			$syncSub    = 'Last imported ' . $latest['when_ago'];
		}

		$wizardUrl = (string) \IPS\Http\Url::internal(
			'app=gddealer&module=dealers&controller=setupwizard',
			'front',
			'dealers_setup_wizard'
		);

		$wizardDoneFlash = (int) ( \IPS\Request::i()->wizard_done ?? 0 ) === 1;

		$data = [
			'dealer'              => $this->dealerSummary(),
			'tab_urls'            => $this->tabUrls(),
			'delivery_mode'       => $currentMode,
			'feed_url'            => (string) ( $dealer->feed_url ?? '' ),
			'feed_format'         => (string) ( $dealer->feed_format ?? '' ),
			'auth_type'           => (string) ( $dealer->auth_type ?? 'none' ),
			'has_credentials'     => $hasCredentials,
			'mapped_count'        => $mappedCount,
			'default_count'       => $defaultCount,
			'wizard_url'          => $wizardUrl,
			'wizard_completed_at' => isset( $wizardCfg['wizard_completed_at'] ) ? (string) $wizardCfg['wizard_completed_at'] : '',
			'wizard_done_flash'   => $wizardDoneFlash,
			'import_log'          => $importLog,
			'recent_uploads'      => $recentUploads,
			'upload_url'          => (string) \IPS\Http\Url::internal(
				'app=gddealer&module=dealers&controller=dashboard&do=uploadFeed'
			)->csrf(),
			'latest'              => $latest,
			'sync_health'         => $syncHealth,
			'sync_title'          => $syncTitle,
			'sync_sub'            => $syncSub,
		];

		$this->output( 'feedSettings',
			\IPS\Theme::i()->getTemplate( 'dealers', 'gddealer', 'front' )->feedSettings( $data )
		);
	}
PHPCODE;

    $content = str_replace( $oldMethod, $newMethod, $content );
    echo "  patch (feedSettings rewrite): applied\n";
    $applied++;
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
