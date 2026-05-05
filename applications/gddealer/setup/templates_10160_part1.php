<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.160 PART 1 of 1 - Update setupWizardStep5 template body to
 * adapt framing based on $values['is_completed']:
 *
 *   First-time finish (is_completed = false):
 *     - "You're almost done!" intro text
 *     - "Step 5 of 5: Preview & Save" heading
 *     - "Finish Setup" green button
 *
 *   Revisit after completion (is_completed = true):
 *     - Top banner: "You completed this wizard on [date]. Make changes
 *       and Save Changes, or click any earlier step to edit."
 *     - "Step 5 of 5: Current Configuration" heading
 *     - "Save Changes" button (does the same thing as Finish; also
 *       updates wizard_completed_at to now)
 *
 * Reuses all existing step 5 CSS (no part 2 needed - CSS unchanged).
 */

$step5Tpl = <<<'TEMPLATE_EOT'
<div class="gdSetupWizard">

	<header class="gdSetupWizard__header">
		<div class="gdSetupWizard__heading">
			<h2>Feed Setup Wizard</h2>
			{{if $values['is_completed']}}
				<p>You can update any step here and save changes to apply them. Use the progress bar or Back button to jump to a different step.</p>
			{{else}}
				<p>You're almost done! Review your configuration below, then hit Finish to lock it in. You can always come back and re-run the wizard to reconfigure.</p>
			{{endif}}
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

	{{if $values['is_completed']}}
	<div class="gdSetupWizard__flash gdSetupWizard__flash--info">
		<strong>&#10003; Wizard previously completed</strong>
		Originally finished {$values['completed_at']}. You can review your current setup below or navigate back to any step to make changes. Click <em>Save Changes</em> at the bottom to re-apply your configuration.
	</div>
	{{endif}}

	{{if count($values['errors']) > 0}}
	<div class="gdSetupWizard__flash gdSetupWizard__flash--error">
		<strong>Could not finalize:</strong>
		<ul>
		{{foreach $values['errors'] as $err}}
			<li>{$err}</li>
		{{endforeach}}
		</ul>
	</div>
	{{endif}}

	<section class="gdSetupWizard__card">
		{{if $values['is_completed']}}
			<h3>Step 5 of {$wizardData['totalSteps']}: Current Configuration</h3>
			<p>This is your current feed configuration. To change anything, use the progress bar above or click Back to navigate to an earlier step. Click Save Changes at the bottom to re-apply.</p>
		{{else}}
			<h3>Step 5 of {$wizardData['totalSteps']}: Preview &amp; Save</h3>
			<p>Below is a summary of how your feed will be imported. Review the field mapping, peek at how the first records would look once normalized, and then hit Finish.</p>
		{{endif}}
	</section>

	<section class="gdSetupWizard__card">
		<h4>Field Mapping</h4>
		<p>Required fields (and any optional fields you mapped) are listed below. Defaults will be applied to every record.</p>
		<table class="gdSetupWizard__summaryTable">
			<thead>
				<tr>
					<th>Our field</th>
					<th>Source</th>
					<th>Value</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $values['mapping_rows'] as $row}}
					{{if $row['source'] === 'feed'}}
						{{$srcLabel = 'From feed';}}
						{{$srcCls = 'gdSetupWizard__src--feed';}}
					{{elseif $row['source'] === 'default'}}
						{{$srcLabel = 'Default';}}
						{{$srcCls = 'gdSetupWizard__src--default';}}
					{{else}}
						{{$srcLabel = 'Not mapped';}}
						{{$srcCls = 'gdSetupWizard__src--none';}}
					{{endif}}
					<tr>
						<td>
							<code>{$row['slug']}</code>
							{{if $row['req'] === 'required'}}<span class="gdSetupWizard__reqBadge gdSetupWizard__reqBadge--required">Required</span>{{endif}}
							{{if $row['req'] === 'conditional'}}<span class="gdSetupWizard__reqBadge gdSetupWizard__reqBadge--conditional">Conditional</span>{{endif}}
							<div class="gdSetupWizard__canonLabel">{$row['label']}</div>
						</td>
						<td><span class="gdSetupWizard__srcBadge {$srcCls}">{$srcLabel}</span></td>
						<td>
							{{if $row['source'] === 'feed'}}
								<code class="gdSetupWizard__sample">{$row['value']}</code>
							{{elseif $row['source'] === 'default'}}
								<code class="gdSetupWizard__sample gdSetupWizard__sample--default">{$row['value']}</code>
							{{else}}
								<span class="gdSetupWizard__samplePlaceholder">&mdash;</span>
							{{endif}}
						</td>
					</tr>
				{{endforeach}}
			</tbody>
		</table>
	</section>

	{{if count($values['preview_records']) > 0}}
	<section class="gdSetupWizard__card">
		<h4>Sample import preview</h4>
		<p>Here's how the first {$values['preview_count']} records would look after we apply your mapping. Records flagged with errors won't actually import.</p>
		<div class="gdSetupWizard__previewWrap">
			<table class="gdSetupWizard__previewTable">
				<thead>
					<tr>
						<th>#</th>
						{{foreach $values['preview_columns'] as $col}}
							<th>{$col['label']}</th>
						{{endforeach}}
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					{{foreach $values['preview_records'] as $rec}}
						{{if $rec['has_errors']}}
							{{$badgeCls = 'gdSetupWizard__statusBadge gdSetupWizard__status--error';}}
							{{$badgeLabel = 'Errors';}}
						{{elseif $rec['has_warnings']}}
							{{$badgeCls = 'gdSetupWizard__statusBadge gdSetupWizard__status--warn';}}
							{{$badgeLabel = 'Warnings';}}
						{{else}}
							{{$badgeCls = 'gdSetupWizard__statusBadge gdSetupWizard__status--ok';}}
							{{$badgeLabel = 'Valid';}}
						{{endif}}
						<tr>
							<td>{$rec['row']}</td>
							{{foreach $values['preview_columns'] as $col}}
								{{$cell = isset( $rec['cells'][ $col['slug'] ] ) ? $rec['cells'][ $col['slug'] ] : '';}}
								<td>
									{{if $cell !== ''}}
										<code class="gdSetupWizard__sample">{$cell}</code>
									{{else}}
										<span class="gdSetupWizard__samplePlaceholder">&mdash;</span>
									{{endif}}
								</td>
							{{endforeach}}
							<td><span class="{$badgeCls}">{$badgeLabel}</span></td>
						</tr>
					{{endforeach}}
				</tbody>
			</table>
		</div>
	</section>
	{{endif}}

	<section class="gdSetupWizard__card">
		<h4>Validation summary</h4>
		<div class="gdSetupWizard__step5Stats">
			<div class="gdSetupWizard__stat gdSetupWizard__stat--good">
				<span class="gdSetupWizard__statNum">{$values['valid_records']}</span>
				<span class="gdSetupWizard__statLabel">Valid records</span>
			</div>
			<div class="gdSetupWizard__stat gdSetupWizard__stat--bad">
				<span class="gdSetupWizard__statNum">{$values['error_records']}</span>
				<span class="gdSetupWizard__statLabel">With errors</span>
			</div>
			<div class="gdSetupWizard__stat gdSetupWizard__stat--warn">
				<span class="gdSetupWizard__statNum">{$values['warning_records']}</span>
				<span class="gdSetupWizard__statLabel">With warnings</span>
			</div>
		</div>
		{{if $values['has_errors']}}
		<div class="gdSetupWizard__warning">
			<strong>Heads up:</strong> Some records had validation errors. You can still finish the wizard - those records will be skipped at import time. Fix the underlying issues in your feed (or via <a href="{$values['urls']['step3']}">step 3 mapping</a>) and re-run the wizard later to revalidate.
		</div>
		{{endif}}
	</section>

	{{if !$values['is_completed']}}
	<section class="gdSetupWizard__card gdSetupWizard__card--info">
		<h4>What happens after Finish?</h4>
		<ul class="gdSetupWizard__nextSteps">
			<li>Your feed configuration is saved and the import scheduler will pick it up on its next run.</li>
			<li>You'll land on the <strong>Feed Settings</strong> page where you can upload feed files (manual mode) or check your import history.</li>
			<li>You can re-run this wizard anytime from the sidebar to reconfigure from scratch.</li>
		</ul>
	</section>
	{{endif}}

	<form method="post" action="{$values['urls']['save_step5']}" class="gdSetupWizard__form" data-step="5">
		<input type="hidden" name="csrfKey" value="{$values['csrfKey']}">

		<div class="gdSetupWizard__actions">
			<a href="{$values['urls']['step4']}" class="gdSetupWizard__btn gdSetupWizard__btn--ghost">&larr; Back to Step 4</a>
			{{if $values['is_completed']}}
				<button type="submit" class="gdSetupWizard__btn gdSetupWizard__btn--primary">&#10003; Save Changes</button>
			{{else}}
				<button type="submit" class="gdSetupWizard__btn gdSetupWizard__btn--primary">&#10003; Finish Setup</button>
			{{endif}}
		</div>
	</form>

