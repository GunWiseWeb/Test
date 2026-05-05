<?php
if ( !defined( '\\IPS\\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/**
 * v1.0.158 PART 4 of 4 - feedSettings CSS + persist template.
 *
 * Must run after part 3. Uses UPDATE only (template row exists from
 * v74/v128/v129 prior writes).
 */

if ( !isset( $feedSettingsTpl ) )
{
    throw new \RuntimeException( 'templates_10158_part4.php loaded before part3' );
}

$feedSettingsTpl .= <<<'TEMPLATE_EOT'
<style>
.gdFeedSettings {
	font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	color: #0f172a;
	max-width: 1100px;
	margin: 0 auto;
	padding: 24px 16px 80px;
}
.gdFeedSettings *, .gdFeedSettings *::before, .gdFeedSettings *::after { box-sizing: border-box; }

.gdFeedSettings__flash { border-radius: 12px; padding: 18px 22px; margin-bottom: 20px; font-size: 14px; }
.gdFeedSettings__flash strong { display: block; margin-bottom: 4px; font-size: 1.1em; }
.gdFeedSettings__flash p { margin: 8px 0 0; }
.gdFeedSettings__flash a { font-weight: 600; }
.gdFeedSettings__flash--success { background: #f0fdf4; border: 1px solid #86efac; color: #14532d; }

.gdFeedSettings__guide {
	display: flex; gap: 12px; padding: 14px 16px; margin-bottom: 16px;
	background: #eff6ff; border: 1px solid #93c5fd; border-radius: 10px;
}
.gdFeedSettings__guideIcon { font-size: 22px; color: #2563eb; flex-shrink: 0; }
.gdFeedSettings__guideBody { flex: 1; font-size: 13.5px; color: #1e3a8a; }
.gdFeedSettings__guideBody strong { display: block; margin-bottom: 2px; }
.gdFeedSettings__guideBody p { margin: 0; }
.gdFeedSettings__guideBody a { color: #1e3a8a; font-weight: 600; text-decoration: underline; }

.gdFeedSettings__healthCard {
	border-radius: 12px; padding: 20px 24px; margin-bottom: 16px;
	border: 1px solid #e2e8f0; background: #fff;
}
.gdFeedSettings__healthCard--healthy { background: #f0fdf4; border-color: #86efac; }
.gdFeedSettings__healthCard--warn    { background: #fffbeb; border-color: #fcd34d; }
.gdFeedSettings__healthCard--error   { background: #fef2f2; border-color: #fca5a5; }
.gdFeedSettings__healthHeader h3 { margin: 0 0 4px; font-size: 1.1em; font-weight: 700; }
.gdFeedSettings__healthHeader p { margin: 0; color: #64748b; font-size: 13.5px; }
.gdFeedSettings__healthCard--healthy .gdFeedSettings__healthHeader h3 { color: #14532d; }
.gdFeedSettings__healthCard--warn    .gdFeedSettings__healthHeader h3 { color: #92400e; }
.gdFeedSettings__healthCard--error   .gdFeedSettings__healthHeader h3 { color: #991b1b; }

.gdFeedSettings__card {
	background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
	padding: 20px; margin-bottom: 16px;
}
.gdFeedSettings__card h4 { margin: 0 0 12px; font-size: 1em; font-weight: 600; color: #1e40af; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
.gdFeedSettings__card p { margin: 0 0 12px; color: #475569; font-size: 14px; }
.gdFeedSettings__card p:last-child { margin-bottom: 0; }

.gdFeedSettings__cardHead {
	display: flex; align-items: center; justify-content: space-between;
	margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0;
}
.gdFeedSettings__cardHead h4 { margin: 0; padding: 0; border: none; }

.gdFeedSettings__configTable { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.gdFeedSettings__configTable th {
	text-align: left; padding: 10px 12px 10px 0;
	font-weight: 600; color: #475569; width: 180px; vertical-align: top;
}
.gdFeedSettings__configTable td { padding: 10px 0; vertical-align: top; }
.gdFeedSettings__configTable tr { border-top: 1px solid #f1f5f9; }
.gdFeedSettings__configTable tr:first-child { border-top: none; }

.gdFeedSettings__pill {
	display: inline-block; padding: 3px 10px; border-radius: 12px;
	font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;
	margin-right: 6px;
}
.gdFeedSettings__pill--url { background: #dbeafe; color: #1e40af; }
.gdFeedSettings__pill--manual { background: #ede9fe; color: #6d28d9; }
.gdFeedSettings__pill--good { background: #dcfce7; color: #14532d; }
.gdFeedSettings__pill--warn { background: #fef3c7; color: #92400e; }
.gdFeedSettings__pill--bad { background: #fee2e2; color: #991b1b; }
.gdFeedSettings__pill--info { background: #dbeafe; color: #1e40af; }
.gdFeedSettings__pill--neutral { background: #f1f5f9; color: #475569; }

.gdFeedSettings__hint { color: #64748b; font-size: 12px; margin-left: 4px; }

.gdFeedSettings__code {
	font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px;
	background: #f1f5f9; color: #1e40af; padding: 2px 8px; border-radius: 3px;
}
.gdFeedSettings__code--url {
	max-width: 100%; display: inline-block;
	overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle;
}

.gdFeedSettings__missing { color: #b45309; font-size: 13px; font-style: italic; }
.gdFeedSettings__missing a { color: #b45309; font-weight: 600; }

.gdFeedSettings__uploadForm { margin-top: 8px; }
.gdFeedSettings__uploadDrop {
	position: relative; padding: 32px 20px; border: 2px dashed #cbd5e1; border-radius: 10px;
	background: #f8fafc; text-align: center; cursor: pointer; transition: all 0.15s;
}
.gdFeedSettings__uploadDrop:hover { border-color: #2563eb; background: #eff6ff; }
.gdFeedSettings__uploadDrop input[type=file] {
	position: absolute; inset: 0; opacity: 0; cursor: pointer;
}
.gdFeedSettings__uploadLabel { display: flex; flex-direction: column; gap: 8px; align-items: center; color: #475569; cursor: pointer; }
.gdFeedSettings__uploadIcon { font-size: 32px; color: #94a3b8; }
.gdFeedSettings__uploadActions { margin-top: 12px; display: flex; justify-content: flex-end; }

.gdFeedSettings__listTable { width: 100%; border-collapse: collapse; font-size: 13px; }
.gdFeedSettings__listTable th {
	text-align: left; padding: 8px 10px; background: #f8fafc; font-weight: 600; color: #334155;
	font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.4px;
}
.gdFeedSettings__listTable td { padding: 10px; border-top: 1px solid #f1f5f9; }

.gdFeedSettings__btn {
	padding: 8px 16px; border-radius: 8px; font-weight: 600; font-size: 13.5px;
	border: 1px solid transparent; text-decoration: none; cursor: pointer; transition: all 0.15s;
	display: inline-block;
}
.gdFeedSettings__btn--primary { background: #2563eb; color: #fff; border-color: #2563eb; }
.gdFeedSettings__btn--primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
.gdFeedSettings__btn--ghost { background: #fff; color: #475569; border-color: #cbd5e1; }
.gdFeedSettings__btn--ghost:hover { background: #f8fafc; color: #0f172a; }

@media (max-width: 720px) {
	.gdFeedSettings { padding: 16px 12px 60px; }
	.gdFeedSettings__cardHead { flex-direction: column; align-items: flex-start; gap: 8px; }
	.gdFeedSettings__configTable th { width: auto; display: block; padding-bottom: 4px; }
	.gdFeedSettings__configTable td { display: block; padding-top: 0; padding-bottom: 12px; }
}
</style>
TEMPLATE_EOT;

try
{
    \IPS\Db::i()->update( 'core_theme_templates',
        [
            'template_data'    => '$data',
            'template_content' => $feedSettingsTpl,
            'template_updated' => time(),
        ],
        [ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
          'gddealer', 'front', 'dealers', 'feedSettings' ]
    );
}
catch ( \Throwable $e )
{
    try { \IPS\Log::log( 'templates_10158_part4.php failed: ' . $e->getMessage(), 'gddealer_upg_10158' ); }
    catch ( \Throwable ) {}
}
