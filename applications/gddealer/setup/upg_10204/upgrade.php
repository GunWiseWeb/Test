<?php
namespace IPS\gddealer\setup\upg_10204;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$feedValidatorTpl = <<<'TEMPLATE_EOT'
<div class="gd-feedval">
<style>
.gd-feedval {
    --gd-brand: #1E40AF;
    --gd-brand-hover: #1E3A8A;
    --gd-brand-light: #EFF6FF;
    --gd-brand-border: #BFDBFE;
    --gd-surface: #FFFFFF;
    --gd-surface-muted: #F8FAFC;
    --gd-border: #E5E7EB;
    --gd-border-strong: #CBD5E1;
    --gd-text: #0F172A;
    --gd-text-muted: #475569;
    --gd-text-subtle: #64748B;
    --gd-error: #B91C1C;
    --gd-error-bg: #FEE2E2;
    --gd-warn: #B45309;
    --gd-warn-bg: #FEF3C7;
    --gd-ok: #047857;
    --gd-ok-bg: #D1FAE5;
    --gd-r-md: 6px;
    --gd-r-lg: 10px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    color: var(--gd-text);
    font-size: 14px;
    line-height: 1.5;
    max-width: 1446px;
    margin: 0 auto;
    padding: 2rem 24px;
    box-sizing: border-box;
}
.gd-feedval *, .gd-feedval *::before, .gd-feedval *::after { box-sizing: border-box; }
.gd-feedval h1 { font-size: 28px; font-weight: 600; margin: 0 0 8px; letter-spacing: -0.02em; }
.gd-feedval .lede { color: var(--gd-text-muted); margin: 0 0 24px; max-width: 720px; }
.gd-feedval .lede a { color: var(--gd-brand); }

.gd-feedval .card { background: var(--gd-surface); border: 1px solid var(--gd-border); border-radius: var(--gd-r-lg); padding: 1.5rem; margin-bottom: 1rem; }

.gd-feedval .field { margin-bottom: 1rem; }
.gd-feedval .field-label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; }
.gd-feedval .formats { display: flex; gap: 12px; }
.gd-feedval .formats label { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid var(--gd-border); border-radius: var(--gd-r-md); cursor: pointer; font-size: 13px; }
.gd-feedval .formats label.active { border-color: var(--gd-brand); background: var(--gd-brand-light); color: var(--gd-brand); }
.gd-feedval .formats input { margin: 0; }

.gd-feedval textarea { width: 100%; min-height: 280px; font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; font-size: 12px; padding: 12px; border: 1px solid var(--gd-border); border-radius: var(--gd-r-md); resize: vertical; box-sizing: border-box; }
.gd-feedval textarea:focus { outline: none; border-color: var(--gd-brand); box-shadow: 0 0 0 3px rgba(30,64,175,0.1); }

.gd-feedval .actions { display: flex; gap: 8px; align-items: center; }
.gd-feedval .btn { padding: 10px 18px; border-radius: var(--gd-r-md); border: 1px solid var(--gd-border); background: var(--gd-surface); color: var(--gd-text); cursor: pointer; font-family: inherit; font-size: 13px; font-weight: 500; }
.gd-feedval .btn:hover { background: var(--gd-surface-muted); border-color: var(--gd-border-strong); }
.gd-feedval .btn.primary { background: var(--gd-brand); color: white; border-color: var(--gd-brand); }
.gd-feedval .btn.primary:hover { background: var(--gd-brand-hover); }
.gd-feedval .btn:disabled { opacity: 0.5; cursor: not-allowed; }

.gd-feedval .results { display: none; }
.gd-feedval .results.show { display: block; }
.gd-feedval .summary { display: flex; gap: 12px; flex-wrap: wrap; padding: 14px 18px; border-radius: var(--gd-r-md); margin-bottom: 1rem; align-items: center; }
.gd-feedval .summary.ok { background: var(--gd-ok-bg); color: var(--gd-ok); }
.gd-feedval .summary.bad { background: var(--gd-error-bg); color: var(--gd-error); }
.gd-feedval .summary-stat { font-size: 13px; }
.gd-feedval .summary-stat strong { font-weight: 600; }
.gd-feedval .summary-icon { font-size: 18px; }