</div>
TEMPLATE_EOT;

/* CSS unchanged from v158. Re-append the same block so the assembled
 * template content matches what's expected by the renderer. We re-fetch
 * the existing CSS from DB to avoid drift. */

try
{
    $existing = \IPS\Db::i()->select( 'template_content', 'core_theme_templates',
        [ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
          'gddealer', 'front', 'dealers', 'setupWizardStep5' ]
    )->first();

    /* Extract the <style>...</style> block from the existing template
     * and append it to our new body. */
    if ( preg_match( '#<style>.*?</style>#s', (string) $existing, $cssMatch ) )
    {
        $step5Tpl .= "\n" . $cssMatch[0];
    }
}
catch ( \Throwable $e )
{
    try { \IPS\Log::log( 'templates_10160_part1.php css fetch failed: ' . $e->getMessage(), 'gddealer_upg_10160' ); }
    catch ( \Throwable ) {}
}

/* Add the gdSetupWizard__flash--info style if it's not already in the CSS
 * (it wasn't in v158 because we only had --success and --error variants). */
if ( !str_contains( $step5Tpl, 'gdSetupWizard__flash--info' ) )
{
    $step5Tpl .= "\n<style>.gdSetupWizard__flash--info { background: #eff6ff; border: 1px solid #93c5fd; color: #1e3a8a; } .gdSetupWizard__flash--info em { font-style: normal; font-weight: 600; }</style>";
}

try
{
    \IPS\Db::i()->update( 'core_theme_templates',
        [
            'template_data'    => '$wizardData,$values',
            'template_content' => $step5Tpl,
            'template_updated' => time(),
        ],
        [ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
          'gddealer', 'front', 'dealers', 'setupWizardStep5' ]
    );
}
catch ( \Throwable $e )
{
    try { \IPS\Log::log( 'templates_10160_part1.php update failed: ' . $e->getMessage(), 'gddealer_upg_10160' ); }
    catch ( \Throwable ) {}
}