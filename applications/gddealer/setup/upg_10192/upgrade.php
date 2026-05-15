<?php
/**
 * gddealer v1.0.192 - Whitespace-agnostic sidebarNav patching.
 *
 * BACKGROUND
 * ----------
 * v190 and v191 both attempted to patch DealerShellTrait::sidebarNav()
 * with literal-string str_replace anchors that included the file's
 * indentation. v190 used spaces, v191 used tabs - neither matched the
 * actual file (mixed: 3 tabs + 3 spaces + close-bracket on the items-
 * array lines). Both silently no-op'd. v191 stamped a "fully installed"
 * marker even though nothing was inserted, so a normal v192 idempotency
 * check would short-circuit and ship nothing.
 *
 * v192 changes strategy: instead of literal-string anchors with brittle
 * whitespace, it works LINE BY LINE on the file. For each insertion:
 *
 *   1. Find the target line by CONTENT MATCH (regex ignoring leading
 *      whitespace).
 *   2. Capture the leading whitespace from the matched line.
 *   3. Build the new line(s) with the SAME leading whitespace.
 *   4. Insert immediately after the matched line.
 *
 * This works regardless of tabs/spaces/mixed/aligned indentation.
 *
 * It also clears the stale v191-categories-nav marker that v191 wrote
 * incorrectly, since it lied about state. v192 uses its own marker
 * (v192-categories-nav) for idempotency, but recognizes both the v192
 * marker AND a true installed state (the Categories nav item being
 * present in the file) as "patch complete".
 *
 * Idempotent throughout.
 */

