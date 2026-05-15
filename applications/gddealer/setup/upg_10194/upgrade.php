<?php
/**
 * gddealer v1.0.194 - Wire up the v193 Danger Zone properly.
 *
 * BACKGROUND
 * ----------
 * v193 appended a Danger Zone block to the dashboardCustomize template
 * that references three data keys:
 *   - $data['reset_action_url']
 *   - $data['csrf_key']            (already present in customize() data)
 *   - $data['profile']['dealer_name']  (already present)
 *
 * The customize() controller method's $data array DOES NOT pass
 * 'reset_action_url'. When IPS's template engine encounters the
 * undefined key, the render bails before the Danger Zone HTML reaches
 * the browser. No uncaught exception, just a silent truncation.
 *
 * v194 fixes this two ways:
 *
 *   1. PATCH customize() in modules/front/dealers/dashboard.php to
 *      insert 'reset_action_url' into the $data array. Inserted right
 *      after 'csrf_key' via line-splice / content-regex (v192 pattern).
 *
 *   2. REPLACE the Danger Zone block in dashboardCustomize template
 *      with a defensive version that uses {{if isset(...)}} guards.
 *      Strips the v193 block via regex first, then appends the v194
 *      version. Marker: v194-danger-zone (replaces v193-danger-zone).
 *
 * Idempotent throughout. Safe to run multiple times.
 */

