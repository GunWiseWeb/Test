<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.161 PART 1 of 1 - Update setupWizardStep1 to add upload mode.
 *
 * Changes vs v152 step 1:
 *   - Three mode tiles instead of two (URL, Paste, Upload)
 *   - New upload pane with file picker
 *   - Form has enctype="multipart/form-data" (required for uploads)
 *   - Format selector hidden in upload mode (auto-detected from extension)
 *   - JS toggle handles 3 panes instead of 2
 *
 * Reuses existing CSS by pulling it from DB. Adds a couple of new
 * style rules for the upload pane.
 */

$step1Tpl = <<<'TEMPLATE_EOT'
<div class="gdSetupWizard">

	<header class="gdSetupWizard__header">
		<div class="gdSetupWizard__heading">
			<h2>Feed Setup Wizard</h2>
			<p>Walk through 5 steps to connect your product feed. We'll auto-suggest field mappings and validate a sample so you know it'll import cleanly before you go live.</p>
		</div>
	</header>

	<nav class="gdSetupWizard__progress" aria-label="Wizard progress">
		<ol class="gdSetupWizard__steps">
			{{foreach $wizardData['steps'] as $s}}
				{{if $s['num'] < $wizardData['currentStep']}}
					{{$cls = 'is-done';}}
				{{elseif $s['num'] === $wizardData['currentStep']}}
					{{$cls = 'is-current';}}
				{{else}}
					{{$cls = 'is-upcoming';}}
				{{endif}}
				<li class="gdSetupWizard__step {$cls}">
					<span class="gdSetupWizard__stepNum">{$s['num']}</span>
					<span class="gdSetupWizard__stepLabel">{$s['label']}</span>
					<span class="gdSetupWizard__stepDesc">{$s['desc']}</span>
				</li>
			{{endforeach}}
		</ol>
	</nav>

	{{if count($errors) > 0}}
	<div class="gdSetupWizard__flash gdSetupWizard__flash--error">
		<strong>Please fix these issues:</strong>
		<ul>
		{{foreach $errors as $err}}
			<li>{$err}</li>
		{{endforeach}}
		</ul>
	</div>
	{{endif}}

	<form method="post" action="{$values['urls']['save_step1']}" enctype="multipart/form-data" class="gdSetupWizard__form" data-step="1">
		<input type="hidden" name="csrfKey" value="{$values['csrfKey']}">

		<section class="gdSetupWizard__card">
			<h3>Step 1 of {$wizardData['totalSteps']}: Feed Input</h3>
			<p>Choose how you'd like to provide your product feed. If you have a public URL where your feed lives, use Fetch Mode. If you're still setting things up and want to test with a local sample, use Paste or Upload Mode.</p>

			<div class="gdSetupWizard__modeToggle gdSetupWizard__modeToggle--three" role="radiogroup" aria-label="Input mode">
				{{$urlChecked    = ( $values['mode'] ?? 'url' ) === 'url'    ? 'checked' : '';}}
				{{$pasteChecked  = ( $values['mode'] ?? '' )    === 'paste'  ? 'checked' : '';}}
				{{$uploadChecked = ( $values['mode'] ?? '' )    === 'upload' ? 'checked' : '';}}
				<label class="gdSetupWizard__modeOption">
					<input type="radio" name="mode" value="url" {$urlChecked} data-toggle="urlMode">
					<span class="gdSetupWizard__modeLabel">Fetch from URL</span>
					<span class="gdSetupWizard__modeHint">We pull the feed from a public HTTPS URL on a schedule. Best for production.</span>
				</label>
				<label class="gdSetupWizard__modeOption">
					<input type="radio" name="mode" value="paste" {$pasteChecked} data-toggle="pasteMode">
					<span class="gdSetupWizard__modeLabel">Paste feed body</span>
					<span class="gdSetupWizard__modeHint">Paste a sample directly. Use this for testing if your feed isn't deployed yet.</span>
				</label>
				<label class="gdSetupWizard__modeOption">
					<input type="radio" name="mode" value="upload" {$uploadChecked} data-toggle="uploadMode">
					<span class="gdSetupWizard__modeLabel">Upload a file</span>
					<span class="gdSetupWizard__modeHint">Upload a feed file from your computer. Best for dealers without API access (BigCommerce, Square, Shopify Lite).</span>
				</label>
			</div>
		</section>

		<section class="gdSetupWizard__card gdSetupWizard__urlMode" data-mode-pane="url">
			<h4>Feed URL</h4>
			<label class="gdSetupWizard__field">
				<span class="gdSetupWizard__fieldLabel">URL <span class="gdSetupWizard__req">*</span></span>
				{{$fu = htmlspecialchars( $values['feed_url'] ?? '', ENT_QUOTES, 'UTF-8' );}}
				<input type="url" name="feed_url" value="{$fu}" placeholder="https://your-store.com/gunrack-feed.xml" class="gdSetupWizard__input">
				<span class="gdSetupWizard__fieldHelp">Must start with https:// (or http:// for testing). The feed should be publicly reachable - we'll fetch it on a schedule.</span>
			</label>

			<h4>Authentication</h4>
			<label class="gdSetupWizard__field">
				<span class="gdSetupWizard__fieldLabel">Auth Type</span>
				{{$at = $values['auth_type'] ?? 'none';}}
				<select name="auth_type" class="gdSetupWizard__select">
					<option value="none" {{if $at === 'none'}}selected{{endif}}>None (public URL)</option>
					<option value="basic" {{if $at === 'basic'}}selected{{endif}}>Basic Authentication</option>
					<option value="api_key" {{if $at === 'api_key'}}selected{{endif}}>API Key Header</option>
				</select>
			</label>

			<label class="gdSetupWizard__field">
				<span class="gdSetupWizard__fieldLabel">Credentials (JSON)</span>
				{{$ac = htmlspecialchars( $values['auth_credentials'] ?? '', ENT_QUOTES, 'UTF-8' );}}
				<input type="text" name="auth_credentials" value="{$ac}" placeholder='{"username":"user","password":"pass"} or {"api_key":"your-key"}' class="gdSetupWizard__input gdSetupWizard__input--mono">
				<span class="gdSetupWizard__fieldHelp">Leave blank if Auth Type is None. For Basic Auth: <code>{"username":"u","password":"p"}</code>. For API Key: <code>{"api_key":"value"}</code>.</span>
			</label>
		</section>

		<section class="gdSetupWizard__card gdSetupWizard__pasteMode" data-mode-pane="paste" style="display:none">
			<h4>Paste Feed Body</h4>
			<label class="gdSetupWizard__field">
				<span class="gdSetupWizard__fieldLabel">Feed body <span class="gdSetupWizard__req">*</span></span>
				{{$pb = htmlspecialchars( $values['paste_body'] ?? '', ENT_QUOTES, 'UTF-8' );}}
				<textarea name="paste_body" rows="14" class="gdSetupWizard__textarea gdSetupWizard__textarea--mono" placeholder="Paste your XML, JSON, or CSV feed body here">{$pb}</textarea>
				<span class="gdSetupWizard__fieldHelp">Paste up to 10 MB. We'll parse this and discover your fields in the next step.</span>
			</label>
		</section>

		<section class="gdSetupWizard__card gdSetupWizard__uploadMode" data-mode-pane="upload" style="display:none">
			<h4>Upload Feed File</h4>
			<p>Pick a feed file from your computer. We support CSV, XML, JSON, TSV, and TXT files up to 50 MB. The file will be saved and the import scheduler will pick it up on its next run, so this also serves as your first feed import.</p>
			<div class="gdSetupWizard__uploadDrop">
				<input type="file" name="upload_file" id="gd_upload_file" accept=".csv,.xml,.json,.tsv,.txt">
				<label for="gd_upload_file" class="gdSetupWizard__uploadLabel">
					<span class="gdSetupWizard__uploadIcon">&#8682;</span>
					<span class="gdSetupWizard__uploadText">Click to choose a file or drag here</span>
					<span class="gdSetupWizard__uploadHint">CSV, XML, JSON, TSV, TXT &middot; up to 50 MB</span>
				</label>
			</div>
			<p class="gdSetupWizard__uploadNote"><strong>Note:</strong> Uploading sets your delivery mode to <em>Manual</em> automatically. Format will be detected from the file extension - no need to set it below.</p>
		</section>

		<section class="gdSetupWizard__card gdSetupWizard__formatSection" data-mode-pane-format>
			<h4>Feed Format</h4>
			<label class="gdSetupWizard__field">
				<span class="gdSetupWizard__fieldLabel">Format <span class="gdSetupWizard__req">*</span></span>
				{{$ff = $values['feed_format'] ?? 'xml';}}
				<select name="feed_format" class="gdSetupWizard__select">
					<option value="xml" {{if $ff === 'xml'}}selected{{endif}}>XML</option>
					<option value="json" {{if $ff === 'json'}}selected{{endif}}>JSON</option>
					<option value="csv" {{if $ff === 'csv'}}selected{{endif}}>CSV</option>
				</select>
				<span class="gdSetupWizard__fieldHelp">Pick whichever format your existing feed uses. We support all three equally.</span>
			</label>
		</section>

		<div class="gdSetupWizard__actions">
			<a href="{$values['urls']['dashboard']}" class="gdSetupWizard__btn gdSetupWizard__btn--ghost">Cancel</a>
			<button type="submit" class="gdSetupWizard__btn gdSetupWizard__btn--primary">Save &amp; Continue</button>
		</div>
	</form>

	<script>
	(function(){
		var modeRadios = document.querySelectorAll('input[name="mode"]');
		var panes = {
			url:    document.querySelector('[data-mode-pane="url"]'),
			paste:  document.querySelector('[data-mode-pane="paste"]'),
			upload: document.querySelector('[data-mode-pane="upload"]')
		};
		var formatPane = document.querySelector('[data-mode-pane-format]');
		function showFor(mode) {
			Object.keys(panes).forEach(function(k){
				if (panes[k]) panes[k].style.display = (k === mode ? '' : 'none');
			});
			/* Format selector is irrelevant in upload mode (auto-detected). */
			if (formatPane) {
				formatPane.style.display = (mode === 'upload' ? 'none' : '');
			}
		}
		var current = 'url';
		modeRadios.forEach(function(r){ if (r.checked) current = r.value; });
		showFor(current);
		modeRadios.forEach(function(r){
			r.addEventListener('change', function(){ if (r.checked) showFor(r.value); });
		});

		/* Upload tile: when a file is chosen, show its name in the label. */
		var fileInput = document.getElementById('gd_upload_file');
		if (fileInput) {
			fileInput.addEventListener('change', function(){
				var labelText = document.querySelector('.gdSetupWizard__uploadText');
				if (labelText && fileInput.files && fileInput.files.length > 0) {
					labelText.textContent = fileInput.files[0].name;
				}
			});
		}
	})();
	</script>

