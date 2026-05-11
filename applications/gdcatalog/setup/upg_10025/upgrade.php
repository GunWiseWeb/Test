<?php
namespace IPS\gdcatalog\setup\upg_10025;

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
		/* gdcatalog v1.0.25 - Bake in accumulated hotfixes from v1.0.24 session.
		 *
		 * Fixes baked in:
		 *
		 * 1. tasks/ImportFeeds.php - execute() method signature corrected
		 *    to `execute(): mixed`. Without this, IPS 5 Task system throws
		 *    Fatal Error and ALL scheduled tasks halt (queue + cleanup + every
		 *    other task). Discovered when v1.0.24 background queue stalled
		 *    at chunk 13. Hotfixed via sed during the session.
		 *
		 * 2. tasks/AutoResolveConflicts.php - same `execute(): mixed`
		 *    fix. Was about to cause the same problem.
		 *
		 * 3. setup/install.php - replaced ipsButton--normal with
		 *    ipsButton--secondary in 3 locations (lines 373, 374, 616).
		 *    IPS 5.0.18 admin pages don't load `--normal`; only
		 *    `--primary`, `--secondary`, `--positive`, `--negative`,
		 *    `--soft`, `--text`. Plain `ipsButton` with no variant has
		 *    no styling. Without this fix, fresh installs will regress.
		 *
		 * 4. dev/html/admin/catalog/dashboard.phtml - line 40 button class
		 *    `ipsButton--normal` -> `ipsButton--secondary`.
		 *
		 * 5. dev/html/admin/catalog/compliancePanel.phtml - line 79 button
		 *    class `ipsButton--normal` -> `ipsButton--secondary`.
		 *
		 * 6. Reseed dashboard + feedList templates in core_theme_templates
		 *    with the CORRECT button classes. Live DB was hotfixed via SQL
		 *    during the session; this freezes the corrected state into the
		 *    upgrade so a future reseed (via reinstall or table truncation)
		 *    will produce the same correct output.
		 *
		 * No new features. No new tables. No new code paths. Pure cleanup
		 * ship to make v1.0.24 fully self-installing.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10024). */

		/* Step 1: Sanity check */
		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gdcatalog' ]
			)->first();

			$longVer = (int) ( $row['app_long_version'] ?? 0 );
			$msg = sprintf(
				'gdcatalog v1.0.25 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10025' ); } catch ( \Throwable ) {}

			if ( $longVer < 10024 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.25 WARNING: app_long_version=%d below 10024',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10025' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.25 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10025' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Reseed the dashboard template with the CORRECTED button
		 * classes (--secondary instead of --normal). This freezes the
		 * session's SQL hotfix into the upgrade so it persists across
		 * any future reinstalls.
		 *
		 * Content matches the live DB state confirmed via:
		 *   SELECT template_content FROM core_theme_templates
		 *   WHERE template_app='gdcatalog' AND template_name='dashboard' */
		$dashboardTemplate = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">

		<div style="display:flex;gap:16px;margin-bottom:24px">
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$totalProducts}</div>
				<div>Total Products</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$activeProducts}</div>
				<div>Active</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$reviewProducts}</div>
				<div>Pending Review</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$pendingConflicts}</div>
				<div>Open Conflicts</div>
			</div>
		</div>

		<h2 class="ipsType_sectionHead">Distributor Feeds</h2>
		{{if empty($distributorStats)}}
			<div class="ipsEmptyMessage"><p>No distributor feeds configured yet.</p></div>
		{{else}}
			<table class="ipsTable ipsTable_zebra" style="width:100%;margin-bottom:24px">
				<thead>
					<tr>
						<th>Feed</th>
						<th>Distributor</th>
						<th>Schedule</th>
						<th>Last Run</th>
						<th>Records</th>
						<th>Status</th>
						<th style="width:340px">Actions</th>
					</tr>
				</thead>
				<tbody>
				{{foreach $distributorStats as $ds}}
					<tr>
						<td><strong>{$ds['feed_name']}</strong></td>
						<td>{$ds['distributor_label']}</td>
						<td>{$ds['schedule_label']}</td>
						<td>{$ds['last_run_label']}</td>
						<td>{$ds['record_count']}</td>
						<td>
							{{if $ds['is_running']}}
								<span class="ipsBadge ipsBadge--warning">Running</span>
							{{elseif $ds['is_failed']}}
								<span class="ipsBadge ipsBadge--negative">Failed</span>
							{{else}}
								<span class="ipsBadge ipsBadge--positive">Idle</span>
							{{endif}}
						</td>
						<td>
							<a href="{$ds['run_import_url']}" class="ipsButton ipsButton--primary ipsButton--small" data-confirm>Run Import</a>
							{{if !empty($ds['is_sportssouth'])}}
							<a href="{$ds['queue_full_import_url']}" class="ipsButton ipsButton--secondary ipsButton--small" data-confirm title="Background task for full ~58k catalog. Runs in chunks via cron.">Queue Full Import</a>
							{{endif}}
						</td>
					</tr>
				{{endforeach}}
				</tbody>
			</table>
		{{endif}}

		<h2 class="ipsType_sectionHead">OpenSearch</h2>
		{{if !$osExists}}
			<div class="ipsMessage ipsMessage--warning"><p>OpenSearch index does not exist yet. <a href="{$osStats['rebuild_url']}" class="ipsButton ipsButton--primary ipsButton--small">Build Index Now</a></p></div>
		{{else}}
			<div class="ipsBox" style="padding:16px;margin-bottom:16px">
				<div>Indexed Documents: <strong>{$osStats['doc_count']}</strong></div>
				<div>Pending Reindex Queue: <strong>{$reindexQueue}</strong></div>
				<div style="margin-top:12px">
					<a href="{$osStats['rebuild_url']}" class="ipsButton ipsButton--secondary ipsButton--small" data-confirm>Rebuild Index</a>
					<a href="{$osStats['process_queue_url']}" class="ipsButton ipsButton--secondary ipsButton--small" data-confirm>Process Queue Now</a>
				</div>
			</div>
		{{endif}}

		<h2 class="ipsType_sectionHead">Compliance Locks</h2>
		<p>{$lockedFields} fields are currently locked.</p>

	</div>
