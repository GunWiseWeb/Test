<?php
/**
 * gddealer v1.0.195 - Restore everything tarball installs have been wiping.
 *
 * CONFIRMED REGRESSIONS to fix:
 *
 *   1. Categories sidebar nav item in DealerShellTrait.php (was added in v192,
 *      wiped by every tarball install since)
 *
 *   2. Four controller methods in modules/front/dealers/dashboard.php that
 *      v193 was supposed to add but never made it into the git tarball:
 *        - reportUnmatched()
 *        - viewSnapshot()
 *        - resetDealer()
 *        - confirmReset()
 *
 *   3. Report + View Snapshot columns in 'unmatched' template
 *      (template_app='gddealer', template_name='unmatched', set_id=1)
 *
 * STRATEGY: This upgrade.php is the SOLE source of truth for these patches.
 * Every patch is idempotent so it can run on every install going forward
 * without harm. Future versions should keep these restore functions in their
 * upgrade.php too until the tarball source actually carries the changes.
 *
 * All file patches use the v192 whitespace-agnostic line-splice pattern:
 *   - explode file into lines
 *   - find target by content-regex (ignores leading whitespace)
 *   - capture the matched line's leading whitespace
 *   - array_splice insert with that same whitespace
 *   - skip entirely if marker text already present in file
 */

