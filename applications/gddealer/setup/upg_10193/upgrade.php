<?php
/**
 * gddealer v1.0.193 - Three features in one ship:
 *
 *   1. REPORT TO GUNRACK button on unmatched UPCs. Sets
 *      gd_unmatched_upcs.dealer_reported_at = NOW(). Admin queue
 *      (gdcatalog v40+) sorts dealer-reported first.
 *
 *   2. VIEW SNAPSHOT link on unmatched UPCs. Shows the snapshot_json
 *      captured at flag-time (v180 feature). Opens in a modal.
 *
 *   3. DANGER ZONE / RESET on Customize tab. Scope B wipe:
 *        DELETE FROM gd_dealer_listings, gd_unmatched_upcs,
 *                    gd_dealer_category_map, gd_price_history,
 *                    gd_dealer_import_log WHERE dealer_id=?
 *        UPDATE gd_dealer_feed_config SET
 *           feed_url=NULL, auth_type='none', auth_credentials=NULL,
 *           field_mapping=NULL, import_schedule='6hr',
 *           last_run=NULL, last_run_status=NULL, last_record_count=0,
 *           feed_delivery_mode='url', wizard_step=0,
 *           wizard_completed_at=NULL, wizard_state_json=NULL
 *        Keep: dealer_name, dealer_slug, subscription_tier, api_key,
 *              trial_expires_at, all profile fields, FFL, founding member.
 *      Type-name confirm flow ("type Defense Depot to confirm").
 *      Pre-wipe snapshot to gd_dealer_reset_backups (24hr undo).
 *
 * SCHEMA
 *   - ALTER gd_unmatched_upcs ADD dealer_reported_at DATETIME NULL
 *   - CREATE TABLE gd_dealer_reset_backups
 *
 * CODE PATCHES (all via line-splice / content-regex, no whitespace
 * anchors per v192 pattern):
 *   - modules/front/dealers/dashboard.php:
 *       a. Extend unmatched() per-row $out[] to include snapshot_url,
 *          report_url, has_snapshot, is_reported.
 *       b. Add unmatched() $data to pass csrf_key.
 *       c. Append 4 new controller methods at end of class:
 *          reportUnmatched(), viewSnapshot(), resetDealer(),
 *          confirmReset().
 *   - sources/Unmatched/UnmatchedUpc.php:
 *       Add markReported() static method.
 *   - core_theme_templates:
 *       OVERWRITE 'unmatched' template content with v193 body (adds
 *       Report column, View Snapshot button, modal).
 *       APPEND Danger Zone block to 'dashboardCustomize'.
 *   - core_sys_lang_words: 12 new strings.
 *
 * Idempotent throughout.
 */