</div>
TEMPLATE_EOT;

/* CSS: pull existing block from DB, append a few new rules for the
 * three-tile layout and upload pane. */
try
{
    $existing = \IPS\Db::i()->select( 'template_content', 'core_theme_templates',
        [ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
          'gddealer', 'front', 'dealers', 'setupWizardStep1' ]
    )->first();

    if ( preg_match( '#<style>.*?</style>#s', (string) $existing, $cssMatch ) )
    {
        $step1Tpl .= "\n" . $cssMatch[0];
    }
}
catch ( \Throwable $e )
{
    try { \IPS\Log::log( 'templates_10161_part1.php css fetch failed: ' . $e->getMessage(), 'gddealer_upg_10161' ); }
    catch ( \Throwable ) {}
}

/* Append new styles for upload mode (only if not already present). */
$newCss = <<<'CSS'
<style>
.gdSetupWizard__modeToggle--three { grid-template-columns: repeat(3, 1fr); }
@media (max-width: 900px) { .gdSetupWizard__modeToggle--three { grid-template-columns: 1fr; } }

.gdSetupWizard__uploadDrop {
	position: relative;
	padding: 36px 20px;
	border: 2px dashed #cbd5e1;
	border-radius: 10px;
	background: #f8fafc;
	text-align: center;
	cursor: pointer;
	transition: all 0.15s;
	margin: 12px 0;
}
.gdSetupWizard__uploadDrop:hover { border-color: #2563eb; background: #eff6ff; }
.gdSetupWizard__uploadDrop input[type=file] {
	position: absolute;
	inset: 0;
	opacity: 0;
	cursor: pointer;
}
.gdSetupWizard__uploadLabel {
	display: flex;
	flex-direction: column;
	gap: 6px;
	align-items: center;
	color: #475569;
	cursor: pointer;
}
.gdSetupWizard__uploadIcon { font-size: 36px; color: #94a3b8; line-height: 1; }
.gdSetupWizard__uploadText { font-size: 15px; font-weight: 600; color: #1e293b; }
.gdSetupWizard__uploadHint { font-size: 12.5px; color: #94a3b8; }
.gdSetupWizard__uploadNote {
	margin: 12px 0 0;
	padding: 10px 14px;
	background: #fffbeb;
	border: 1px solid #fcd34d;
	border-radius: 8px;
	font-size: 13px;
	color: #78350f;
}
.gdSetupWizard__uploadNote em { font-style: normal; font-weight: 700; }
</style>
CSS;

if ( !str_contains( $step1Tpl, 'gdSetupWizard__uploadDrop' ) )
{
    $step1Tpl .= "\n" . $newCss;
}

try
{
    \IPS\Db::i()->update( 'core_theme_templates',
        [
            'template_data'    => '$wizardData,$values,$errors',
            'template_content' => $step1Tpl,
            'template_updated' => time(),
        ],
        [ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
          'gddealer', 'front', 'dealers', 'setupWizardStep1' ]
    );
}
catch ( \Throwable $e )
{
    try { \IPS\Log::log( 'templates_10161_part1.php update failed: ' . $e->getMessage(), 'gddealer_upg_10161' ); }
    catch ( \Throwable ) {}
}