namespace IPS\gddealer\setup\upg_10195;

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
            if ( $appVersion < 10194 )
            {
                try { \IPS\Log::log( 'v195 ran with app_long_version=' . $appVersion . ' (expected >=10194).', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable ) {}

        $this->ensureSchema();
        $this->restoreCategoriesSidebarNav();
        $this->restoreControllerMethods();
        $this->restoreUnmatchedTemplate();

        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

        try { \IPS\Log::log( 'v195 upgrade complete.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}

        return TRUE;
    }

    /**
     * Make sure v193 schema additions exist. Idempotent.
     */
    protected function ensureSchema(): void
    {
        // gd_unmatched_upcs.dealer_reported_at
        try
        {
            $hasCol = (bool) \IPS\Db::i()->query(
                "SHOW COLUMNS FROM `" . \IPS\Db::i()->prefix . "gd_unmatched_upcs` LIKE 'dealer_reported_at'"
            )->fetch_assoc();
        }
        catch ( \Throwable ) { $hasCol = TRUE; }

        if ( !$hasCol )
        {
            try
            {
                \IPS\Db::i()->query(
                    "ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_unmatched_upcs` ADD COLUMN `dealer_reported_at` DATETIME NULL"
                );
                try { \IPS\Log::log( 'ensureSchema: added dealer_reported_at column.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            }
            catch ( \Throwable $e )
            {
                try { \IPS\Log::log( 'ensureSchema: ADD dealer_reported_at failed: ' . $e->getMessage(), 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            }
        }

        // gd_dealer_reset_backups
        try
        {
            $hasTable = (bool) \IPS\Db::i()->query(
                "SHOW TABLES LIKE '" . \IPS\Db::i()->prefix . "gd_dealer_reset_backups'"
            )->fetch_assoc();
        }
        catch ( \Throwable ) { $hasTable = TRUE; }

        if ( !$hasTable )
        {
            try
            {
                \IPS\Db::i()->query(
                    "CREATE TABLE `" . \IPS\Db::i()->prefix . "gd_dealer_reset_backups` (
                        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `dealer_id` INT UNSIGNED NOT NULL,
                        `scope` VARCHAR(4) NOT NULL DEFAULT 'B',
                        `snapshot_json` LONGTEXT NULL,
                        `created_at` INT UNSIGNED NOT NULL,
                        PRIMARY KEY (`id`),
                        KEY `dealer_id` (`dealer_id`),
                        KEY `created_at` (`created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
                try { \IPS\Log::log( 'ensureSchema: created gd_dealer_reset_backups.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            }
            catch ( \Throwable $e )
            {
                try { \IPS\Log::log( 'ensureSchema: CREATE gd_dealer_reset_backups failed: ' . $e->getMessage(), 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            }
        }
    }

    /**
     * Restore Categories sidebar nav item + $unreviewedCats count in
     * DealerShellTrait.php. Same line-splice strategy as v192.
     *
     * Two insertions:
     *   1. $unreviewedCats = ... before the $nav array build
     *   2. ['key' => 'categories', ...] item in the $nav array
     *
     * Idempotent: skip if both markers present.
     */
    protected function restoreCategoriesSidebarNav(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/sources/Traits/DealerShellTrait.php';
        if ( !is_file( $path ) )
        {
            try { \IPS\Log::log( 'restoreCategoriesSidebarNav: file not found.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            return;
        }

        $contents = (string) file_get_contents( $path );

        $hasCount = ( strpos( $contents, '$unreviewedCats' ) !== FALSE );
        $hasNav   = ( strpos( $contents, "'key' => 'categories'" ) !== FALSE );

        if ( $hasCount && $hasNav )
        {
            try { \IPS\Log::log( 'restoreCategoriesSidebarNav: both markers present, skipping.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            return;
        }

        $lines = explode( "\n", $contents );

        // INSERTION 1: $unreviewedCats count.
        // Find the line "'unmatched'     => (string) \IPS\Http\Url::internal( $base . 'unmatched' ),"
        // (or similar) and insert the count query before the $nav build.
        // We use a more upstream anchor: the $urls = [...] block. Insert
        // before the line containing "$dealer = $this->currentDealer".
        //
        // Actually, safest anchor: the line right before "$urls = [" array.
        // Search for the literal "'overview'     => (string)" entry in $urls.
        if ( !$hasCount )
        {
            $countAnchorIdx = NULL;
            foreach ( $lines as $i => $line )
            {
                // Match "'overview' => (string) \IPS\Http\Url::internal(" with flexible whitespace
                if ( preg_match( "/'overview'\s*=>\s*\\(string\\)\s*\\\\IPS\\\\Http\\\\Url::internal/", $line ) )
                {
                    // Walk back to find "$urls = [" line
                    for ( $j = $i - 1; $j >= max( 0, $i - 10 ); $j-- )
                    {
                        if ( preg_match( '/\$urls\s*=\s*\[/', $lines[ $j ] ) )
                        {
                            $countAnchorIdx = $j;
                            break;
                        }
                    }
                    break;
                }
            }

            if ( $countAnchorIdx !== NULL )
            {
                // Capture whitespace from the anchor line
                $ws = '';
                if ( preg_match( '/^(\s*)/', $lines[ $countAnchorIdx ], $m ) )
                {
                    $ws = $m[1];
                }

                $newLines = [
                    $ws . '/* v195: Categories unreviewed count */',
                    $ws . '$unreviewedCats = 0;',
                    $ws . 'try {',
                    $ws . '    $unreviewedCats = (int) \\IPS\\Db::i()->select(',
                    $ws . '        \'COUNT(*)\', \'gd_dealer_category_map\',',
                    $ws . '        [ \'dealer_id=? AND ( reviewed_at IS NULL OR reviewed_at=0 )\', (int) $dealer->dealer_id ]',
                    $ws . '    )->first();',
                    $ws . '} catch ( \\Throwable ) {}',
                ];

                array_splice( $lines, $countAnchorIdx, 0, $newLines );
                try { \IPS\Log::log( 'restoreCategoriesSidebarNav: inserted $unreviewedCats count at line ' . $countAnchorIdx . '.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            }
            else
            {
                try { \IPS\Log::log( 'restoreCategoriesSidebarNav: count anchor not found.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            }
        }

        // INSERTION 2: 'categories' nav item.
        // Anchor on the 'unmatched' nav item line in the $nav array.
        if ( !$hasNav )
        {
            $navAnchorIdx = NULL;
            foreach ( $lines as $i => $line )
            {
                // Match the 'unmatched' nav item entry (NOT the $urls entry).
                // The nav item has "'key' => 'unmatched'" structure.
                if ( preg_match( "/'key'\s*=>\s*'unmatched'/", $line ) )
                {
                    // Find the closing ], for that nav array entry.
                    for ( $j = $i; $j < min( count( $lines ), $i + 10 ); $j++ )
                    {
                        if ( preg_match( '/^\s*\],\s*$/', $lines[ $j ] ) )
                        {
                            $navAnchorIdx = $j;
                            break;
                        }
                    }
                    break;
                }
            }

            if ( $navAnchorIdx !== NULL )
            {
                $ws = '';
                if ( preg_match( '/^(\s*)/', $lines[ $navAnchorIdx ], $m ) )
                {
                    $ws = $m[1];
                }

                $newLines = [
                    $ws . '/* v195: Categories nav item */',
                    $ws . '[ \'key\' => \'categories\', \'label\' => $lang->addToStack(\'gddealer_front_tab_categories\'),',
                    $ws . '  \'url\' => $urls[\'categories\'] ?? (string) \\IPS\\Http\\Url::internal( $base . \'categories\' ),',
                    $ws . '  \'icon\' => \'tag\', \'badge\' => $unreviewedCats > 0 ? $unreviewedCats : null ],',
                ];

                array_splice( $lines, $navAnchorIdx + 1, 0, $newLines );
                try { \IPS\Log::log( 'restoreCategoriesSidebarNav: inserted categories nav item at line ' . ( $navAnchorIdx + 1 ) . '.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            }
            else
            {
                try { \IPS\Log::log( 'restoreCategoriesSidebarNav: nav anchor not found.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            }
        }

        // INSERTION 3: 'categories' URL in $urls array.
        if ( strpos( implode( "\n", $lines ), "'categories'" ) === FALSE
             || strpos( implode( "\n", $lines ), "'categories'     =>" ) === FALSE )
        {
            // Look for the 'unmatched' URL entry in the $urls array.
            $urlAnchorIdx = NULL;
            foreach ( $lines as $i => $line )
            {
                if ( preg_match( "/'unmatched'\s*=>\s*\\(string\\)\s*\\\\IPS\\\\Http\\\\Url::internal\\(\s*\\\$base/", $line ) )
                {
                    $urlAnchorIdx = $i;
                    break;
                }
            }

            if ( $urlAnchorIdx !== NULL )
            {
                $ws = '';
                if ( preg_match( '/^(\s*)/', $lines[ $urlAnchorIdx ], $m ) )
                {
                    $ws = $m[1];
                }

                $newLine = $ws . "'categories'    => (string) \\IPS\\Http\\Url::internal( \$base . 'categories' ),";
                array_splice( $lines, $urlAnchorIdx + 1, 0, [ $newLine ] );
                try { \IPS\Log::log( 'restoreCategoriesSidebarNav: inserted categories URL at line ' . ( $urlAnchorIdx + 1 ) . '.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            }
        }

        file_put_contents( $path, implode( "\n", $lines ) );

        // Seed lang string for the Categories tab label.
        $this->ensureLangString( 'gddealer_front_tab_categories', 'Categories' );
    }

    /**
     * Add the four missing v193 controller methods to dashboard.php.
     *
     * Strategy: find the closing brace of the class (the last "^}" line),
     * splice the four methods in just before it.
     *
     * Idempotent via "v195-controller-methods" marker comment.
     */
    protected function restoreControllerMethods(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/modules/front/dealers/dashboard.php';
        if ( !is_file( $path ) )
        {
            try { \IPS\Log::log( 'restoreControllerMethods: file not found.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            return;
        }

        $contents = (string) file_get_contents( $path );

        if ( strpos( $contents, 'v195-controller-methods' ) !== FALSE )
        {
            try { \IPS\Log::log( 'restoreControllerMethods: marker present, skipping.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            return;
        }

        $lines = explode( "\n", $contents );

        // Find the LAST line that is just "}" (closing brace of the class).
        $classCloseIdx = NULL;
        for ( $i = count( $lines ) - 1; $i >= 0; $i-- )
        {
            $t = trim( $lines[ $i ] );
            if ( $t === '}' )
            {
                $classCloseIdx = $i;
                break;
            }
        }

        if ( $classCloseIdx === NULL )
        {
            try { \IPS\Log::log( 'restoreControllerMethods: closing brace not found.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            return;
        }

        $methods = $this->getControllerMethods();
        $methodLines = explode( "\n", $methods );

        array_splice( $lines, $classCloseIdx, 0, $methodLines );
        file_put_contents( $path, implode( "\n", $lines ) );

        try { \IPS\Log::log( 'restoreControllerMethods: injected ' . count( $methodLines ) . ' lines of methods before line ' . $classCloseIdx . '.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
    }

    /**
     * The four controller methods, as a single string block.
     * Tab-indented to match the rest of dashboard.php.
     */
    protected function getControllerMethods(): string
    {
        return <<<'PHP'

	/* v195-controller-methods - injected by v195 upgrade */

	/**
	 * AJAX/POST: dealer reports an unmatched UPC to GunRack.
	 */
	protected function reportUnmatched(): void
	{
		\IPS\Session::i()->csrfCheck();
		$dealer = $this->currentDealer();
		$upcId  = (int) \IPS\Request::i()->upc_id;

		if ( !$dealer or !$upcId )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=unmatched' ),
				'Invalid request.', 400
			);
			return;
		}

		try
		{
			\IPS\Db::i()->update(
				'gd_unmatched_upcs',
				[ 'dealer_reported_at' => date( 'Y-m-d H:i:s' ) ],
				[ 'id=? AND dealer_id=?', $upcId, (int) $dealer->dealer_id ]
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'reportUnmatched error: ' . $e->getMessage(), 'gddealer_v195' ); } catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=unmatched' ),
			'UPC reported to GunRack. Thank you!'
		);
	}

	/**
	 * View the snapshot_json for an unmatched UPC in a shell-wrapped panel.
	 */
	protected function viewSnapshot(): void
	{
		$dealer = $this->currentDealer();
		$upcId  = (int) \IPS\Request::i()->upc_id;

		$row = NULL;
		try
		{
			$row = \IPS\Db::i()->select(
				'*', 'gd_unmatched_upcs',
				[ 'id=? AND dealer_id=?', $upcId, (int) $dealer->dealer_id ]
			)->first();
		}
		catch ( \Throwable ) {}

		if ( !$row )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=unmatched' ),
				'Snapshot not found.', 404
			);
			return;
		}

		$snapshot = NULL;
		if ( !empty( $row['snapshot_json'] ) )
		{
			$snapshot = @json_decode( $row['snapshot_json'], true );
		}

		$html  = '<div class="gdPanel" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px">';
		$html .= '<h2 style="margin:0 0 12px;font-size:18px;font-weight:600">Feed snapshot for UPC ' . htmlspecialchars( $row['upc'], ENT_QUOTES, 'UTF-8' ) . '</h2>';
		$html .= '<p style="font-size:13px;color:#64748b;margin:0 0 16px">First seen: ' . htmlspecialchars( $row['first_seen'] ?? '', ENT_QUOTES, 'UTF-8' ) . ' · Last seen: ' . htmlspecialchars( $row['last_seen'] ?? '', ENT_QUOTES, 'UTF-8' ) . ' · Occurrences: ' . (int) ( $row['occurrence_count'] ?? 0 ) . '</p>';
		if ( $snapshot )
		{
			$html .= '<pre style="background:#0f172a;color:#e2e8f0;padding:16px;border-radius:8px;overflow:auto;font-size:12px;font-family:ui-monospace,monospace;white-space:pre-wrap;word-break:break-word">' . htmlspecialchars( json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), ENT_QUOTES, 'UTF-8' ) . '</pre>';
		}
		else
		{
			$html .= '<p style="font-size:13px;color:#64748b">No snapshot available for this UPC.</p>';
		}
		$html .= '<div style="margin-top:16px"><a href="' . htmlspecialchars( (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=unmatched' ), ENT_QUOTES, 'UTF-8' ) . '" class="gdBtn gdBtn--secondary">&larr; Back to unmatched UPCs</a></div>';
		$html .= '</div>';

		$this->output( 'viewSnapshot', $html );
	}

	/**
	 * Internal worker: perform a Scope B wipe and snapshot backup.
	 * Returns true on success.
	 */
	protected function resetDealer(): bool
	{
		$dealer = $this->currentDealer();
		if ( !$dealer )
		{
			return FALSE;
		}

		$dealerId = (int) $dealer->dealer_id;

		// Snapshot Scope B tables before wipe.
		$snapshot = [
			'dealer_id'         => $dealerId,
			'created_at'        => date( 'c' ),
			'gd_dealer_listings'    => [],
			'gd_unmatched_upcs'     => [],
			'gd_dealer_category_map'=> [],
			'gd_dealer_import_log'  => [],
		];

		foreach ( [ 'gd_dealer_listings', 'gd_unmatched_upcs', 'gd_dealer_category_map', 'gd_dealer_import_log' ] as $tbl )
		{
			try
			{
				$rows = \IPS\Db::i()->select( '*', $tbl, [ 'dealer_id=?', $dealerId ] );
				foreach ( $rows as $r )
				{
					$snapshot[ $tbl ][] = $r;
				}
			}
			catch ( \Throwable ) {}
		}

		try
		{
			\IPS\Db::i()->insert( 'gd_dealer_reset_backups', [
				'dealer_id'     => $dealerId,
				'scope'         => 'B',
				'snapshot_json' => json_encode( $snapshot ),
				'created_at'    => time(),
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'resetDealer backup error: ' . $e->getMessage(), 'gddealer_v195' ); } catch ( \Throwable ) {}
			return FALSE;
		}

		// Wipe Scope B tables.
		foreach ( [ 'gd_dealer_listings', 'gd_unmatched_upcs', 'gd_dealer_category_map', 'gd_dealer_import_log' ] as $tbl )
		{
			try { \IPS\Db::i()->delete( $tbl, [ 'dealer_id=?', $dealerId ] ); }
			catch ( \Throwable ) {}
		}

		// Clear feed config wizard state + feed_url so dealer goes back to wizard.
		try
		{
			\IPS\Db::i()->update( 'gd_dealer_feed_config',
				[
					'feed_url'          => '',
					'auth'              => '',
					'wizard_state_json' => '',
				],
				[ 'dealer_id=?', $dealerId ]
			);
		}
		catch ( \Throwable ) {}

		return TRUE;
	}

	/**
	 * POST handler from the Danger Zone form on Customize.
	 */
	protected function confirmReset(): void
	{
		\IPS\Session::i()->csrfCheck();

		$dealer = $this->currentDealer();
		$typed  = trim( (string) \IPS\Request::i()->confirm_name );
		$expected = $dealer ? (string) $dealer->dealer_name : '';

		if ( !$dealer or $typed === '' or $typed !== $expected )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=customize' ),
				'Reset cancelled: confirmation name did not match.', 400
			);
			return;
		}

		$ok = $this->resetDealer();

		if ( $ok )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::external( (string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=overview' ) ),
				'Your dealer data has been reset. Run through the setup wizard to reconfigure your feed. (24-hour undo available - contact support.)'
			);
		}
		else
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=customize' ),
				'Reset failed. Contact GunRack support.', 500
			);
		}
	}

	/* end v195-controller-methods */

PHP;
    }

    /**
     * Restore the 'unmatched' template's Report + View Snapshot columns
     * by overwriting template_content entirely. Idempotent via marker
     * "v195-unmatched-template" in template_content.
     */
    protected function restoreUnmatchedTemplate(): void
    {
        try
        {
            $row = \IPS\Db::i()->select(
                'template_id, template_content',
                'core_theme_templates',
                [ "template_app='gddealer' AND template_name='unmatched' AND template_group='dealers' AND template_set_id=1" ]
            )->first();
        }
        catch ( \Throwable )
        {
            try { \IPS\Log::log( 'restoreUnmatchedTemplate: template not found.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            return;
        }

        if ( strpos( $row['template_content'], 'v195-unmatched-template' ) !== FALSE )
        {
            try { \IPS\Log::log( 'restoreUnmatchedTemplate: marker present, skipping.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
            return;
        }

        $newContent = $this->getUnmatchedTemplateContent();

        \IPS\Db::i()->update( 'core_theme_templates',
            [
                'template_content' => $newContent,
                'template_updated' => time(),
            ],
            [ 'template_id=?', (int) $row['template_id'] ]
        );

        try { \IPS\Log::log( 'restoreUnmatchedTemplate: wrote ' . strlen( $newContent ) . ' bytes.', 'gddealer_upg_10195' ); } catch ( \Throwable ) {}
    }

    protected function getUnmatchedTemplateContent(): string
    {
        return <<<'TPL'
{{/* v195-unmatched-template */}}
<div class="gdPageHeader">
    <div class="gdPageHeader__titleBlock">
        <h1 class="gdPageHeader__title">Unmatched UPCs</h1>
        <p class="gdPageHeader__sub">Products in your feed that aren't in our catalog. Report them so we can add them.</p>
    </div>
</div>

{{if empty( $data['rows'] )}}
<div class="gdPanel gdEmptyState">
    <p style="color:#64748b">No unmatched UPCs right now &mdash; everything in your feed matched the catalog.</p>
</div>
{{else}}
<div class="gdPanel gdPanel--tableShell">
    <table class="gdListingsTable">
        <thead>
            <tr>
                <th>UPC</th>
                <th>First seen</th>
                <th>Last seen</th>
                <th class="is-num">Occurrences</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            {{foreach $data['rows'] as $row}}
            <tr>
                <td data-label="UPC" class="gdListingsTable__upc">{$row['upc']}</td>
                <td data-label="First seen">{$row['first_seen']}</td>
                <td data-label="Last seen">{$row['last_seen']}</td>
                <td data-label="Occurrences" class="is-num">{$row['occurrence_count']}</td>
                <td data-label="Status">
                    {{if !empty( $row['is_reported'] )}}
                    <span class="gdStatusPill gdStatusPill--active">Reported</span>
                    {{else}}
                    <span class="gdStatusPill gdStatusPill--muted">New</span>
                    {{endif}}
                </td>
                <td data-label="Actions">
                    {{if empty( $row['is_reported'] ) and isset( $row['report_url'] )}}
                    <a href="{$row['report_url']}" class="gdBtn gdBtn--primary gdBtn--sm" onclick="return confirm('Report this UPC to GunRack for catalog addition?');">Report to GunRack</a>
                    {{endif}}
                    {{if !empty( $row['has_snapshot'] ) and isset( $row['snapshot_url'] )}}
                    <a href="{$row['snapshot_url']}" class="gdBtn gdBtn--secondary gdBtn--sm" style="margin-left:6px">View Snapshot</a>
                    {{endif}}
                </td>
            </tr>
            {{endforeach}}
        </tbody>
    </table>
</div>
{{endif}}
TPL;
    }

    /**
     * Helper: seed a lang string if it doesn't exist.
     */
    protected function ensureLangString( string $key, string $default ): void
    {
        try
        {
            $exists = (bool) \IPS\Db::i()->select(
                'COUNT(*)', 'core_sys_lang_words',
                [ 'word_key=? AND word_app=?', $key, 'gddealer' ]
            )->first();
        }
        catch ( \Throwable ) { return; }

        if ( $exists ) { return; }

        try
        {
            $langs = \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' );
            foreach ( $langs as $langId )
            {
                try
                {
                    \IPS\Db::i()->insert( 'core_sys_lang_words', [
                        'lang_id'      => (int) $langId,
                        'word_app'     => 'gddealer',
                        'word_key'     => $key,
                        'word_default' => $default,
                        'word_js'      => 0,
                        'word_export'  => 1,
                    ] );
                }
                catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable ) {}
    }
}

class upgrade extends _upgrade {}
