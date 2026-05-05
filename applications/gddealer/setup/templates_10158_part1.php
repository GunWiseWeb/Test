<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.158 PART 1 of 4 - HTML body of setupWizardStep5.
 *
 * Preview & Save - the wizard finale. Shows:
 *   - Field mapping summary (canonical -> source)
 *   - 5-record canonical preview
 *   - Validation reminder
 *   - Finish button (always enabled - wizard configures, doesn't enforce)
 *
 * Initializes $step5Tpl. Part 2 appends CSS + writes to DB.
 */

$step5Tpl = <<<'TEMPLATE_EOT'
<div class="gdSetupWizard">

	<header class="gdSetupWizard__header">
		<div class="gdSetupWizard__heading">
			<h2>Feed Setup Wizard</h2>
			<p>You're almost done! Review your configuration below, then hit Finish to lock it in. You can always come back and re-run the wizard to reconfigure.</p>
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
		<h3>Step 5 of {$wizardData['totalSteps']}: Preview &amp; Save</h3>
		<p>Below is a summary of how your feed will be imported. Review the field mapping, peek at how the first records would look once normalized, and then hit Finish.</p>
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

	<section class="gdSetupWizard__card gdSetupWizard__card--info">
		<h4>What happens after Finish?</h4>
		<ul class="gdSetupWizard__nextSteps">
			<li>Your feed configuration is saved and the import scheduler will pick it up on its next run.</li>
			<li>You'll land on the <strong>Feed Settings</strong> page where you can upload feed files (manual mode) or check your import history.</li>
			<li>You can re-run this wizard anytime from the sidebar to reconfigure from scratch.</li>
		</ul>
	</section>

	<form method="post" action="{$values['urls']['save_step5']}" class="gdSetupWizard__form" data-step="5">
		<input type="hidden" name="csrfKey" value="{$values['csrfKey']}">

		<div class="gdSetupWizard__actions">
			<a href="{$values['urls']['step4']}" class="gdSetupWizard__btn gdSetupWizard__btn--ghost">&larr; Back to Step 4</a>
			<button type="submit" class="gdSetupWizard__btn gdSetupWizard__btn--primary">&#10003; Finish Setup</button>
		</div>
	</form>

</div>
TEMPLATE_EOT;