</div>
TEMPLATE_EOT;

		/* Step 3: Reseed feedList template with Test Connection button
		 * using --secondary instead of --alternate.
		 *
		 * Content matches the LIVE feedList template post-hotfix. */
		$feedListTemplate = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">

		<p>Configure each distributor's feed URL, authentication, field mapping, and import schedule. Feeds are processed by the ImportFeeds background task.</p>

		<div style="margin-bottom:16px">
			<a href="{$addUrl}" class="ipsButton ipsButton--primary">+ Add Distributor</a>
		</div>

		{{if empty($feeds)}}
			<div class="ipsEmptyMessage"><p>No distributor feeds configured yet.</p></div>
		{{else}}
			<table class="ipsTable ipsTable_zebra ipsTable_reorder" data-controller="core.admin.core.table" data-reorderUrl="{$reorderUrl}" style="width:100%">
				<thead>
					<tr>
						<th style="width:40px"></th>
						<th style="width:60px">Priority</th>
						<th>Feed Name</th>
						<th>Distributor</th>
						<th>Feed Format</th>
						<th>Import Schedule</th>
						<th>Active</th>
						<th>Last Run</th>
						<th>Last Record Count</th>
						<th>Last Run Status</th>
						<th style="width:280px">Actions</th>
					</tr>
				</thead>
				<tbody>
				{{foreach $feeds as $feed}}
					<tr data-id="{$feed->id}">
						<td class="ipsTable_dragHandle" data-role="dragHandle"><i class="fa-solid fa-bars" aria-hidden="true"></i></td>
						<td><strong>{$feed->priority}</strong></td>
						<td><strong>{$feed->feed_name}</strong></td>
						<td>{$feed->distributorLabel()}</td>
						<td>{$feed->feed_format}</td>
						<td>{$feed->scheduleLabel()}</td>
						<td>
							{{if $feed->is_active}}
								<span class="ipsBadge ipsBadge--positive">{lang="gdcatalog_feed_active"}</span>
							{{else}}
								<span class="ipsBadge ipsBadge--neutral">{lang="gdcatalog_feed_inactive"}</span>
							{{endif}}
						</td>
						<td>
							{{if $feed->last_run}}
								{datetime="$feed->last_run"}
							{{else}}
								&mdash;
							{{endif}}
						</td>
						<td>{$feed->last_record_count}</td>
						<td>
							{{if $feed->last_run_status === 'running'}}
								<span class="ipsBadge ipsBadge--warning">{lang="gdcatalog_feed_status_running"}</span>
							{{elseif $feed->last_run_status === 'completed'}}
								<span class="ipsBadge ipsBadge--positive">{lang="gdcatalog_feed_status_completed"}</span>
							{{elseif $feed->last_run_status === 'failed'}}
								<span class="ipsBadge ipsBadge--negative">{lang="gdcatalog_feed_status_failed"}</span>
							{{else}}
								<span class="ipsBadge ipsBadge--neutral">&mdash;</span>
							{{endif}}
						</td>
						<td>
							<a href="{url="app=gdcatalog&module=catalog&controller=feeds&do=edit&id={$feed->id}" csrf="true"}" class="ipsButton ipsButton--small ipsButton--primary">{lang="gdcatalog_btn_edit"}</a>
							<a href="{url="app=gdcatalog&module=catalog&controller=feeds&do=delete&id={$feed->id}" csrf="true"}" class="ipsButton ipsButton--small ipsButton--negative" data-confirm data-confirmMessage='{lang="gdcatalog_feed_delete_confirm"}'>{lang="gdcatalog_btn_delete"}</a>
							{{if $feed['test_url']}}
							<a href='{$feed['test_url']}' class="ipsButton ipsButton--secondary ipsButton--small">{lang="gdcatalog_feed_test_connection"}</a>
							{{endif}}
						</td>
					</tr>
				{{endforeach}}
				</tbody>
			</table>
		{{endif}}

	</div>
