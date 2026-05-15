<?php
/**
 * gddealer v1.0.190 - Categories sidebar nav (persistent).
 *
 * BACKGROUND
 * ----------
 * v189 shipped the Categories dashboard tab (controller methods +
 * template + language strings). The nav LINK to that tab was added by
 * hand via sed to DealerShellTrait::sidebarNav() because the v189
 * upgrade.php didn't touch DealerShellTrait. That manual edit lives only
 * on the production server; future rebuilds from git lose it.
 *
 * v190 ships that edit as a proper idempotent runtime patch so every
 * install of this version onward has the Categories sidebar entry
 * without manual intervention.
 *
 * What this upgrade does:
 *   1. Add `$unreviewedCats` query block to sidebarNav() (mirrors the
 *      existing $unmatched COUNT query).
 *   2. Insert the Categories nav item into the 'catalog' group items
 *      array, immediately after the 'unmatched' item.
 *
 * Patches use runtime str_replace anchored on the existing unmatched
 * code. Marker comment: v190-categories-nav. Idempotent.
 *
 * If someone reverts to a clean git copy of DealerShellTrait.php (no
 * manual sed patch), v190 reapplies cleanly. If v189's manual sed is
 * already in place AND was added with the v189-categories-nav marker
 * comment, the v190 marker check short-circuits and skips.
 */

namespace IPS\gddealer\setup\upg_10190;

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
            if ( $appVersion < 10189 )
            {
                try { \IPS\Log::log( 'v190 ran with app_long_version=' . $appVersion . ' (expected >=10189).', 'gddealer_upg_10190' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable ) {}

        $this->patchSidebarNav();

        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

        try { \IPS\Log::log( 'v190 upgrade complete.', 'gddealer_upg_10190' ); } catch ( \Throwable ) {}

        return TRUE;
    }

    /**
     * Inject the Categories nav item into DealerShellTrait::sidebarNav().
     *
     * Two insertions, both anchored on stable unique strings from the
     * existing file. Idempotent via marker comment check.
     */
    protected function patchSidebarNav(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/sources/Traits/DealerShellTrait.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        /* Idempotent: either v189's manual marker OR v190's marker counts as installed. */
        if ( strpos( $contents, 'v190-categories-nav' ) !== false
            || strpos( $contents, 'v189-categories-nav' ) !== false )
        {
            try { \IPS\Log::log( 'DealerShellTrait.php already has categories-nav marker. Skipping.', 'gddealer_upg_10190' ); } catch ( \Throwable ) {}
            return;
        }

        $changes = 0;

        /* --- Patch A: add unreviewedCats count query right after the
         *              unmatched count block. Anchor: the last line of
         *              the unmatched try/catch (the catch with empty body)
         *              followed by the wizard-complete comment. */
        $anchorA = "                catch ( \\Exception ) {}\n\n                /* Setup wizard completion check";
        $replaceA =
            "                catch ( \\Exception ) {}\n\n" .
            "                /* v190-categories-nav: count auto-classified categories awaiting review. */\n" .
            "                \$unreviewedCats = 0;\n" .
            "                try\n" .
            "                {\n" .
            "                        \$unreviewedCats = \\IPS\\gddealer\\Feed\\CategoryMap::unreviewedCount( (int) \$this->dealer->dealer_id );\n" .
            "                }\n" .
            "                catch ( \\Throwable ) {}\n\n" .
            "                /* Setup wizard completion check";

        $countA = substr_count( $contents, $anchorA );
        if ( $countA === 1 )
        {
            $contents = str_replace( $anchorA, $replaceA, $contents );
            $changes++;
        }
        else
        {
            try { \IPS\Log::log( 'Patch A: anchor count=' . $countA . ' (expected 1). Skipped.', 'gddealer_upg_10190' ); } catch ( \Throwable ) {}
        }

        /* --- Patch B: insert Categories nav item right after the
         *              unmatched item. Anchor: the unmatched item's
         *              full closing line. The file uses TAB indentation
         *              in some places and SPACES in others, but the
         *              unmatched 3-line item uses spaces. */
        $anchorB = "                                          'url' => \$urls['unmatched'], 'icon' => 'unmatched',\n" .
                   "                                          'badge' => \$unmatched > 0 ? [ 'count' => \$unmatched, 'variant' => 'urgent' ] : null ],";
        $replaceB =
            "                                          'url' => \$urls['unmatched'], 'icon' => 'unmatched',\n" .
            "                                          'badge' => \$unmatched > 0 ? [ 'count' => \$unmatched, 'variant' => 'urgent' ] : null ],\n" .
            "                                        /* v190-categories-nav */\n" .
            "                                        [ 'key' => 'categories', 'label' => 'Categories',\n" .
            "                                          'url' => \$urls['categories'], 'icon' => 'listings',\n" .
            "                                          'badge' => \$unreviewedCats > 0 ? [ 'count' => \$unreviewedCats, 'variant' => 'warn' ] : null ],";

        $countB = substr_count( $contents, $anchorB );
        if ( $countB === 1 )
        {
            $contents = str_replace( $anchorB, $replaceB, $contents );
            $changes++;
        }
        else
        {
            try { \IPS\Log::log( 'Patch B: anchor count=' . $countB . ' (expected 1). Skipped.', 'gddealer_upg_10190' ); } catch ( \Throwable ) {}
        }

        if ( $changes === 0 )
        {
            try { \IPS\Log::log( 'patchSidebarNav: no anchors matched. File not modified.', 'gddealer_upg_10190' ); } catch ( \Throwable ) {}
            return;
        }

        if ( $changes !== 2 )
        {
            try { \IPS\Log::log( 'patchSidebarNav: PARTIAL patch (' . $changes . '/2 anchors matched). Investigate.', 'gddealer_upg_10190' ); } catch ( \Throwable ) {}
        }

        file_put_contents( $path, $contents );

        try { \IPS\Log::log( 'patchSidebarNav: applied ' . $changes . ' patch(es) to DealerShellTrait.php.', 'gddealer_upg_10190' ); } catch ( \Throwable ) {}
    }
}

class upgrade extends _upgrade {}
