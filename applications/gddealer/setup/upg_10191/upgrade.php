<?php
/**
 * gddealer v1.0.191 - Repair dashboardCategories template + retry
 * sidebarNav patch with correct (tab-indented) anchors.
 *
 * BACKGROUND
 * ----------
 * v190 attempted to patch DealerShellTrait::sidebarNav() with anchors
 * that used SPACE indentation, but the live file uses TAB indentation.
 * Both anchors returned count=0 and the patch silently no-op'd. Result:
 * the Categories sidebar link only existed because of the earlier
 * manual sed (which used line-number insertion, not anchor matching),
 * and the v189-categories-nav marker was never written to the file,
 * so v190's idempotency check passed-through.
 *
 * Separately, an out-of-band SQL hot-patch on the dashboardCategories
 * template_content damaged the template's HTML - left dangling
 * </a></nav>{{endif}} fragments after a regex strip removed only the
 * opening of a {{if}}/{{endif}} block. The Categories tab now throws
 * UnexpectedValueException when rendered.
 *
 * v191 fixes both:
 *
 *   1. Repair the dashboardCategories template via full content
 *      OVERWRITE. We replace the entire template_content with a clean
 *      known-good body (the v189 body MINUS the redundant top tab nav,
 *      since the sidebar covers navigation). This converges to a
 *      working state regardless of how damaged the current content is.
 *      Idempotent because overwriting with the same content is a no-op.
 *
 *   2. Retry the sidebarNav patch with TAB-indented anchors that match
 *      the actual file. Add $unreviewedCats count query + Categories
 *      nav item. New marker: v191-categories-nav.
 *
 *      Three states this handles:
 *        a) Fresh server: no marker, no hand-patch. Both anchors match,
 *           both patches applied, marker added.
 *        b) Live prod server: manual sed added the nav item but never
 *           added a marker. We detect the existing 'key' => 'categories'
 *           line, skip duplicating it, but still try to add the
 *           unreviewedCats count and stamp the v191 marker so future
 *           runs short-circuit.
 *        c) Already-installed v191: marker found, skip everything.
 *
 * Idempotent throughout. Safe to run multiple times.
 */

