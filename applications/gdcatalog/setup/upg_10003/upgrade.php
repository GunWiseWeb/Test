<?php
namespace IPS\gdcatalog\setup\upg_10003;

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
		$newFeedListContent = $this->getFeedListTemplate();

		/* 1. Reseed feedList template (v1.0.2 had this but with wrong schema columns).
		 * IPS 5.0.18 core_theme_templates schema (verified): template_set_id, template_group,
		 * template_content, template_name, template_data, template_updated, template_master_key,
		 * template_location, template_app, template_version, template_has_hookpoints.
		 * NO template_user_edited / template_user_created / template_user_added columns. */
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
				'template_version'     => '1.0.3',
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.3 feedList reseed failed: ' . $e->getMessage(), 'gdcatalog_upg_10003' ); } catch ( \Throwable ) {}
			return FALSE;
		}

		/* 2. Insert new lang strings into core_sys_lang_words for every installed language.
		 * Per memory: IPS 5.0.18 schema uses ONLY 6 columns (lang_id, word_app, word_key,
		 * word_default, word_js, word_export). Per-row try/catch so one bad row doesn't
		 * poison the rest. */
		$newStrings = [
			'gdcatalog_feed_add'                   => 'Add Distributor',
			'gdcatalog_feed_priority'              => 'Priority',
			'gdcatalog_feed_priority_position'     => 'Priority Position',
			'gdcatalog_feed_distributor_slug'      => 'Distributor Slug',
			'gdcatalog_feed_distributor_label'     => 'Distributor Display Name',
			'gdcatalog_feed_added'                 => 'Distributor added. Configure feed URL and credentials, then activate.',
			'gdcatalog_feed_deleted'               => 'Distributor deleted.',
			'gdcatalog_feed_deleted_with_reassign' => 'Distributor deleted. %s catalog products reassigned and flagged for admin review.',
			'gdcatalog_feed_delete_confirm'        => 'Delete this distributor? Cascade: all conflict logs, locks, compliance flags, and import history will be removed. Catalog products using this distributor as primary source will be reassigned and flagged for admin review.',
			'gdcatalog_feed_slug_duplicate'        => 'A distributor with this slug already exists. Choose a unique slug.',
			'gdcatalog_drag_to_reorder'            => 'Drag to reorder priority',
			'gdcatalog_btn_edit'                   => 'Edit',
			'gdcatalog_btn_delete'                 => 'Delete',
		];

		try
		{
			$languages = \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' );
			foreach ( $languages as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdcatalog',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $rowException )
					{
						try { \IPS\Log::log( 'v1.0.3 lang seed row failed key=' . $key . ' lang_id=' . $langId . ': ' . $rowException->getMessage(), 'gdcatalog_upg_10003' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.3 lang seed outer failed: ' . $e->getMessage(), 'gdcatalog_upg_10003' ); } catch ( \Throwable ) {}
		}

		/* 3. Standard cache invalidation */
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
		return 'v1.0.3 - reseed feedList template + insert lang strings (fixes v1.0.2 schema bug)';
	}

	/**
	 * v1.0.3 feedList template content. Inlined per project rule #28 (no
	 * runtime extraction from other files). Adds drag handles, Add button,
	 * Delete buttons, and jQuery sortable JS for drag-and-drop reorder.
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

		<script>
		(function(){
			var table = document.querySelector('table[data-reorder-url]');
			if ( !table || typeof jQuery === 'undefined' || !jQuery.fn.sortable ) return;

			var url = table.getAttribute('data-reorder-url');
			jQuery(table).find('tbody.gdcatalog-sortable').sortable({
				handle: '.gdcatalog-drag-handle',
				placeholder: 'ipsTable--ghost',
				helper: function(e, tr) {
					var $originals = tr.children();
					var $helper = tr.clone();
					$helper.children().each(function(index){
						jQuery(this).width($originals.eq(index).width());
					});
					return $helper;
				},
				stop: function() {
					var ids = jQuery(table).find('tbody tr[data-feed-id]').map(function(){
						return this.getAttribute('data-feed-id');
					}).get();
					jQuery.ajax({
						url: url,
						method: 'POST',
						data: { ids: ids, csrfKey: ips.utils.csrfKey || '' },
						success: function() { window.location.reload(); }
					});
				}
			});

			document.querySelectorAll('a[data-confirm-message]').forEach(function(a){
				a.addEventListener('click', function(e){
					var msg = a.getAttribute('data-confirm-message');
					if ( !confirm(msg) ) e.preventDefault();
				});
			});
		})();
		</script>
		{{endif}}

	</div>
</div>
TEMPLATE_EOT;
	}
}

class upgrade extends _upgrade {}