namespace IPS\gddealer\setup\upg_10192;

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
            if ( $appVersion < 10191 )
            {
                try { \IPS\Log::log( 'v192 ran with app_long_version=' . $appVersion . ' (expected >=10191).', 'gddealer_upg_10192' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable ) {}

        $this->patchSidebarNav();

        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

        try { \IPS\Log::log( 'v192 upgrade complete.', 'gddealer_upg_10192' ); } catch ( \Throwable ) {}

        return TRUE;
    }

    /**
     * Whitespace-agnostic, line-by-line patching of sidebarNav().
     *
     * Two insertions:
     *   A. $unreviewedCats count block, right after the unmatched-count
     *      try/catch block.
     *   B. Categories nav item, right after the unmatched nav item.
     *
     * Insertion logic:
     *   - Find the anchor line by regex on content (no whitespace match).
     *   - Capture its leading whitespace.
     *   - For Patch A, find the END of the try/catch block (the catch
     *     line at the same depth as the try).
     *   - For Patch B, find the close-bracket of the unmatched item.
     *   - Insert new lines AFTER, using captured whitespace.
     *
     * Idempotent: skip if the Categories nav item is already present
     * (whatever how it got there - hand-patched, prior run, etc.)
     */
    protected function patchSidebarNav(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/sources/Traits/DealerShellTrait.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        /* True-state idempotency: the Categories nav item being present
         * AND the unreviewedCats count being present means we're fully
         * patched, regardless of whatever marker comments exist. */
        $hasNavItem    = ( strpos( $contents, "'key' => 'categories'" ) !== FALSE );
        $hasCountQuery = ( strpos( $contents, '$unreviewedCats' ) !== FALSE );

        if ( $hasNavItem && $hasCountQuery )
        {
            try { \IPS\Log::log( 'sidebarNav already fully patched (nav item + count present). Cleaning stale markers and exiting.', 'gddealer_upg_10192' ); } catch ( \Throwable ) {}
            $this->cleanStaleMarkers( $path, $contents );
            return;
        }

        $lines = explode( "\n", $contents );

        $patchAApplied = $hasCountQuery;
        $patchBApplied = $hasNavItem;

        /* ----- Patch A: insert $unreviewedCats count block ----- */
        if ( !$patchAApplied )
        {
            $patchAApplied = $this->insertUnreviewedCatsCount( $lines );
        }

        /* ----- Patch B: insert Categories nav item ----- */
        if ( !$patchBApplied )
        {
            $patchBApplied = $this->insertCategoriesNavItem( $lines );
        }

        $newContents = implode( "\n", $lines );
        $newContents = $this->cleanStaleMarkersInString( $newContents );

        if ( $newContents === $contents )
        {
            try { \IPS\Log::log( 'patchSidebarNav: no changes made. patchA=' . var_export( $patchAApplied, TRUE ) . ' patchB=' . var_export( $patchBApplied, TRUE ), 'gddealer_upg_10192' ); } catch ( \Throwable ) {}
            return;
        }

        file_put_contents( $path, $newContents );

        try { \IPS\Log::log( 'patchSidebarNav: patchA=' . var_export( $patchAApplied, TRUE ) . ' patchB=' . var_export( $patchBApplied, TRUE ) . '. File written.', 'gddealer_upg_10192' ); } catch ( \Throwable ) {}
    }

    /**
     * Find the unmatched-count try/catch block and insert the
     * unreviewedCats block right after it.
     *
     * Strategy: find a line matching the catch that closes the
     * $unmatched try block. That's the catch immediately preceded
     * (within the same depth) by the unmatched select. We look for:
     *
     *   $unmatched = (int) \IPS\Db::i()->select(...)
     *   ...
     *   catch ( \Exception ) {}    <-- target line
     *
     * After this line, we insert a blank line + comment + the new
     * count block, all using the same leading whitespace.
     *
     * Returns TRUE if inserted (or already present), FALSE if anchor
     * not found.
     *
     * @param array<int, string> $lines (by reference)
     */
    protected function insertUnreviewedCatsCount( array &$lines ): bool
    {
        $unmatchedSelectIdx = NULL;
        foreach ( $lines as $i => $line )
        {
            if ( preg_match( '/\$unmatched\s*=\s*\(int\)\s*\\\\IPS\\\\Db::i\(\)->select/', $line ) )
            {
                $unmatchedSelectIdx = $i;
                break;
            }
        }

        if ( $unmatchedSelectIdx === NULL )
        {
            try { \IPS\Log::log( 'insertUnreviewedCatsCount: anchor line ($unmatched = (int) ...select) not found.', 'gddealer_upg_10192' ); } catch ( \Throwable ) {}
            return FALSE;
        }

        /* From there, scan forward for the closing catch. */
        $catchIdx = NULL;
        $total    = count( $lines );
        for ( $i = $unmatchedSelectIdx; $i < min( $unmatchedSelectIdx + 10, $total ); $i++ )
        {
            if ( preg_match( '/^\s*catch\s*\(\s*\\\\Exception\s*\)\s*\{\s*\}\s*$/', $lines[ $i ] ) )
            {
                $catchIdx = $i;
                break;
            }
        }

        if ( $catchIdx === NULL )
        {
            try { \IPS\Log::log( 'insertUnreviewedCatsCount: closing catch not found within 10 lines.', 'gddealer_upg_10192' ); } catch ( \Throwable ) {}
            return FALSE;
        }

        /* Capture leading whitespace from the catch line. */
        $ws = '';
        if ( preg_match( '/^(\s*)/', $lines[ $catchIdx ], $m ) )
        {
            $ws = $m[1];
        }

        $newLines = [
            '',
            $ws . '/* v192-categories-nav: count of auto-classified categories awaiting review. */',
            $ws . '$unreviewedCats = 0;',
            $ws . 'try',
            $ws . '{',
            $ws . "\t" . '$unreviewedCats = \IPS\gddealer\Feed\CategoryMap::unreviewedCount( (int) $this->dealer->dealer_id );',
            $ws . '}',
            $ws . 'catch ( \Throwable ) {}',
        ];

        array_splice( $lines, $catchIdx + 1, 0, $newLines );

        return TRUE;
    }

    /**
     * Find the unmatched nav-item closing line and insert the Categories
     * nav item right after it.
     *
     * Anchor: the line containing
     *   'badge' => $unmatched > 0 ? [ 'count' => $unmatched, 'variant' => 'urgent' ] : null ],
     *
     * @param array<int, string> $lines (by reference)
     */
    protected function insertCategoriesNavItem( array &$lines ): bool
    {
        $targetIdx = NULL;
        foreach ( $lines as $i => $line )
        {
            if ( preg_match( "/'badge'\s*=>\s*\\\$unmatched\s*>\s*0\s*\?/", $line ) )
            {
                $targetIdx = $i;
                break;
            }
        }

        if ( $targetIdx === NULL )
        {
            try { \IPS\Log::log( 'insertCategoriesNavItem: anchor line (unmatched badge) not found.', 'gddealer_upg_10192' ); } catch ( \Throwable ) {}
            return FALSE;
        }

        /* Capture leading whitespace from the badge line (alignment ws
         * for the array-item rows). */
        $ws = '';
        if ( preg_match( '/^(\s*)/', $lines[ $targetIdx ], $m ) )
        {
            $ws = $m[1];
        }

        /* The badge line is the 3rd line of the 'unmatched' item
         * (key/label, url/icon, badge). We want our new item to start
         * one indent OUTWARD from the badge line - which means stripping
         * the 2-space alignment from the badge line's whitespace to get
         * the key-line whitespace. The badge line is indented with the
         * same depth as the URL line, which is 2 spaces deeper than the
         * key line. */
        $wsKey = preg_replace( '/  $/', '', $ws );
        if ( $wsKey === $ws )
        {
            /* No 2-space alignment - probably tab-only. Use as-is. */
            $wsKey = $ws;
        }

        $newLines = [
            $wsKey . "/* v192-categories-nav */",
            $wsKey . "[ 'key' => 'categories', 'label' => 'Categories',",
            $ws    . "'url' => \$urls['categories'], 'icon' => 'listings',",
            $ws    . "'badge' => \$unreviewedCats > 0 ? [ 'count' => \$unreviewedCats, 'variant' => 'warn' ] : null ],",
        ];

        array_splice( $lines, $targetIdx + 1, 0, $newLines );

        return TRUE;
    }

    /**
     * Remove the false v191 marker comment (and any other stale marker
     * that v190's failed attempts wrote). Only operates on the working
     * string; caller writes the file.
     */
    protected function cleanStaleMarkersInString( string $contents ): string
    {
        /* The v191 marker was inserted right after the opening <?php
         * as: \n/* v191-categories-nav: marker (file was hand-patched in a prior version). *\/\n
         * Remove it. */
        $patterns = [
            '/\n\/\* v191-categories-nav: marker \(file was hand-patched in a prior version\)\. \*\/\n/',
            '/\n\/\* v190-categories-nav: marker .*?\*\/\n/',
            '/\n\/\* v189-categories-nav: marker .*?\*\/\n/',
        ];

        foreach ( $patterns as $p )
        {
            $contents = preg_replace( $p, "\n", $contents );
        }

        return $contents;
    }

    /**
     * Called when we detect the file is already correctly patched. Just
     * clean any stale markers and write back if anything changed.
     */
    protected function cleanStaleMarkers( string $path, string $contents ): void
    {
        $cleaned = $this->cleanStaleMarkersInString( $contents );
        if ( $cleaned !== $contents )
        {
            file_put_contents( $path, $cleaned );
            try { \IPS\Log::log( 'cleanStaleMarkers: stripped stale marker(s) from DealerShellTrait.php.', 'gddealer_upg_10192' ); } catch ( \Throwable ) {}
        }
    }
}

class upgrade extends _upgrade {}
