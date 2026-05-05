<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.158 PART 2 of 4 - Step 5 CSS + persist setupWizardStep5 template.
 *
 * Must run after part 1 (which initializes $step5Tpl).
 * Uses INSERT then UPDATE pattern (template row may not exist yet).
 */

if ( !isset( $step5Tpl ) )
{
    throw new \RuntimeException( 'templates_10158_part2.php loaded before part1' );
}

$step5Tpl .= <<<'TEMPLATE_EOT'
<style>
.gdSetupWizard {
	font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	color: #0f172a;
	max-width: 1280px;
	margin: 0 auto;
	padding: 24px 16px 80px;
}
.gdSetupWizard *, .gdSetupWizard *::before, .gdSetupWizard *::after { box-sizing: border-box; }

.gdSetupWizard__header { margin-bottom: 24px; }
.gdSetupWizard__header h2 { margin: 0 0 8px; font-size: 1.65em; font-weight: 700; }
.gdSetupWizard__header p { margin: 0; color: #64748b; max-width: 700px; }

.gdSetupWizard__progress { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 24px; }
.gdSetupWizard__steps { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin: 0; padding: 0; list-style: none; }
.gdSetupWizard__step { display: flex; flex-direction: column; gap: 4px; padding: 10px 12px; border-radius: 8px; background: #fff; border: 1px solid #e2e8f0; }
.gdSetupWizard__step.is-current { background: #eff6ff; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
.gdSetupWizard__step.is-done { background: #f0fdf4; border-color: #86efac; }
.gdSetupWizard__step.is-upcoming { opacity: 0.65; }
.gdSetupWizard__stepNum { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #cbd5e1; color: #fff; font-size: 11px; font-weight: 700; }
.gdSetupWizard__step.is-current .gdSetupWizard__stepNum { background: #2563eb; }
.gdSetupWizard__step.is-done .gdSetupWizard__stepNum { background: #16a34a; }
.gdSetupWizard__stepLabel { font-size: 13px; font-weight: 600; }
.gdSetupWizard__stepDesc { font-size: 11.5px; color: #64748b; }

.gdSetupWizard__flash { border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; font-size: 14px; }
.gdSetupWizard__flash strong { display: block; margin-bottom: 4px; }
.gdSetupWizard__flash--error { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }
.gdSetupWizard__flash--error ul { margin: 4px 0 0; padding-left: 20px; }
.gdSetupWizard__flash--error li { margin: 2px 0; }

.gdSetupWizard__card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
.gdSetupWizard__card h3 { margin: 0 0 8px; font-size: 1.1em; font-weight: 700; }
.gdSetupWizard__card h4 { margin: 0 0 12px; font-size: 1em; font-weight: 600; color: #1e40af; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
.gdSetupWizard__card p { margin: 0 0 12px; color: #475569; font-size: 14px; }
.gdSetupWizard__card p:last-child { margin-bottom: 0; }
.gdSetupWizard__card--info { background: #eff6ff; border-color: #93c5fd; }
.gdSetupWizard__card--info h4 { color: #1e3a8a; border-bottom-color: #bfdbfe; }
.gdSetupWizard__card--info p { color: #1e40af; }

.gdSetupWizard__nextSteps { margin: 0; padding-left: 22px; font-size: 14px; color: #1e40af; }
.gdSetupWizard__nextSteps li { margin: 6px 0; line-height: 1.5; }

.gdSetupWizard__summaryTable, .gdSetupWizard__previewTable { width: 100%; border-collapse: collapse; font-size: 13px; }
.gdSetupWizard__summaryTable th, .gdSetupWizard__previewTable th {
	text-align: left; padding: 8px 10px; background: #f8fafc; font-weight: 600; color: #334155;
	font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.4px;
}
.gdSetupWizard__summaryTable td, .gdSetupWizard__previewTable td {
	padding: 10px; border-top: 1px solid #f1f5f9; vertical-align: top;
}

.gdSetupWizard__summaryTable code {
	font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px;
	background: #f1f5f9; color: #1e40af; padding: 2px 7px; border-radius: 3px; font-weight: 600;
}
.gdSetupWizard__canonLabel { font-size: 11.5px; color: #64748b; margin-top: 2px; }

.gdSetupWizard__reqBadge {
	display: inline-block; padding: 1px 7px; border-radius: 10px;
	font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;
	margin-left: 6px;
}
.gdSetupWizard__reqBadge--required { background: #fee2e2; color: #991b1b; }
.gdSetupWizard__reqBadge--conditional { background: #fef3c7; color: #92400e; }

.gdSetupWizard__srcBadge {
	display: inline-block; padding: 3px 10px; border-radius: 12px;
	font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;
}
.gdSetupWizard__src--feed { background: #dcfce7; color: #14532d; }
.gdSetupWizard__src--default { background: #ede9fe; color: #6d28d9; }
.gdSetupWizard__src--none { background: #f1f5f9; color: #64748b; }

.gdSetupWizard__sample {
	font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 11.5px;
	background: #f1f5f9; color: #475569; padding: 3px 8px; border-radius: 4px;
	display: inline-block; max-width: 240px;
	overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle;
}
.gdSetupWizard__sample--default { background: #ede9fe; color: #6d28d9; }
.gdSetupWizard__samplePlaceholder { color: #cbd5e1; font-size: 13px; }

.gdSetupWizard__previewWrap { overflow-x: auto; }
.gdSetupWizard__previewTable { min-width: 100%; }
.gdSetupWizard__previewTable td { white-space: nowrap; }

.gdSetupWizard__statusBadge {
	display: inline-block; padding: 3px 10px; border-radius: 12px;
	font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;
}
.gdSetupWizard__status--ok { background: #dcfce7; color: #14532d; }
.gdSetupWizard__status--warn { background: #fef3c7; color: #92400e; }
.gdSetupWizard__status--error { background: #fee2e2; color: #991b1b; }

.gdSetupWizard__step5Stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 12px; }
.gdSetupWizard__stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; display: flex; flex-direction: column; gap: 2px; }
.gdSetupWizard__stat--good { background: #f0fdf4; border-color: #86efac; }
.gdSetupWizard__stat--good .gdSetupWizard__statNum { color: #14532d; }
.gdSetupWizard__stat--bad { background: #fef2f2; border-color: #fca5a5; }
.gdSetupWizard__stat--bad .gdSetupWizard__statNum { color: #991b1b; }
.gdSetupWizard__stat--warn { background: #fffbeb; border-color: #fcd34d; }
.gdSetupWizard__stat--warn .gdSetupWizard__statNum { color: #92400e; }
.gdSetupWizard__statNum { font-size: 22px; font-weight: 700; color: #0f172a; }
.gdSetupWizard__statLabel { font-size: 12px; color: #64748b; }

.gdSetupWizard__warning {
	background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px;
	padding: 12px 16px; font-size: 13.5px; color: #78350f;
}
.gdSetupWizard__warning strong { color: #78350f; }
.gdSetupWizard__warning a { color: #78350f; text-decoration: underline; }

.gdSetupWizard__form { margin-top: 8px; }
.gdSetupWizard__actions { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
.gdSetupWizard__btn {
	padding: 12px 24px; border-radius: 8px; font-weight: 700; font-size: 15px;
	border: 1px solid transparent; text-decoration: none; cursor: pointer; transition: all 0.15s;
}
.gdSetupWizard__btn--primary { background: #16a34a; color: #fff; border-color: #16a34a; }
.gdSetupWizard__btn--primary:hover { background: #15803d; border-color: #15803d; }
.gdSetupWizard__btn--ghost { background: #fff; color: #475569; border-color: #cbd5e1; font-weight: 600; }
.gdSetupWizard__btn--ghost:hover { background: #f8fafc; color: #0f172a; }

@media (max-width: 1100px) {
	.gdSetupWizard__step5Stats { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 720px) {
	.gdSetupWizard { padding: 16px 12px 60px; }
	.gdSetupWizard__steps { grid-template-columns: 1fr 1fr; }
	.gdSetupWizard__step5Stats { grid-template-columns: 1fr; }
	.gdSetupWizard__summaryTable thead { display: none; }
	.gdSetupWizard__summaryTable tr { display: block; padding: 12px 0; border-top: 1px solid #f1f5f9; }
	.gdSetupWizard__summaryTable td { display: block; padding: 4px 10px; }
	.gdSetupWizard__actions { flex-direction: column-reverse; align-items: stretch; }
	.gdSetupWizard__actions .gdSetupWizard__btn { width: 100%; text-align: center; }
}
@media (max-width: 480px) { .gdSetupWizard__steps { grid-template-columns: 1fr; } }
</style>
TEMPLATE_EOT;

$templateData = '$wizardData,$values';

try
{
    \IPS\Db::i()->insert( 'core_theme_templates',
        [
            'template_set_id'   => 1,
            'template_app'      => 'gddealer',
            'template_location' => 'front',
            'template_group'    => 'dealers',
            'template_name'     => 'setupWizardStep5',
            'template_data'     => $templateData,
            'template_content'  => $step5Tpl,
            'template_updated'  => time(),
        ],
        TRUE
    );

    \IPS\Db::i()->update( 'core_theme_templates',
        [
            'template_data'    => $templateData,
            'template_content' => $step5Tpl,
            'template_updated' => time(),
        ],
        [ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
          'gddealer', 'front', 'dealers', 'setupWizardStep5' ]
    );
}
catch ( \Throwable $e )
{
    try { \IPS\Log::log( 'templates_10158_part2.php failed: ' . $e->getMessage(), 'gddealer_upg_10158' ); }
    catch ( \Throwable ) {}
}
