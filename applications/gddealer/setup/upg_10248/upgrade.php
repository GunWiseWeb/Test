<?php

namespace IPS\gddealer\setup\upg_10248;

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
		$newStrings = [
			'gddealer_flags_reevaluate'  => 'Re-evaluate Flags',
			'gddealer_flags_clear_all'   => 'Clear All Flags',
			'gddealer_flags_reevaluated' => 'Flags re-evaluated. False positives auto-resolved.',
			'gddealer_flags_cleared'     => 'All flags cleared.',
		];

		try {
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gddealer',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		} catch ( \Throwable ) {}

		$templateContent = <<<'TEMPLATE_EOT'
<ips:template parameters="$flags, $total, $pagination, $statusFilter, $dealerFilter, $typeFilter, $counts, $reEvalUrl='', $clearAllUrl=''" />
<div class="ipsBox">
	<div class="ipsBox__header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
		<h1 class="ipsType_pageTitle">{lang="gddealer_flagged_upcs_title"}</h1>
		<div style="display:flex;gap:8px;font-size:0.85em;align-items:center;flex-wrap:wrap">
			<a href="?app=gddealer&module=dealers&controller=flaggedUpcs&flag_status=submitted" class="ipsBadge {{if $statusFilter === 'submitted'}}ipsBadge--warning{{else}}ipsBadge--neutral{{endif}}">{lang="gddealer_flag_status_submitted"} ({$counts['submitted']})</a>
			<a href="?app=gddealer&module=dealers&controller=flaggedUpcs&flag_status=new" class="ipsBadge {{if $statusFilter === 'new'}}ipsBadge--negative{{else}}ipsBadge--neutral{{endif}}">{lang="gddealer_flag_status_new"} ({$counts['new']})</a>
			<a href="?app=gddealer&module=dealers&controller=flaggedUpcs&flag_status=resolved" class="ipsBadge {{if $statusFilter === 'resolved'}}ipsBadge--positive{{else}}ipsBadge--neutral{{endif}}">{lang="gddealer_flag_status_resolved"} ({$counts['resolved']})</a>
			<a href="?app=gddealer&module=dealers&controller=flaggedUpcs&flag_status=dismissed" class="ipsBadge {{if $statusFilter === 'dismissed'}}ipsBadge--neutral{{else}}ipsBadge--neutral{{endif}}">{lang="gddealer_flag_status_dismissed"} ({$counts['dismissed']})</a>
			<a href="?app=gddealer&module=dealers&controller=flaggedUpcs&flag_status=all" class="ipsBadge ipsBadge--neutral">{lang="gddealer_flag_status_all"}</a>
			{{if $reEvalUrl}}
			<a href="{$reEvalUrl}" class="ipsButton ipsButton--small ipsButton--primary" onclick="return confirm('Re-evaluate all open flags with updated matching rules?')">{lang="gddealer_flags_reevaluate"}</a>
			{{endif}}
			{{if $clearAllUrl}}
			<a href="{$clearAllUrl}" class="ipsButton ipsButton--small ipsButton--negative" onclick="return confirm('Delete ALL flags? This cannot be undone.')">{lang="gddealer_flags_clear_all"}</a>
			{{endif}}
		</div>
	</div>
	<div class="ipsBox__content">
		<p style="color:#666;margin:0 0 16px">{$total} {lang="gddealer_flagged_upcs_count"}</p>
		{{if count($flags) === 0}}
		<p style="text-align:center;color:#999;padding:32px 0">{lang="gddealer_flagged_upcs_empty"}</p>
		{{else}}
		<table class="ipsTable ipsTable_zebra">
			<thead>
				<tr>
					<th>{lang="gddealer_flag_col_dealer"}</th>
					<th>{lang="gddealer_flag_col_upc"}</th>
					<th>{lang="gddealer_flag_col_type"}</th>
					<th>{lang="gddealer_flag_col_dealer_value"}</th>
					<th>{lang="gddealer_flag_col_catalog_value"}</th>
					<th>{lang="gddealer_flag_col_status"}</th>
					<th>{lang="gddealer_flag_col_submitted"}</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				{{foreach $flags as $flag}}
				<tr>
					<td>{$flag['dealer_name']}</td>
					<td>
						<code style="font-size:0.85em">{$flag['upc']}</code>
						{{if $flag['catalog_title']}}
						<div style="font-size:0.8em;color:#666">{$flag['catalog_title']}</div>
						{{endif}}
					</td>
					<td><span class="ipsBadge ipsBadge--neutral">{$flag['flag_type']}</span></td>
					<td style="color:#dc2626;font-weight:500">{$flag['dealer_value']}</td>
					<td style="color:#16a34a">{$flag['catalog_value']}</td>
					<td>
						{{if $flag['status'] === 'new'}}
						<span class="ipsBadge ipsBadge--negative">{lang="gddealer_flag_status_new"}</span>
						{{elseif $flag['status'] === 'submitted'}}
						<span class="ipsBadge ipsBadge--warning">{lang="gddealer_flag_status_submitted"}</span>
						{{elseif $flag['status'] === 'resolved'}}
						<span class="ipsBadge ipsBadge--positive">{lang="gddealer_flag_status_resolved"}</span>
						{{elseif $flag['status'] === 'dismissed'}}
						<span class="ipsBadge ipsBadge--neutral">{lang="gddealer_flag_status_dismissed"}</span>
						{{endif}}
					</td>
					<td style="font-size:0.85em;color:#666">{$flag['submitted_at']}</td>
					<td>
						<a href="{$flag['detail_url']}" class="ipsButton ipsButton--small ipsButton--primary">{lang="gddealer_flag_review"}</a>
					</td>
				</tr>
				{{endforeach}}
			</tbody>
		</table>
		{{if $pagination}}
		<div style="margin-top:16px">{$pagination|raw}</div>
		{{endif}}
		{{endif}}
	</div>
</div>
TEMPLATE_EOT;

		try {
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 0,
				'template_app'     => 'gddealer',
				'template_location' => 'admin',
				'template_group'   => 'dealers',
				'template_name'    => 'flaggedUpcs',
				'template_data'    => '$flags, $total, $pagination, $statusFilter, $dealerFilter, $typeFilter, $counts, $reEvalUrl=\'\', $clearAllUrl=\'\'',
				'template_content' => $templateContent,
				'template_updated' => time(),
				'template_version' => '1.0.248',
			] );
		} catch ( \Throwable ) {}

		require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		\IPS\gddealer\Setup\CanonicalTemplates::ensure();
		\IPS\gddealer\Setup\CanonicalTemplates::clearCaches();

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