.gd-feedval .issue-table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; font-size: 13px; }
.gd-feedval .issue-table th { text-align: left; font-weight: 600; padding: 10px 12px; background: var(--gd-surface-muted); border-bottom: 1px solid var(--gd-border); }
.gd-feedval .issue-table td { padding: 10px 12px; border-bottom: 1px solid var(--gd-border); vertical-align: top; }
.gd-feedval .issue-table .row-num { font-variant-numeric: tabular-nums; color: var(--gd-text-subtle); width: 60px; }
.gd-feedval .issue-table .field-name { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; color: var(--gd-text-muted); width: 140px; }
.gd-feedval .issue-table .upc-cell { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; color: var(--gd-text-subtle); width: 130px; }
.gd-feedval .section-head { font-weight: 600; margin: 1.5rem 0 8px; display: flex; align-items: center; gap: 8px; }
.gd-feedval .section-head .pill { font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
.gd-feedval .section-head .pill.err { background: var(--gd-error-bg); color: var(--gd-error); }
.gd-feedval .section-head .pill.warn { background: var(--gd-warn-bg); color: var(--gd-warn); }
.gd-feedval .section-head .pill.note { background: #EFF6FF; color: #1E40AF; }

.gd-feedval .raw-toggle { font-size: 12px; color: var(--gd-text-subtle); cursor: pointer; user-select: none; margin-top: 1rem; }
.gd-feedval pre.raw { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 11px; background: var(--gd-surface-muted); padding: 12px; border-radius: var(--gd-r-md); overflow-x: auto; max-height: 400px; display: none; }
.gd-feedval pre.raw.show { display: block; }
</style>

    <h1>Feed validator</h1>
    <p class="lede">Paste a sample of your XML, JSON, or CSV feed below and we'll check it against the GunRack v1 schema. No data is saved &mdash; this is a pure dry-run check. See the <a href="/dealers/feed-schema">schema docs</a> for the full field reference.</p>

    <div class="card">
        <div class="field">
            <label class="field-label">Format</label>
            <div class="formats" id="gdfvFormats">
                <label class="active"><input type="radio" name="format" value="xml" checked> XML</label>
                <label><input type="radio" name="format" value="json"> JSON</label>
                <label><input type="radio" name="format" value="csv"> CSV</label>
            </div>
        </div>

        <div class="field">
            <label class="field-label" for="gdfvBody">Feed body</label>
            <textarea id="gdfvBody" placeholder="Paste your feed contents here..."></textarea>
        </div>

        <div class="actions">
            <button type="button" class="btn primary" id="gdfvCheck">Validate feed</button>
            <button type="button" class="btn" id="gdfvClear">Clear</button>
        </div>
    </div>

    <div class="card results" id="gdfvResults">
        <div id="gdfvSummary"></div>
        <div id="gdfvErrors"></div>
        <div id="gdfvWarnings"></div>
        <div id="gdfvNotes"></div>
        <div class="raw-toggle" id="gdfvRawToggle">Show raw JSON response</div>
        <pre class="raw" id="gdfvRaw"></pre>
    </div>

<script>
(function(){
    var formats = document.getElementById('gdfvFormats');
    var body    = document.getElementById('gdfvBody');
    var check   = document.getElementById('gdfvCheck');
    var clear   = document.getElementById('gdfvClear');
    var results = document.getElementById('gdfvResults');
    var sumDiv  = document.getElementById('gdfvSummary');
    var errDiv  = document.getElementById('gdfvErrors');
    var warnDiv = document.getElementById('gdfvWarnings');
    var noteDiv = document.getElementById('gdfvNotes');
    var rawTog  = document.getElementById('gdfvRawToggle');
    var rawPre  = document.getElementById('gdfvRaw');

    formats.addEventListener('click', function(e){
        var lbl = e.target.closest('label');
        if (!lbl) return;
        formats.querySelectorAll('label').forEach(function(l){ l.classList.remove('active'); });
        lbl.classList.add('active');
    });

    rawTog.addEventListener('click', function(){
        rawPre.classList.toggle('show');
        rawTog.textContent = rawPre.classList.contains('show') ? 'Hide raw JSON response' : 'Show raw JSON response';
    });

    clear.addEventListener('click', function(){
        body.value = '';
        results.classList.remove('show');
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
        });
    }

    function renderIssueTable(issues, kind) {
        if (!issues || !issues.length) return '';
        var label = kind === 'error' ? 'Errors' : (kind === 'warning' ? 'Warnings' : 'Notes');
        var pill  = kind === 'error' ? 'err' : (kind === 'warning' ? 'warn' : 'note');
        var html  = '<div class="section-head">' + label + ' <span class="pill ' + pill + '">' + issues.length + '</span></div>';
        html += '<table class="issue-table"><thead><tr><th>Row</th><th>UPC</th><th>Field</th><th>Message</th></tr></thead><tbody>';
        issues.forEach(function(i){
            html += '<tr><td class="row-num">' + escapeHtml(i.row) + '</td>';
            html += '<td class="upc-cell">' + escapeHtml(i.upc || '-') + '</td>';
            html += '<td class="field-name">' + escapeHtml(i.field) + '</td>';
            html += '<td>' + escapeHtml(i.message) + '</td></tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    check.addEventListener('click', function(){
        var fmt = formats.querySelector('input[name="format"]:checked').value;
        var b   = body.value;
        if (!b.trim()) { alert('Paste a feed body first.'); return; }

        check.disabled = true;
        check.textContent = 'Validating...';

        var fd = new FormData();
        fd.append('format', fmt);
        fd.append('body', b);

        fetch('/dealers/feed-validator?do=check', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(data){
                results.classList.add('show');
                var s = data.summary || {};
                var sumClass = data.valid ? 'ok' : 'bad';
                var sumIcon  = data.valid ? '&check;' : '&cross;';
                var sumLabel = data.valid ? 'Feed is valid' : 'Feed has errors';
                sumDiv.innerHTML = '<div class="summary ' + sumClass + '">' +
                    '<span class="summary-icon">' + sumIcon + '</span>' +
                    '<span class="summary-stat"><strong>' + sumLabel + '</strong></span>' +
                    '<span class="summary-stat">&middot; <strong>' + (s.total_records || 0) + '</strong> total</span>' +
                    '<span class="summary-stat">&middot; <strong>' + (s.valid_records || 0) + '</strong> valid</span>' +
                    '<span class="summary-stat">&middot; <strong>' + (s.error_records || 0) + '</strong> with errors</span>' +
                    '<span class="summary-stat">&middot; <strong>' + (s.warning_records || 0) + '</strong> with warnings</span>' +
                    ((s.note_records || 0) > 0 ? '<span class="summary-stat">&middot; <strong>' + s.note_records + '</strong> with notes</span>' : '') +
                    '</div>';
                errDiv.innerHTML  = renderIssueTable(data.errors, 'error');
                warnDiv.innerHTML = renderIssueTable(data.warnings, 'warning');
                noteDiv.innerHTML = renderIssueTable(data.notes, 'note');
                rawPre.textContent = JSON.stringify(data, null, 2);
            })
            .catch(function(err){
                results.classList.add('show');
                sumDiv.innerHTML = '<div class="summary bad"><span class="summary-icon">&cross;</span><span class="summary-stat"><strong>Network error:</strong> ' + escapeHtml(err.message) + '</span></div>';
                errDiv.innerHTML = '';
                warnDiv.innerHTML = '';
                noteDiv.innerHTML = '';
                rawPre.textContent = '';
            })
            .finally(function(){
                check.disabled = false;
                check.textContent = 'Validate feed';
            });
    });
})();
</script>

</div>
TEMPLATE_EOT;

		try {
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'   => 1,
				'template_app'      => 'gddealer',
				'template_location' => 'front',
				'template_group'    => 'dealers',
				'template_name'     => 'feedValidator',
				'template_data'     => '',
				'template_content'  => $feedValidatorTpl,
				'template_updated'  => time(),
				'template_version'  => '1.0.204',
			] );
		} catch ( \Throwable $e ) {
			try { \IPS\Log::log( 'upg_10204 feedValidator reseed failed: ' . $e->getMessage(), 'gddealer_upg_10204' ); } catch ( \Throwable ) {}
		}

		require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10143.php';
		require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10147_help.php';

		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }       catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