namespace IPS\gddealer\setup\upg_10191;

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
            if ( $appVersion < 10190 )
            {
                try { \IPS\Log::log( 'v191 ran with app_long_version=' . $appVersion . ' (expected >=10190).', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable ) {}

        $this->repairCategoriesTemplate();
        $this->patchSidebarNav();

        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

        try { \IPS\Log::log( 'v191 upgrade complete.', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}

        return TRUE;
    }

    /**
     * Replace dashboardCategories template_content with the known-good
     * body. This is a CONTENT OVERWRITE, not a regex patch, so it
     * repairs damage from any prior hot-patch attempt and removes the
     * redundant top tab nav in the same UPDATE.
     */
    protected function repairCategoriesTemplate(): void
    {
        try
        {
            try
            {
                $row = \IPS\Db::i()->select(
                    'template_id, template_content',
                    'core_theme_templates',
                    [ "template_app='gddealer' AND template_name='dashboardCategories' AND template_set_id=1" ]
                )->first();
            }
            catch ( \UnderflowException )
            {
                try { \IPS\Log::log( 'dashboardCategories template not found, cannot repair.', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
                return;
            }

            $newBody = $this->getCleanTemplateBody();

            /* Skip if already at known-good content (byte-for-byte). */
            if ( (string) $row['template_content'] === $newBody )
            {
                try { \IPS\Log::log( 'dashboardCategories already at known-good content. Skipping.', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
                return;
            }

            \IPS\Db::i()->update( 'core_theme_templates',
                [
                    'template_content' => $newBody,
                    'template_updated' => time(),
                ],
                [ 'template_id=?', (int) $row['template_id'] ]
            );

            try { \IPS\Log::log( 'dashboardCategories template repaired (' . strlen( $newBody ) . ' bytes).', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( 'repairCategoriesTemplate failed: ' . $e->getMessage(), 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
        }
    }

    /**
     * Add $unreviewedCats count + Categories nav item to sidebarNav().
     * Uses TAB-indented anchors to match the live file. Idempotent.
     */
    protected function patchSidebarNav(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/sources/Traits/DealerShellTrait.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        /* v191 marker = fully installed. Skip. */
        if ( strpos( $contents, 'v191-categories-nav' ) !== FALSE )
        {
            try { \IPS\Log::log( 'DealerShellTrait.php already has v191-categories-nav marker. Skipping.', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
            return;
        }

        /* Detect "hand-patched" state: 'key' => 'categories' exists but
         * no v191 marker. We won't duplicate the nav item but will
         * still try to add unreviewedCats and stamp the marker. */
        $hasHandPatchedItem = ( strpos( $contents, "'key' => 'categories'" ) !== FALSE );

        $changes = 0;

        /* --- Patch A: add unreviewedCats count after the unmatched
         *              try/catch. Anchor uses TAB indentation. --- */
        $anchorA = "\t\t\t\tcatch ( \\Exception ) {}\n\n\t\t\t\t/* Setup wizard completion check";
        $replaceA =
            "\t\t\t\tcatch ( \\Exception ) {}\n\n" .
            "\t\t\t\t/* v191-categories-nav: count of auto-classified categories awaiting review. */\n" .
            "\t\t\t\t\$unreviewedCats = 0;\n" .
            "\t\t\t\ttry\n" .
            "\t\t\t\t{\n" .
            "\t\t\t\t\t\$unreviewedCats = \\IPS\\gddealer\\Feed\\CategoryMap::unreviewedCount( (int) \$this->dealer->dealer_id );\n" .
            "\t\t\t\t}\n" .
            "\t\t\t\tcatch ( \\Throwable ) {}\n\n" .
            "\t\t\t\t/* Setup wizard completion check";

        $countA = substr_count( $contents, $anchorA );
        if ( $countA === 1 )
        {
            $contents = str_replace( $anchorA, $replaceA, $contents );
            $changes++;
        }
        else
        {
            try { \IPS\Log::log( 'Patch A (unreviewedCats count): anchor count=' . $countA . ' (expected 1). Skipped.', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
        }

        /* --- Patch B: add Categories nav item. Anchor uses the TAB
         *              indentation of the items array. --- */
        $anchorB = "\t\t\t\t\t\t\t\t\t  'url' => \$urls['unmatched'], 'icon' => 'unmatched',\n" .
                   "\t\t\t\t\t\t\t\t\t  'badge' => \$unmatched > 0 ? [ 'count' => \$unmatched, 'variant' => 'urgent' ] : null ],";
        $replaceB =
            "\t\t\t\t\t\t\t\t\t  'url' => \$urls['unmatched'], 'icon' => 'unmatched',\n" .
            "\t\t\t\t\t\t\t\t\t  'badge' => \$unmatched > 0 ? [ 'count' => \$unmatched, 'variant' => 'urgent' ] : null ],\n" .
            "\t\t\t\t\t\t\t\t\t/* v191-categories-nav */\n" .
            "\t\t\t\t\t\t\t\t\t[ 'key' => 'categories', 'label' => 'Categories',\n" .
            "\t\t\t\t\t\t\t\t\t  'url' => \$urls['categories'], 'icon' => 'listings',\n" .
            "\t\t\t\t\t\t\t\t\t  'badge' => \$unreviewedCats > 0 ? [ 'count' => \$unreviewedCats, 'variant' => 'warn' ] : null ],";

        if ( $hasHandPatchedItem )
        {
            try { \IPS\Log::log( 'sidebarNav: Categories nav item already present from prior hand-patch. Skipping Patch B.', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
        }
        else
        {
            $countB = substr_count( $contents, $anchorB );
            if ( $countB === 1 )
            {
                $contents = str_replace( $anchorB, $replaceB, $contents );
                $changes++;
            }
            else
            {
                try { \IPS\Log::log( 'Patch B (Categories nav item): anchor count=' . $countB . ' (expected 1). Skipped.', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
            }
        }

        /* Ensure marker is present so future runs skip. If neither
         * anchor matched (hand-patched live server), inject marker as
         * a comment right after <?php. */
        if ( strpos( $contents, 'v191-categories-nav' ) === FALSE )
        {
            if ( strpos( $contents, '<?php' ) === 0 )
            {
                $markerLine = "\n/* v191-categories-nav: marker (file was hand-patched in a prior version). */\n";
                $contents = substr( $contents, 0, 5 ) . $markerLine . substr( $contents, 5 );
                $changes++;
                try { \IPS\Log::log( 'sidebarNav: stamped v191 marker comment at top of file.', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
            }
        }

        if ( $changes === 0 )
        {
            try { \IPS\Log::log( 'patchSidebarNav: nothing changed.', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
            return;
        }

        file_put_contents( $path, $contents );

        try { \IPS\Log::log( 'patchSidebarNav: applied ' . $changes . ' change(s) to DealerShellTrait.php.', 'gddealer_upg_10191' ); } catch ( \Throwable ) {}
    }

    /**
     * The clean known-good template body. This is the v189 body MINUS
     * the redundant top tab nav block. Single source of truth.
     */
    protected function getCleanTemplateBody(): string
    {
        return <<<'TPL'
<div class="gdDashboard">
    <div class="gdDashboard__shell">

        <div class="gdDashboard__body">

            <h1 class="gdDashboard__title">Category Mappings</h1>

            <p class="gdDashboard__lede">
                Your feed uses categories like "Firearms, Handguns, Pistols". We map them to one of our
                canonical categories so search and filtering work consistently. Edit any mapping below;
                set a category to "skip" to drop those records from your listings.
            </p>

            {{if $data['saved_flash']}}
            <div class="gdAlert gdAlert--success">Mappings saved.</div>
            {{endif}}

            {{if $data['unreviewed'] > 0}}
            <div class="gdAlert gdAlert--info">
                <strong>{$data['unreviewed']}</strong> mapping(s) were auto-classified and haven't been reviewed yet. Confirm or change them below — any edit you save will mark that row as reviewed.
            </div>
            {{endif}}

            {{if $data['by_canonical']}}
            <div class="gdCatSummary">
                {{foreach $data['by_canonical'] as $cat => $info}}
                <div class="gdCatSummary__pill">
                    <span class="gdCatSummary__name">{$cat}</span>
                    <span class="gdCatSummary__count">{$info['count']} records · {$info['rows']} rules</span>
                </div>
                {{endforeach}}
            </div>
            {{endif}}

            {{if count( $data['mappings'] ) === 0}}
                <div class="gdEmpty">
                    <p>No category mappings yet. Run your next import and they'll populate automatically.</p>
                </div>
            {{else}}
                <form method="post" action="{$data['save_url']}" class="gdCatForm">
                    <input type="hidden" name="csrfKey" value="{$data['csrf_key']}">

                    <table class="gdCatTable">
                        <thead>
                            <tr>
                                <th class="gdCatTable__source">Your category</th>
                                <th class="gdCatTable__canonical">Maps to</th>
                                <th class="gdCatTable__count">Records</th>
                                <th class="gdCatTable__status">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{foreach $data['mappings'] as $m}}
                            <tr class="{{if $m['auto_mapped']}}gdCatTable__row--auto{{endif}}">
                                <td class="gdCatTable__source">
                                    <span class="gdCatTable__sourceText">{$m['source_value']}</span>
                                </td>
                                <td class="gdCatTable__canonical">
                                    <select name="canonical[{$m['id']}]" class="gdCatTable__select">
                                        {{foreach $data['valid_options'] as $opt}}
                                        <option value="{$opt}" {{if $m['canonical'] === $opt}}selected{{endif}}>{$opt}</option>
                                        {{endforeach}}
                                    </select>
                                </td>
                                <td class="gdCatTable__count">{$m['occurrence_count']}</td>
                                <td class="gdCatTable__status">
                                    {{if $m['auto_mapped']}}
                                        <span class="gdCatTable__badge gdCatTable__badge--auto">auto</span>
                                    {{else}}
                                        <span class="gdCatTable__badge gdCatTable__badge--confirmed">confirmed</span>
                                    {{endif}}
                                </td>
                            </tr>
                            {{endforeach}}
                        </tbody>
                    </table>

                    <div class="gdCatForm__actions">
                        <button type="submit" class="gdBtn gdBtn--primary">Save Changes</button>
                    </div>
                </form>
            {{endif}}

        </div>
    </div>
</div>

<style>
.gdDashboard__shell { max-width: 1200px; margin: 0 auto; padding: 24px; font-family: 'Inter', system-ui, sans-serif; }
.gdDashboard__title { font-size: 28px; font-weight: 700; margin: 0 0 8px; color: #111827; }
.gdDashboard__lede { color: #6b7280; margin: 0 0 24px; max-width: 760px; line-height: 1.6; }
.gdAlert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
.gdAlert--success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
.gdAlert--info { background: #dbeafe; color: #1e40af; border: 1px solid #60a5fa; }
.gdCatSummary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
.gdCatSummary__pill { background: #f3f4f6; padding: 8px 14px; border-radius: 999px; font-size: 13px; }
.gdCatSummary__name { font-weight: 600; color: #111827; text-transform: capitalize; }
.gdCatSummary__count { color: #6b7280; margin-left: 6px; }
.gdEmpty { padding: 48px; text-align: center; background: #f9fafb; border-radius: 12px; color: #6b7280; }
.gdCatForm { background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; overflow: hidden; }
.gdCatTable { width: 100%; border-collapse: collapse; }
.gdCatTable th { background: #f9fafb; padding: 12px 16px; text-align: left; font-weight: 600; font-size: 13px; color: #374151; border-bottom: 1px solid #e5e7eb; }
.gdCatTable td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; }
.gdCatTable__row--auto { background: #fffbeb; }
.gdCatTable__sourceText { font-family: 'JetBrains Mono', monospace; font-size: 13px; color: #1f2937; }
.gdCatTable__select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
.gdCatTable__count { color: #6b7280; font-variant-numeric: tabular-nums; }
.gdCatTable__badge { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.gdCatTable__badge--auto { background: #fef3c7; color: #92400e; }
.gdCatTable__badge--confirmed { background: #d1fae5; color: #065f46; }
.gdCatForm__actions { padding: 16px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; }
.gdBtn { padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; }
.gdBtn--primary { background: #1e40af; color: #fff; }
.gdBtn--primary:hover { background: #1e3a8a; }
</style>
TPL;
    }
}

class upgrade extends _upgrade {}