</div>
TEMPLATE_EOT;

		$templatesToReseed = [
			[
				'name'    => 'dashboard',
				'group'   => 'catalog',
				'content' => $dashboardTemplate,
				'data'    => '$totalProducts, $activeProducts, $reviewProducts, $categoryCounts, $distributorStats, $osExists, $osStats, $pendingConflicts, $pendingCompliance, $lockedFields, $reindexQueue',
			],
			[
				'name'    => 'feedList',
				'group'   => 'catalog',
				'content' => $feedListTemplate,
				'data'    => '$feeds, $addUrl, $reorderUrl',
			],
		];

		foreach ( $templatesToReseed as $tpl )
		{
			try
			{
				$masterKey = md5( 'gdcatalog;admin;catalog;' . $tpl['name'] );

				\IPS\Db::i()->delete( 'core_theme_templates', [
					'template_app=? AND template_name=? AND template_location=?',
					'gdcatalog', $tpl['name'], 'admin'
				] );

				\IPS\Db::i()->insert( 'core_theme_templates', [
					'template_set_id'         => 1,
					'template_group'          => $tpl['group'],
					'template_content'        => $tpl['content'],
					'template_name'           => $tpl['name'],
					'template_data'           => $tpl['data'],
					'template_updated'        => time(),
					'template_master_key'     => $masterKey,
					'template_location'       => 'admin',
					'template_app'            => 'gdcatalog',
					'template_version'        => '1.0.25',
					'template_has_hookpoints' => 0,
				] );

				try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.25 reseeded template: %s', $tpl['name'] ), 'gdcatalog_upg_10025' ); } catch ( \Throwable ) {}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.25 reseed FAILED for %s: %s', $tpl['name'], $e->getMessage() ), 'gdcatalog_upg_10025' ); } catch ( \Throwable ) {}
			}
		}

		/* Step 4: Cache invalidation */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/*.php' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/static/templates/*.php' ) ?: [] as $f )
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
		return 'gdcatalog v1.0.25 - bake hotfixes (task signatures, button classes, template reseed)';
	}
}

class upgrade extends _upgrade {}