namespace IPS\gddealer\setup\upg_10194;

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
            if ( $appVersion < 10193 )
            {
                try { \IPS\Log::log( 'v194 ran with app_long_version=' . $appVersion . ' (expected >=10193).', 'gddealer_upg_10194' ); } catch ( \Throwable ) {}
            }
        }
        catch ( \Throwable ) {}

        $this->patchCustomizeController();
        $this->rebuildDangerZone();

        try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
        try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

        try { \IPS\Log::log( 'v194 upgrade complete.', 'gddealer_upg_10194' ); } catch ( \Throwable ) {}

        return TRUE;
    }

    /**
     * Insert 'reset_action_url' into customize()'s $data array.
     * Anchor: the line containing "'csrf_key'           => $csrfKey,".
     * Content-regex match ignores exact whitespace; new line uses the
     * same leading whitespace as the anchor.
     */
    protected function patchCustomizeController(): void
    {
        $path = \IPS\ROOT_PATH . '/applications/gddealer/modules/front/dealers/dashboard.php';
        if ( !is_file( $path ) ) { return; }

        $contents = (string) file_get_contents( $path );

        if ( strpos( $contents, "'reset_action_url'" ) !== FALSE )
        {
            try { \IPS\Log::log( "patchCustomizeController: 'reset_action_url' already present. Skipping.", 'gddealer_upg_10194' ); } catch ( \Throwable ) {}
            return;
        }

        $lines = explode( "\n", $contents );

        $targetIdx = NULL;
        foreach ( $lines as $i => $line )
        {
            if ( preg_match( "/'csrf_key'\s*=>\s*\\\$csrfKey\s*,/", $line ) )
            {
                $targetIdx = $i;
                break;
            }
        }

        if ( $targetIdx === NULL )
        {
            try { \IPS\Log::log( "patchCustomizeController: anchor 'csrf_key' => \$csrfKey not found.", 'gddealer_upg_10194' ); } catch ( \Throwable ) {}
            return;
        }

        $ws = '';
        if ( preg_match( '/^(\s*)/', $lines[ $targetIdx ], $m ) )
        {
            $ws = $m[1];
        }

        $newLine = $ws . "'reset_action_url'   => (string) \\IPS\\Http\\Url::internal( 'app=gddealer&module=dealers&controller=dashboard&do=confirmReset' )->csrf(),";

        array_splice( $lines, $targetIdx + 1, 0, [ $newLine ] );

        file_put_contents( $path, implode( "\n", $lines ) );

        try { \IPS\Log::log( "patchCustomizeController: inserted 'reset_action_url' after 'csrf_key' line.", 'gddealer_upg_10194' ); } catch ( \Throwable ) {}
    }

    /**
     * Replace the v193 Danger Zone block in dashboardCustomize with a
     * defensive v194 version. Strategy:
     *   1. Find the existing block via regex matching from
     *      <!-- v193-danger-zone --> through the trailing </script>
     *      of its inline JS.
     *   2. Replace it (and strip any trailing whitespace) with the
     *      v194 block.
     *   3. If v193 marker not present, just append (clean install).
     *
     * Idempotent via v194-danger-zone marker in attribute, not HTML
     * comment (so IPS template engine doesn't choke).
     */
    protected function rebuildDangerZone(): void
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
            try { \IPS\Log::log( 'rebuildDangerZone: dashboardCustomize template not found.', 'gddealer_upg_10194' ); } catch ( \Throwable ) {}
            return;
        }

        $content = (string) $row['template_content'];

        if ( strpos( $content, 'data-v="194"' ) !== FALSE && strpos( $content, 'v194-danger-zone' ) !== FALSE )
        {
            try { \IPS\Log::log( 'rebuildDangerZone: v194 already installed. Skipping.', 'gddealer_upg_10194' ); } catch ( \Throwable ) {}
            return;
        }

        $newBlock = $this->getDangerZoneBlock();

        /* Strip everything from the v193 HTML comment (if present) to
         * the end of file. The JS-end </script> is the last thing in
         * the v193 block, after which the template should end. */
        $v193MarkerPos = strpos( $content, '<!-- v193-danger-zone -->' );
        if ( $v193MarkerPos !== FALSE )
        {
            $content = rtrim( substr( $content, 0, $v193MarkerPos ) );
            try { \IPS\Log::log( 'rebuildDangerZone: stripped v193 block (cut at position ' . $v193MarkerPos . ').', 'gddealer_upg_10194' ); } catch ( \Throwable ) {}
        }

        /* If a v194 block somehow remains in content, strip it too. */
        $v194MarkerPos = strpos( $content, 'data-v="194"' );
        if ( $v194MarkerPos !== FALSE )
        {
            /* Walk back to the start of the containing <div. */
            $divStart = strrpos( substr( $content, 0, $v194MarkerPos ), '<div id="gdDangerZone"' );
            if ( $divStart !== FALSE )
            {
                $content = rtrim( substr( $content, 0, $divStart ) );
                try { \IPS\Log::log( 'rebuildDangerZone: stripped existing v194 block at position ' . $divStart . '.', 'gddealer_upg_10194' ); } catch ( \Throwable ) {}
            }
        }

        $newContent = $content . "\n\n" . $newBlock;

        \IPS\Db::i()->update( 'core_theme_templates',
            [
                'template_content' => $newContent,
                'template_updated' => time(),
            ],
            [ 'template_id=?', (int) $row['template_id'] ]
        );

        try { \IPS\Log::log( 'rebuildDangerZone: wrote v194 block (' . strlen( $newBlock ) . ' bytes block, ' . strlen( $newContent ) . ' bytes total).', 'gddealer_upg_10194' ); } catch ( \Throwable ) {}
    }

    /**
     * v194 Danger Zone block. Differences from v193:
     *   - No HTML comment marker (IPS parser can fragile with <!-- -->
     *     near template tags). Marker is data-v="194" attribute and
     *     a CSS class 'v194-danger-zone'.
     *   - Defensive {{if isset(...)}} guards on every $data key the
     *     block reads.
     *   - Inline JS hoisted to the END of the block, AFTER the form,
     *     so a JS parse error can't kill the visible HTML.
     */
    protected function getDangerZoneBlock(): string
    {
        return <<<'TPL'
<div id="gdDangerZone" class="gdPanel v194-danger-zone" data-v="194" style="border:2px solid #b91c1c;background:#fef2f2;margin-top:48px;padding:24px;border-radius:12px">
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

        {{if isset( $data['reset_action_url'] ) and isset( $data['csrf_key'] ) and isset( $data['profile']['dealer_name'] )}}
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
        {{else}}
        <p style="font-size:12px;color:#b91c1c;background:#fff;border:1px solid #fca5a5;padding:8px 12px;border-radius:6px;margin:0">
            The reset form is not configured. Please reload the page; if it still doesn't appear, contact GunRack support.
        </p>
        {{endif}}
    </div>
</div>

<script>
window.gdConfirmReset = function( frm ) {
    var input = frm.querySelector( 'input[name="confirm_name"]' );
    if ( !input ) { return false; }
    var expected = input.getAttribute( 'placeholder' );
    var typed = ( input.value || '' ).trim();
    if ( typed !== expected ) {
        alert( 'You must type your dealer name exactly to confirm: ' + expected );
        input.focus();
        return false;
    }
    return confirm( 'This will wipe all imported data and reset your feed setup. Continue?' );
};
</script>
TPL;
    }
}

class upgrade extends _upgrade {}
