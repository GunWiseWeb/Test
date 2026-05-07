<?php
namespace IPS\gdcatalog\setup\upg_10004;

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
		/* v1.0.4: reseed feedList template to drop the inline <script> block.
		 *
		 * The v1.0.3 template embedded a <script> with JS variables named
		 * $originals and $helper. IPS's template engine interpreted those
		 * as template variables (since they start with $) and replaced them
		 * with empty strings during template compilation. Result: the JS
		 * shipped to browsers had `var  = tr.children();` (no variable name)
		 * which is a syntax error, killing the entire script and breaking
		 * drag-and-drop.
		 *
		 * Fix: move the JS to a static file (dev/js/admin/feedSort.js) loaded
		 * by the controller via Output::i()->js(). This template now contains
		 * only the data-* attributes the JS reads.
		 */
		$newFeedListContent = $this->getFeedListTemplate();

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'      => 1,
				'template_app'         => 'gdcatalog',
				'template_location'    => 'admin',
				'template_group'       => 'catalog',
				'template_name'        => 'feedList',
				'template_data'        => '$feeds, $feedCounts, $addUrl, $reorderUrl',
				'template_content'     => $newFeedListContent,
				'template_master_key'  => md5( 'gdcatalog;admin;catalog;feedList' ),
				'template_updated'     => time(),
				'template_version'     => '1.0.4',
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.4 feedList reseed failed: ' . $e->getMessage(), 'gdcatalog_upg_10004' ); } catch ( \Throwable ) {}
			return FALSE;
		}

		/* Cache invalidation */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'lang_%'" ] ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*catalog*' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/lang_*' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'v1.0.4 - reseed feedList template (drop inline script, controller now enqueues dev/js/admin/feedSort.js)';
	}

	/**
	 * v1.0.4 feedList template content - no inline <script> block.
	 * The drag-and-drop JS lives in dev/js/admin/feedSort.js and is
	 * enqueued by the manage() controller action.
	 */
	protected function getFeedListTemplate(): string
	{
		return <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">

		<div style="display:flex;gap:16px;margin-bottom:24px">
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$feedCounts['total']}</div>
				<div>Configured Feeds</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$feedCounts['active']}</div>
				<div>Active Feeds</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$feedCounts['urls']}</div>
				<div>URLs Configured</div>
			</div>
		</div>

		<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
			<p class="ipsType_light" style="margin:0">{lang="gdcatalog_feeds_help"}</p>
			<a href='{$addUrl}' class="ipsButton ipsButton--primary">
				<i class="fa fa-plus"></i> {lang="gdcatalog_feed_add"}
			</a>
		</div>

		{{if count($feeds) === 0}}
			<div class="ipsEmptyMessage"><p>{lang="gdcatalog_feeds_empty"}</p></div>
		{{else}}
		<table class="ipsTable ipsTable_zebra" style="width:100%" data-controller="gdcatalog.admin.catalog.feedSort" data-reorder-url='{$reorderUrl}'>
			<thead>
				<tr>
					<th style="width:30px"></th>
					<th style="width:50px">{lang="gdcatalog_feed_priority"}</th>
					<th>{lang="gdcatalog_feed_name"}</th>
					<th>{lang="gdcatalog_feed_distributor"}</th>
					<th>{lang="gdcatalog_feed_format"}</th>
					<th>{lang="gdcatalog_feed_schedule"}</th>
					<th>{lang="gdcatalog_feed_active"}</th>
					<th>{lang="gdcatalog_feed_last_run"}</th>
					<th>{lang="gdcatalog_feed_last_count"}</th>
					<th>{lang="gdcatalog_feed_last_status"}</th>
					<th style="width:160px">Actions</th>
				</tr>
			</thead>
			<tbody class="gdcatalog-sortable">
				{{foreach $feeds as $feed}}
				<tr data-feed-id='{$feed['id']}'>
					<td style="cursor:move;text-align:center;color:#999" class="gdcatalog-drag-handle" title='{lang="gdcatalog_drag_to_reorder"}'>
						<i class="fa fa-bars"></i>
					</td>
					<td><strong>{$feed['priority']}</strong></td>
					<td><strong>{$feed['feed_name']}</strong></td>
					<td>{$feed['distributor_label']}</td>
					<td>{$feed['feed_format']}</td>
					<td>{$feed['import_schedule']}</td>
					<td>
						{{if $feed['active']}}
							<span class="ipsBadge ipsBadge--positive">{lang="gdcatalog_feed_active"}</span>
						{{else}}
							<span class="ipsBadge ipsBadge--neutral">Inactive</span>
						{{endif}}
					</td>
					<td>
						{{if $feed['last_run']}}
							{$feed['last_run']}
						{{else}}
							&mdash;
						{{endif}}
					</td>
					<td>{expression="number_format( $feed['last_record_count'] )"}</td>
					<td>
						{{if $feed['last_run_status'] === 'completed'}}
							<span class="ipsBadge ipsBadge--positive">OK</span>
						{{elseif $feed['last_run_status'] === 'failed'}}
							<span class="ipsBadge ipsBadge--negative">Failed</span>
						{{elseif $feed['last_run_status'] === 'running'}}
							<span class="ipsBadge ipsBadge--warning">Running</span>
						{{else}}
							&mdash;
						{{endif}}
					</td>
					<td>
						<a href='{$feed['edit_url']}' class="ipsButton ipsButton--primary ipsButton--small">{lang="gdcatalog_btn_edit"}</a>
						<a href='{$feed['delete_url']}' class="ipsButton ipsButton--negative ipsButton--small" data-confirm-message='{lang="gdcatalog_feed_delete_confirm"}'>{lang="gdcatalog_btn_delete"}</a>
					</td>
				</tr>
				{{endforeach}}
			</tbody>
		</table>
		{{endif}}

	</div>
</div>
TEMPLATE_EOT;
	}
}

class upgrade extends _upgrade {}
