<?php
/**
 * GunRack Dealer Manager — v1.0.205 upgrade
 * Re-seeds unmatched template with text-align:right on Actions column
 */
namespace IPS\gddealer\setup\upg_10205;

class _upgrade
{
	public function step1(): bool
	{
		$unmatchedContent = <<<'TEMPLATE_EOT'
<div class="ipsBox" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px">
	<h3 class="ipsBox__header" style="margin:0;padding:14px 18px;border-bottom:1px solid var(--i-border-color,#f0f0f0);font-size:1em;font-weight:700">{lang="gddealer_front_tab_unmatched"}</h3>
	<div class="i-padding_2" style="padding:18px">

	<p>{lang="gddealer_front_unmatched_intro"}</p>

	<p style="margin:8px 0 16px 0">
		<a href="{$data['export_url']}" class="ipsButton ipsButton--normal ipsButton--small">{lang="gddealer_front_export_csv"}</a>
	</p>

	<div class="gdTableWrap">
	<table class="ipsTable ipsTable_zebra gdResponsiveTable" style="width:100%">
		<thead>
			<tr>
				<th>{lang="gddealer_unmatched_upc"}</th>
				<th>{lang="gddealer_unmatched_first_seen"}</th>
				<th>{lang="gddealer_unmatched_last_seen"}</th>
				<th>{lang="gddealer_unmatched_count"}</th>
				<th style="text-align:right">Actions</th>
			</tr>
		</thead>
		<tbody>
			{{foreach $data['rows'] as $r}}
			<tr>
				<td data-label="UPC"><code>{$r['upc']}</code></td>
				<td data-label="First Seen">{$r['first_seen']}</td>
				<td data-label="Last Seen">{$r['last_seen']}</td>
				<td data-label="Count">{$r['occurrence_count']}</td>
				<td data-label="Actions" style="white-space:nowrap;text-align:right">
					{{if $r['has_snapshot']}}
					<a href="{$r['snapshot_url']}" class="ipsButton ipsButton--small ipsButton--light" style="margin-right:4px">View Snapshot</a>
					{{endif}}
					{{if $r['already_reported']}}
					<span class="ipsButton ipsButton--small ipsButton--positive" style="margin-right:4px;cursor:default" title="Reported on {$r['dealer_reported_at']}">Reported</span>
					{{else}}
					<a href="{$r['report_url']}" class="ipsButton ipsButton--small ipsButton--primary" style="margin-right:4px" onclick="return confirm('Report this UPC to GunRack admins for review?')">Report to Admin</a>
					{{endif}}
					<a href="{$r['exclude_url']}" class="ipsButton ipsButton--small ipsButton--negative" onclick="return confirm('Exclude this UPC from your unmatched list?')">Exclude</a>
				</td>
			</tr>
			{{endforeach}}
			{{if count( $data['rows'] ) === 0}}
			<tr><td colspan="5" style="text-align:center;color:#999;padding:24px">{lang="gddealer_front_unmatched_empty"}</td></tr>
			{{endif}}
		</tbody>
	</table>
	</div>

	</div>
</div>
TEMPLATE_EOT;

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gddealer',
				'template_location'=> 'front',
				'template_group'   => 'dealers',
				'template_name'    => 'unmatched',
				'template_data'    => '$data',
				'template_content' => $unmatchedContent,
				'template_updated' => time(),
				'template_version' => '1.0.205',
			] );
		}
		catch ( \Throwable ) {}

		/* bust caches */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}

		try
		{
			foreach ( \IPS\Db::i()->select( 'store_key', 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ) as $key )
			{
				try { \IPS\Db::i()->delete( 'core_store', [ 'store_key=?', $key ] ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		$dsPath = \IPS\ROOT_PATH . '/datastore/';
		if ( is_dir( $dsPath ) )
		{
			foreach ( new \DirectoryIterator( $dsPath ) as $f )
			{
				if ( !$f->isDot() && ( str_starts_with( $f->getFilename(), 'theme_' ) || str_starts_with( $f->getFilename(), 'template_' ) ) )
				{
					@unlink( $f->getPathname() );
				}
			}
		}

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