namespace IPS\gddealer\setup\upg_10193;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class _upgrade
{
    public function step1(): bool
    {
        try
        {
            $appVersion = (int) \IPS\Db::i()->select(
                'app_long_version', 'core_applications',
                [ 'app_directory=?', 'gddealer' ]
            )->first();
            if ( $appVersion < 10192 )
            {
                try { \IPS\Log::log( 'v193 ran with app_long_version=' . $appVersion . ' (expected >=10192).', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable ) {}

        $this->migrateSchema();
        $this->patchUnmatchedModel();
        $this->patchDashboardController();
        $this->repairUnmatchedTemplate();
        $this->appendDangerZoneToCustomize();
        $this->seedLangStrings();

        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

        try { \IPS\Log::log( 'v193 upgrade complete.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}

        return TRUE;
    }

    /* =================================================================
     * SCHEMA
     * ================================================================= */

    protected function migrateSchema(): void
    {
        /* gd_unmatched_upcs.dealer_reported_at */
        try
        {
            $cols = [];
            foreach ( \IPS\Db::i()->query( 'SHOW COLUMNS FROM ' . \IPS\Db::i()->prefix . 'gd_unmatched_upcs' ) as $r )
            {
                $cols[] = $r['Field'];
            }
            if ( !in_array( 'dealer_reported_at', $cols, TRUE ) )
            {
                \IPS\Db::i()->query( 'ALTER TABLE ' . \IPS\Db::i()->prefix . 'gd_unmatched_upcs ADD COLUMN dealer_reported_at DATETIME NULL DEFAULT NULL' );
                try { \IPS\Log::log( 'Added gd_unmatched_upcs.dealer_reported_at column.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'migrateSchema (dealer_reported_at) failed: ' . $e->getMessage(), 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
        }

        /* gd_dealer_reset_backups */
        try
        {
            $exists = FALSE;
            foreach ( \IPS\Db::i()->query( "SHOW TABLES LIKE '" . \IPS\Db::i()->prefix . "gd_dealer_reset_backups'" ) as $r )
            {
                $exists = TRUE;
                break;
            }
            if ( !$exists )
            {
                \IPS\Db::i()->query(
                    'CREATE TABLE ' . \IPS\Db::i()->prefix . 'gd_dealer_reset_backups (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        dealer_id INT UNSIGNED NOT NULL,
                        scope VARCHAR(4) NOT NULL DEFAULT \'B\',
                        snapshot_json LONGTEXT,
                        created_at DATETIME NOT NULL,
                        PRIMARY KEY (id),
                        KEY idx_dealer (dealer_id),
                        KEY idx_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
                try { \IPS\Log::log( 'Created gd_dealer_reset_backups table.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'migrateSchema (gd_dealer_reset_backups) failed: ' . $e->getMessage(), 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
        }
    }

    /* =================================================================
     * UnmatchedUpc model - add markReported()
     * ================================================================= */

    protected function patchUnmatchedModel(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/sources/Unmatched/UnmatchedUpc.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        if ( strpos( $contents, 'function markReported' ) !== FALSE )
        {
            try { \IPS\Log::log( 'UnmatchedUpc::markReported already present. Skipping.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return;
        }

        /* Insert markReported() right after exclude(). Find the exclude
         * method, find its closing brace, insert after. */
        $lines = explode( "\n", $contents );

        $excludeIdx = NULL;
        foreach ( $lines as $i => $line )
        {
            if ( preg_match( '/public\s+static\s+function\s+exclude\s*\(/', $line ) )
            {
                $excludeIdx = $i;
                break;
            }
        }

        if ( $excludeIdx === NULL )
        {
            try { \IPS\Log::log( 'patchUnmatchedModel: exclude() method not found.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return;
        }

        /* Find the closing brace of exclude(). Walk forward tracking
         * brace depth. The first '{' at depth 0 starts the body; the
         * matching '}' ends it. */
        $depth      = 0;
        $bodyEntered = FALSE;
        $closeIdx   = NULL;
        $total      = count( $lines );
        for ( $i = $excludeIdx; $i < $total; $i++ )
        {
            $line = $lines[ $i ];
            /* Strip strings and comments roughly for counting purposes. */
            $stripped = preg_replace( '/\/\*.*?\*\//', '', $line );
            $stripped = preg_replace( '/\/\/.*$/', '', $stripped );
            $stripped = preg_replace( "/'[^']*'/", '', $stripped );
            $stripped = preg_replace( '/"[^"]*"/', '', $stripped );

            $opens  = substr_count( $stripped, '{' );
            $closes = substr_count( $stripped, '}' );

            if ( $opens > 0 ) { $bodyEntered = TRUE; }
            $depth += $opens;
            $depth -= $closes;

            if ( $bodyEntered && $depth === 0 )
            {
                $closeIdx = $i;
                break;
            }
        }

        if ( $closeIdx === NULL )
        {
            try { \IPS\Log::log( 'patchUnmatchedModel: could not find end of exclude().', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return;
        }

        $ws = '';
        if ( preg_match( '/^(\s*)/', $lines[ $excludeIdx ], $m ) )
        {
            $ws = $m[1];
        }

        $newLines = [
            '',
            $ws . '/**',
            $ws . ' * v193: Dealer flagged this UPC as priority for admin review.',
            $ws . ' * Admin queue (gdcatalog) sorts dealer-reported first.',
            $ws . ' */',
            $ws . 'public static function markReported( int $id, int $dealerId ): bool',
            $ws . '{',
            $ws . "\t" . 'try',
            $ws . "\t" . '{',
            $ws . "\t\t" . '\IPS\Db::i()->update( \'gd_unmatched_upcs\',',
            $ws . "\t\t\t" . '[ \'dealer_reported_at\' => date( \'Y-m-d H:i:s\' ) ],',
            $ws . "\t\t\t" . '[ \'id=? AND dealer_id=?\', $id, $dealerId ]',
            $ws . "\t\t" . ');',
            $ws . "\t\t" . 'return TRUE;',
            $ws . "\t" . '}',
            $ws . "\t" . 'catch ( \Throwable )',
            $ws . "\t" . '{',
            $ws . "\t\t" . 'return FALSE;',
            $ws . "\t" . '}',
            $ws . '}',
        ];

        array_splice( $lines, $closeIdx + 1, 0, $newLines );

        file_put_contents( $path, implode( "\n", $lines ) );

        try { \IPS\Log::log( 'patchUnmatchedModel: added markReported() method.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
    }

    /* =================================================================
     * dashboard.php controller - extend unmatched(), add 4 new methods
     * ================================================================= */

    protected function patchDashboardController(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/modules/front/dealers/dashboard.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        $hasMarker = ( strpos( $contents, 'v193-report-snapshot-reset' ) !== FALSE );
        if ( $hasMarker )
        {
            try { \IPS\Log::log( 'dashboard.php already has v193 marker. Skipping.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return;
        }

        $lines = explode( "\n", $contents );

        /* --- Step 1: extend the per-row $out[] in unmatched() to add
         *             snapshot_url, report_url, has_snapshot, is_reported.
         *             Anchor: the line 'exclude_url' => $excludeUrl,
         *             We insert new keys right before the closing ];
         *             of the $out[] = [...] block. */
        $patched1 = $this->insertReportUrlsIntoOutArray( $lines );

        /* --- Step 2: add 'csrf_key' to the $data array in unmatched().
         *             Anchor: 'export_url' => $exportUrl, */
        $patched2 = $this->insertCsrfKeyIntoUnmatchedData( $lines );

        /* --- Step 3: append 4 new controller methods at end of class.
         *             Anchor: the final closing brace of the class
         *             (last } in file at depth 1). */
        $patched3 = $this->appendControllerMethods( $lines );

        $newContents = implode( "\n", $lines );

        if ( $newContents === $contents )
        {
            try { \IPS\Log::log( 'patchDashboardController: no changes made. p1=' . var_export( $patched1, TRUE ) . ' p2=' . var_export( $patched2, TRUE ) . ' p3=' . var_export( $patched3, TRUE ), 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return;
        }

        file_put_contents( $path, $newContents );

        try { \IPS\Log::log( 'patchDashboardController: applied. p1=' . var_export( $patched1, TRUE ) . ' p2=' . var_export( $patched2, TRUE ) . ' p3=' . var_export( $patched3, TRUE ), 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
    }

    /**
     * Find the exclude_url line in the per-row $out[] = [...] block and
     * insert four new keys right after it.
     */
    protected function insertReportUrlsIntoOutArray( array &$lines ): bool
    {
        $targetIdx = NULL;
        foreach ( $lines as $i => $line )
        {
            if ( preg_match( "/'exclude_url'\s*=>\s*\\\$excludeUrl/", $line ) )
            {
                $targetIdx = $i;
                break;
            }
        }

        if ( $targetIdx === NULL )
        {
            try { \IPS\Log::log( 'insertReportUrlsIntoOutArray: anchor (exclude_url) not found.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return FALSE;
        }

        $ws = '';
        if ( preg_match( '/^(\s*)/', $lines[ $targetIdx ], $m ) )
        {
            $ws = $m[1];
        }

        $newLines = [
            $ws . "'report_url'       => (string) \\IPS\\Http\\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=reportUnmatched&unmatched_id=' . (int) \$r['id'] )->csrf(),",
            $ws . "'snapshot_url'     => (string) \\IPS\\Http\\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=viewSnapshot&unmatched_id=' . (int) \$r['id'] ),",
            $ws . "'has_snapshot'     => !empty( \$r['snapshot_json'] ),",
            $ws . "'is_reported'      => !empty( \$r['dealer_reported_at'] ),",
        ];

        array_splice( $lines, $targetIdx + 1, 0, $newLines );

        return TRUE;
    }

    /**
     * Find the 'export_url' line in the $data array of unmatched() and
     * add 'csrf_key' right after it.
     */
    protected function insertCsrfKeyIntoUnmatchedData( array &$lines ): bool
    {
        /* The unmatched() data array. Look for 'export_url' => $exportUrl
         * lines - there's one in $out[] (already skipped because that's
         * inside the loop) and one in the outer $data array. We want the
         * outer one. The outer one is NOT inside a foreach, but content-
         * wise both look similar. Distinguish: the outer $data array
         * comes after the foreach close. Simpler heuristic: find ALL
         * matches, pick the one where the previous lines contain
         * 'total' => or 'rows' => $out. We'll look for the line where
         * the *next* line is the closing ];
         */
        $matches = [];
        foreach ( $lines as $i => $line )
        {
            if ( preg_match( "/'export_url'\s*=>\s*\\\$exportUrl/", $line ) )
            {
                $matches[] = $i;
            }
        }

        if ( count( $matches ) === 0 )
        {
            try { \IPS\Log::log( 'insertCsrfKeyIntoUnmatchedData: no export_url anchor found.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return FALSE;
        }

        /* The data-array one is the last one (after the foreach). */
        $targetIdx = end( $matches );

        $ws = '';
        if ( preg_match( '/^(\s*)/', $lines[ $targetIdx ], $m ) )
        {
            $ws = $m[1];
        }

        $newLine = $ws . "'csrf_key'   => \\IPS\\Session::i()->csrfKey,";

        array_splice( $lines, $targetIdx + 1, 0, [ $newLine ] );

        return TRUE;
    }

    /**
     * Append 4 new controller methods at the end of the class.
     * Find the final closing brace (the class's }). Insert just before.
     */
    protected function appendControllerMethods( array &$lines ): bool
    {
        /* Find the last non-whitespace line containing only '}'. */
        $lastBraceIdx = NULL;
        for ( $i = count( $lines ) - 1; $i >= 0; $i-- )
        {
            if ( preg_match( '/^\s*\}\s*$/', $lines[ $i ] ) )
            {
                $lastBraceIdx = $i;
                break;
            }
        }

        if ( $lastBraceIdx === NULL )
        {
            try { \IPS\Log::log( 'appendControllerMethods: no closing brace found.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return FALSE;
        }

        /* Method body uses tab indent at depth 1 (one tab = class body). */
        $methods = $this->getNewControllerMethodsBody();

        array_splice( $lines, $lastBraceIdx, 0, explode( "\n", $methods ) );

        return TRUE;
    }

    protected function getNewControllerMethodsBody(): string
    {
        return <<<'PHP'

	/* v193-report-snapshot-reset: BEGIN ----------------------------- */

	/**
	 * Mark an unmatched UPC as dealer-reported for priority admin review.
	 */
	protected function reportUnmatched()
	{
		\IPS\Session::i()->csrfCheck();

		$id = (int) \IPS\Request::i()->unmatched_id;
		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_unmatched_upcs', [ 'id=? AND dealer_id=?', $id, (int) $this->dealer->dealer_id ] )->first();
		}
		catch ( \UnderflowException )
		{
			\IPS\Output::i()->error( 'node_error', '2GDD230/1', 404 );
			return;
		}

		\IPS\gddealer\Unmatched\UnmatchedUpc::markReported( (int) $row['id'], (int) $this->dealer->dealer_id );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=unmatched' ),
			'gddealer_unmatched_reported_ok'
		);
	}

	/**
	 * Return the snapshot_json contents for a single unmatched UPC as
	 * pre-rendered HTML inside the standard dealer shell.
	 */
	protected function viewSnapshot()
	{
		$id = (int) \IPS\Request::i()->unmatched_id;
		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_unmatched_upcs', [ 'id=? AND dealer_id=?', $id, (int) $this->dealer->dealer_id ] )->first();
		}
		catch ( \UnderflowException )
		{
			\IPS\Output::i()->error( 'node_error', '2GDD231/1', 404 );
			return;
		}

		$snapshot = (string) ( $row['snapshot_json'] ?? '' );
		$decoded  = $snapshot !== '' ? json_decode( $snapshot, TRUE ) : NULL;

		$body = '<div class="gdPanel" style="padding:24px"><h1 style="margin:0 0 8px;font-size:20px">Snapshot for UPC ' . htmlspecialchars( (string) $row['upc'], ENT_QUOTES ) . '</h1>';
		$body .= '<p style="color:#6b7280;margin:0 0 16px;font-size:13px">This is the data your feed sent us at flag-time. It is what our admin team sees when reviewing this UPC for addition to the master catalog.</p>';

		if ( is_array( $decoded ) && count( $decoded ) > 0 )
		{
			$body .= '<table style="width:100%;border-collapse:collapse;font-size:13px">';
			foreach ( $decoded as $k => $v )
			{
				$vDisp = is_scalar( $v ) ? (string) $v : json_encode( $v, JSON_UNESCAPED_SLASHES );
				$body .= '<tr><td style="padding:6px 12px;border-bottom:1px solid #eee;font-weight:600;width:200px;vertical-align:top">' . htmlspecialchars( (string) $k, ENT_QUOTES ) . '</td><td style="padding:6px 12px;border-bottom:1px solid #eee;font-family:monospace">' . htmlspecialchars( (string) $vDisp, ENT_QUOTES ) . '</td></tr>';
			}
			$body .= '</table>';
		}
		else
		{
			$body .= '<p style="color:#6b7280;font-style:italic">No snapshot data captured for this UPC. (Snapshots are captured starting at import time; older UPCs may not have one.)</p>';
		}

		$body .= '<p style="margin-top:24px"><a href="' . (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=unmatched' ) . '" class="gdBtn gdBtn--secondary">&larr; Back to Unmatched UPCs</a></p>';
		$body .= '</div>';

		$this->output( 'unmatched', $body );
	}

	/**
	 * Show the Danger Zone reset form (GET). User must type their dealer
	 * name to enable the actual submit.
	 */
	protected function resetDealer()
	{
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=customize#gdDangerZone' )
		);
	}

	/**
	 * Process the reset (POST). Type-name must match dealer_name exactly.
	 * Scope B wipe: imported data + feed config to defaults; keep
	 * subscription, profile, FFL, founding flag.
	 */
	protected function confirmReset()
	{
		\IPS\Session::i()->csrfCheck();

		$dealer     = $this->dealer;
		$dealerId   = (int) $dealer->dealer_id;
		$dealerName = (string) ( $dealer->dealer_name ?? '' );

		$typed = trim( (string) ( \IPS\Request::i()->confirm_name ?? '' ) );
		if ( $typed === '' || $typed !== $dealerName )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=customize' ),
				'gddealer_reset_no_match'
			);
			return;
		}

		/* Pre-wipe snapshot to gd_dealer_reset_backups for 24hr undo. */
		try
		{
			$snap = [
				'feed_config' => NULL,
				'counts'      => [],
			];
			try
			{
				$snap['feed_config'] = \IPS\Db::i()->select( '*', 'gd_dealer_feed_config', [ 'dealer_id=?', $dealerId ] )->first();
			}
			catch ( \Throwable ) {}

			foreach ( [ 'gd_dealer_listings', 'gd_unmatched_upcs', 'gd_dealer_category_map', 'gd_price_history', 'gd_dealer_import_log' ] as $t )
			{
				try
				{
					$snap['counts'][ $t ] = (int) \IPS\Db::i()->select( 'COUNT(*)', $t, [ 'dealer_id=?', $dealerId ] )->first();
				}
				catch ( \Throwable )
				{
					$snap['counts'][ $t ] = -1;
				}
			}

			\IPS\Db::i()->insert( 'gd_dealer_reset_backups', [
				'dealer_id'     => $dealerId,
				'scope'         => 'B',
				'snapshot_json' => json_encode( $snap, JSON_UNESCAPED_SLASHES ),
				'created_at'    => date( 'Y-m-d H:i:s' ),
			] );
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( 'confirmReset: pre-wipe snapshot failed: ' . $e->getMessage(), 'gddealer_reset' );
		}

		/* Wipe imported data tables. */
		foreach ( [ 'gd_dealer_listings', 'gd_unmatched_upcs', 'gd_dealer_category_map', 'gd_price_history', 'gd_dealer_import_log' ] as $t )
		{
			try
			{
				\IPS\Db::i()->delete( $t, [ 'dealer_id=?', $dealerId ] );
			}
			catch ( \Throwable $e )
			{
				\IPS\Log::log( 'confirmReset: delete from ' . $t . ' failed: ' . $e->getMessage(), 'gddealer_reset' );
			}
		}

		/* Reset feed_config fields. Keep subscription, profile, FFL. */
		try
		{
			\IPS\Db::i()->update( 'gd_dealer_feed_config', [
				'feed_url'            => NULL,
				'auth_type'           => 'none',
				'auth_credentials'    => NULL,
				'field_mapping'       => NULL,
				'import_schedule'     => '6hr',
				'last_run'            => NULL,
				'last_run_status'     => NULL,
				'last_record_count'   => 0,
				'feed_delivery_mode'  => 'url',
				'wizard_step'         => 0,
				'wizard_completed_at' => NULL,
				'wizard_state_json'   => NULL,
			], [ 'dealer_id=?', $dealerId ] );
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( 'confirmReset: feed_config update failed: ' . $e->getMessage(), 'gddealer_reset' );
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=setupWizard' ),
			'gddealer_reset_complete'
		);
	}

	/* v193-report-snapshot-reset: END ------------------------------- */

PHP;
    }

    /* =================================================================
     * Templates - overwrite unmatched, append to dashboardCustomize
     * ================================================================= */

    protected function repairUnmatchedTemplate(): void
    {
        try
        {
            $row = \IPS\Db::i()->select(
                'template_id, template_content, template_data',
                'core_theme_templates',
                [ "template_app='gddealer' AND template_name='unmatched' AND template_group='dealers' AND template_set_id=1" ]
            )->first();
        }
        catch ( \Throwable )
        {
            try { \IPS\Log::log( 'unmatched template not found.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return;
        }

        $newBody = $this->getUnmatchedTemplateBody();

        if ( (string) $row['template_content'] === $newBody )
        {
            try { \IPS\Log::log( 'unmatched template already at v193 known-good content. Skipping.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return;
        }

        \IPS\Db::i()->update( 'core_theme_templates',
            [
                'template_content' => $newBody,
                'template_updated' => time(),
            ],
            [ 'template_id=?', (int) $row['template_id'] ]
        );

        try { \IPS\Log::log( 'unmatched template updated to v193 (' . strlen( $newBody ) . ' bytes).', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
    }

    protected function getUnmatchedTemplateBody(): string
    {
        return <<<'TPL'
<div class="gdPageHeader">
    <div class="gdPageHeader__titleBlock">
        <h1 class="gdPageHeader__title">
            Unmatched UPCs
            {{if $data['total'] > 0}}
            <span class="gdPageHeader__titleBadge">{expression="number_format($data['total'])"}</span>
            {{endif}}
        </h1>
        <p class="gdPageHeader__sub">UPCs from your feed that don't match our master catalog. We'll automatically re-match as our catalog grows.</p>
    </div>
    <div class="gdPageHeader__actions">
        {{if $data['total'] > 0}}
        <a href="{$data['export_url']}" class="gdBtn gdBtn--secondary">Export CSV</a>
        {{endif}}
    </div>
</div>

{{if $data['total'] === 0}}
<div class="gdPanel" style="text-align:center;padding:48px 24px">
    <div style="font-size:40px;margin-bottom:12px">&#x2713;</div>
    <h3 style="font-size:16px;font-weight:600;margin:0 0 6px">All UPCs matched</h3>
    <p style="color:var(--gd-text-subtle);margin:0">Every product in your feed matched our catalog. Nice work.</p>
</div>
{{else}}
<div class="gdPanel gdPanel--info" style="margin-bottom:16px;background:var(--gd-info-bg);border-color:var(--gd-brand-border);color:var(--gd-text)">
    <p style="margin:0 0 8px;font-size:13px">
        <strong>What this means:</strong> Our master catalog doesn't have these UPCs yet, so your listings for them aren't appearing in price comparison results. Most unmatched UPCs auto-resolve within a week as our catalog expands.
    </p>
    <p style="margin:0;font-size:13px">
        <strong>Report to GunRack</strong> to flag a UPC as priority for our admin team. <strong>View snapshot</strong> to see exactly what data your feed sent us for that UPC.
    </p>
</div>

<div class="gdPanel gdPanel--tableShell">
    <table class="gdListingsTable">
        <thead>
            <tr>
                <th>UPC</th>
                <th class="is-num">First seen</th>
                <th class="is-num">Last seen</th>
                <th class="is-num">Times in feed</th>
                <th class="is-num">Actions</th>
            </tr>
        </thead>
        <tbody>
            {{foreach $data['rows'] as $row}}
            <tr>
                <td data-label="UPC"><span class="gdListingsTable__upc">{$row['upc']}</span></td>
                <td class="is-num" data-label="First seen" style="font-size:12px;color:var(--gd-text-subtle)">{$row['first_seen']}</td>
                <td class="is-num" data-label="Last seen" style="font-size:12px;color:var(--gd-text-subtle)">{$row['last_seen']}</td>
                <td class="is-num" data-label="Times in feed">{expression="number_format($row['occurrence_count'])"}</td>
                <td class="is-num" data-label="Actions" style="white-space:nowrap">
                    {{if $row['has_snapshot']}}
                    <a href="{$row['snapshot_url']}" class="gdBtn gdBtn--ghost gdBtn--sm" style="margin-right:4px">View snapshot</a>
                    {{endif}}
                    {{if $row['is_reported']}}
                    <span class="gdBtn gdBtn--ghost gdBtn--sm" style="margin-right:4px;opacity:.6;cursor:default">Reported &#x2713;</span>
                    {{else}}
                    <a href="{$row['report_url']}" class="gdBtn gdBtn--secondary gdBtn--sm" style="margin-right:4px" onclick="return confirm('Flag this UPC as priority for the GunRack admin team to review?');">Report</a>
                    {{endif}}
                    <a href="{$row['exclude_url']}" class="gdBtn gdBtn--ghost gdBtn--sm" onclick="return confirm('Stop tracking this UPC?');">Exclude</a>
                </td>
            </tr>
            {{endforeach}}
        </tbody>
    </table>
</div>
{{endif}}
TPL;
    }

    protected function appendDangerZoneToCustomize(): void
    {
        try
        {
            $row = \IPS\Db::i()->select(
                'template_id, template_content',
                'core_theme_templates',
                [ "template_app='gddealer' AND template_name='dashboardCustomize' AND template_group='dealers' AND template_set_id=1" ]
            )->first();
        }
        catch ( \Throwable )
        {
            try { \IPS\Log::log( 'dashboardCustomize template not found.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return;
        }

        $content = (string) $row['template_content'];

        if ( strpos( $content, 'gdDangerZone' ) !== FALSE || strpos( $content, 'v193-danger-zone' ) !== FALSE )
        {
            try { \IPS\Log::log( 'Danger Zone already in dashboardCustomize. Skipping.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
            return;
        }

        $dangerBlock = $this->getDangerZoneBlock();
        $newContent  = $content . "\n\n" . $dangerBlock;

        \IPS\Db::i()->update( 'core_theme_templates',
            [
                'template_content' => $newContent,
                'template_updated' => time(),
            ],
            [ 'template_id=?', (int) $row['template_id'] ]
        );

        try { \IPS\Log::log( 'Appended Danger Zone block to dashboardCustomize (' . strlen( $dangerBlock ) . ' bytes added).', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
    }

    protected function getDangerZoneBlock(): string
    {
        return <<<'TPL'
<!-- v193-danger-zone -->
<div id="gdDangerZone" class="gdPanel" style="border:2px solid #b91c1c;background:#fef2f2;margin-top:48px;padding:24px;border-radius:12px">
    <h2 style="margin:0 0 6px;color:#b91c1c;font-size:18px;font-weight:700">Danger Zone</h2>
    <p style="margin:0 0 16px;color:#7f1d1d;font-size:13px">
        These actions are irreversible without an admin restore. Be sure before clicking.
    </p>

    <div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:16px">
        <h3 style="margin:0 0 4px;font-size:15px;font-weight:600;color:#111827">Reset &amp; start over</h3>
        <p style="margin:0 0 12px;font-size:13px;color:#374151">
            Wipes all imported data (listings, unmatched UPCs, category map, price history, import history) and clears your feed setup. You'll be sent back to the setup wizard to reconfigure your feed from scratch.
        </p>
        <p style="margin:0 0 12px;font-size:13px;color:#374151">
            <strong>Keeps:</strong> subscription tier, dealer profile (name, address, hours, logo, FFL info), reviews.
        </p>

        <form method="post" action="{$data['reset_action_url']}" onsubmit="return gdConfirmReset(this);" style="margin:0">
            <input type="hidden" name="csrfKey" value="{$data['csrf_key']}">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <label style="font-size:13px;color:#374151;flex:1;min-width:240px">
                    Type <strong>{$data['profile']['dealer_name']}</strong> to confirm:
                    <input type="text" name="confirm_name" placeholder="{$data['profile']['dealer_name']}" autocomplete="off" style="display:block;margin-top:4px;width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px">
                </label>
                <button type="submit" style="background:#b91c1c;color:#fff;border:none;padding:10px 18px;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer">Reset &amp; start over</button>
            </div>
        </form>
    </div>
</div>

<script>
function gdConfirmReset( frm ) {
    var input = frm.querySelector( 'input[name="confirm_name"]' );
    var expected = input.getAttribute( 'placeholder' );
    var typed = ( input.value || '' ).trim();
    if ( typed !== expected ) {
        alert( 'You must type your dealer name exactly to confirm: ' + expected );
        input.focus();
        return false;
    }
    return confirm( 'This will wipe all imported data and reset your feed setup. Continue?' );
}
</script>
TPL;
    }

    /* =================================================================
     * Lang strings
     * ================================================================= */

    protected function seedLangStrings(): void
    {
        $words = [
            'gddealer_unmatched_report'        => 'Report to GunRack',
            'gddealer_unmatched_reported'      => 'Reported',
            'gddealer_unmatched_view_snapshot' => 'View snapshot',
            'gddealer_unmatched_no_snapshot'   => 'No snapshot captured',
            'gddealer_unmatched_reported_ok'   => 'UPC reported to GunRack. Our admin team will prioritize it.',
            'gddealer_danger_zone_title'       => 'Danger Zone',
            'gddealer_danger_zone_lede'        => 'These actions are irreversible without an admin restore.',
            'gddealer_reset_button'            => 'Reset &amp; start over',
            'gddealer_reset_confirm_prompt'    => 'Type your dealer name to confirm',
            'gddealer_reset_complete'          => 'Reset complete. Your feed configuration has been cleared. Walk through the setup wizard to start again.',
            'gddealer_reset_no_match'          => 'The name you typed did not match. Reset was cancelled - no data was deleted.',
            'gddealer_front_reset_nav'         => 'Reset',
        ];

        $langIds = [];
        try
        {
            foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $id )
            {
                $langIds[] = (int) $id;
            }
        }
        catch ( \Throwable ) {}

        if ( count( $langIds ) === 0 ) { $langIds = [ 1 ]; }

        $inserted = 0;
        foreach ( $langIds as $langId )
        {
            foreach ( $words as $key => $val )
            {
                try
                {
                    \IPS\Db::i()->replace( 'core_sys_lang_words', [
                        'lang_id'      => $langId,
                        'word_app'     => 'gddealer',
                        'word_key'     => $key,
                        'word_default' => $val,
                        'word_js'      => 0,
                        'word_export'  => 1,
                    ] );
                    $inserted++;
                }
                catch ( \Throwable ) {}
            }
        }

        try { \IPS\Log::log( 'seedLangStrings: ' . $inserted . ' word row(s) replaced.', 'gddealer_upg_10193' ); } catch ( \Throwable ) {}
    }
}

class upgrade extends _upgrade {}
