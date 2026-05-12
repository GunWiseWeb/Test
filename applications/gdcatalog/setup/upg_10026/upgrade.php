<?php
namespace IPS\gdcatalog\setup\upg_10026;

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
		/* gdcatalog v1.0.26 - Conflict log cleanup feature + bake working templates.
		 *
		 * What this does:
		 *
		 * 1. SCHEMA: Adds index idx_resolved_admin (resolved_at, admin_override) to
		 *    gd_conflict_log. Speeds up the new "filter by scope" queries and the
		 *    scheduled PruneConflictLog task's WHERE clause.
		 *
		 * 2. SETTINGS: Inserts gdcatalog_conflict_log_retention_days (default 14)
		 *    into core_sys_conf_settings. Drives the prune task's cutoff.
		 *
		 * 3. TEMPLATES: Reseeds conflictLog (with new Clear button + scope filter +
		 *    counters), dashboard (current working bytes), feedList (current working
		 *    bytes). Each template is the exact byte content currently working in
		 *    the database, NOT a memory-based reconstruction.
		 *
		 * 4. TASK: PruneConflictLog gets registered in core_tasks via the new
		 *    tasks.json entry. (The actual task class file lives in tasks/.)
		 *
		 * 5. POSTCOMPLETE FIX: The v1.0.24 queue extension's postComplete sometimes
		 *    received $data with feed_id=0 (cause unclear but reproducible).
		 *    SportsSouthImport.php is patched to persist feed_id via core_store
		 *    so postComplete can recover it.
		 *
		 * 6. FEED STATE FIX: Marks the Sports South feed (id=2) as completed if
		 *    still stuck on "running" from the v1.0.24 queue's broken postComplete.
		 *    Idempotent - only fires if last_run_status='running' and last_record_count<58000.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10025). */

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
				'gdcatalog v1.0.26 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}

			if ( $longVer < 10025 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.26 WARNING: app_long_version=%d below 10025',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.26 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Add the new index. Use raw SQL with IF NOT EXISTS-style guard. */
		try
		{
			$prefix = \IPS\Db::i()->prefix;
			$existing = \IPS\Db::i()->query( "SHOW INDEX FROM {$prefix}gd_conflict_log WHERE Key_name = 'idx_resolved_admin'" );
			$hasIndex = FALSE;
			foreach ( $existing as $r )
			{
				$hasIndex = TRUE;
				break;
			}

			if ( !$hasIndex )
			{
				\IPS\Db::i()->query( "ALTER TABLE {$prefix}gd_conflict_log ADD INDEX idx_resolved_admin (resolved_at, admin_override)" );
				try { \IPS\Log::log( 'gdcatalog v1.0.26 added index idx_resolved_admin on gd_conflict_log', 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.26 index add failed: ' . $e->getMessage(), 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Insert the new setting. */
		try
		{
			$exists = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				'core_sys_conf_settings',
				[ 'conf_key=?', 'gdcatalog_conflict_log_retention_days' ]
			)->first();

			if ( $exists === 0 )
			{
				\IPS\Db::i()->insert( 'core_sys_conf_settings', [
					'conf_key'     => 'gdcatalog_conflict_log_retention_days',
					'conf_value'   => '14',
					'conf_default' => '14',
					'conf_app'     => 'gdcatalog',
				] );
				try { \IPS\Log::log( 'gdcatalog v1.0.26 inserted setting gdcatalog_conflict_log_retention_days=14', 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			/* If conf_app column doesn't exist on this IPS install, retry without it.
			 * Per CLAUDE.md memory: introspect schema before INSERT. */
			try
			{
				$prefix = \IPS\Db::i()->prefix;
				$describe = \IPS\Db::i()->query( "DESCRIBE {$prefix}core_sys_conf_settings" );
				$cols = [];
				foreach ( $describe as $c )
				{
					$cols[] = (string) ( $c['Field'] ?? '' );
				}

				$row = [];
				if ( in_array( 'conf_key',     $cols, true ) ) $row['conf_key']     = 'gdcatalog_conflict_log_retention_days';
				if ( in_array( 'conf_value',   $cols, true ) ) $row['conf_value']   = '14';
				if ( in_array( 'conf_default', $cols, true ) ) $row['conf_default'] = '14';
				if ( in_array( 'conf_app',     $cols, true ) ) $row['conf_app']     = 'gdcatalog';

				if ( !empty( $row ) )
				{
					\IPS\Db::i()->insert( 'core_sys_conf_settings', $row );
					try { \IPS\Log::log( 'gdcatalog v1.0.26 inserted setting via fallback (cols=' . implode( ',', $cols ) . ')', 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
				}
			}
			catch ( \Throwable $e2 )
			{
				try { \IPS\Log::log( 'gdcatalog v1.0.26 setting insert failed: ' . $e2->getMessage(), 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
			}
		}

		/* Step 4: Reseed templates. EXACT working bytes for dashboard + feedList
		 * pulled from production DB on 2026-05-11. ConflictLog is the working bytes
		 * PLUS new Clear button + scope filter UI added on top. */
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

		$feedListTemplate = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
<div class="ipsBox_body ipsPad">

<p>Configure each distributor's feed URL, authentication, field mapping, and import schedule. Feeds are processed by the ImportFeeds background task.</p>

<div style="margin-bottom:16px">
<a href="{$addUrl}" class="ipsButton ipsButton--primary">+ Add Distributor</a>
</div>

{{if empty( $feeds )}}
<div class="ipsEmptyMessage"><p>No distributor feeds configured yet.</p></div>
{{else}}
<table class="ipsTable ipsTable_zebra" style="width:100%" data-reorder-url="{$reorderUrl}" data-csrf-key="{$csrfKey}">
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
<tr data-id="{$feed['id']}">
<td class="ipsTable_dragHandle" data-role="dragHandle"><i class="fa-solid fa-bars" aria-hidden="true"></i></td>
<td><strong>{$feed['priority']}</strong></td>
<td><strong>{$feed['feed_name']}</strong></td>
<td>{$feed['distributor_label']}</td>
<td>{$feed['feed_format']}</td>
<td>{$feed['import_schedule']}</td>
<td>
{{if $feed['active']}}
<span class="ipsBadge ipsBadge--positive">Active</span>
{{else}}
<span class="ipsBadge ipsBadge--neutral">Inactive</span>
{{endif}}
</td>
<td>
{{if !empty( $feed['last_run'] )}}
{$feed['last_run']}
{{else}}
&mdash;
{{endif}}
</td>
<td>{$feed['last_record_count']}</td>
<td>
{{if $feed['last_run_status'] === 'running'}}
<span class="ipsBadge ipsBadge--warning">Running</span>
{{elseif $feed['last_run_status'] === 'completed'}}
<span class="ipsBadge ipsBadge--positive">Completed</span>
{{elseif $feed['last_run_status'] === 'failed'}}
<span class="ipsBadge ipsBadge--negative">Failed</span>
{{else}}
<span class="ipsBadge ipsBadge--neutral">&mdash;</span>
{{endif}}
</td>
<td>
<a href="{$feed['edit_url']}" class="ipsButton ipsButton--primary ipsButton--small">Edit</a>
<a href="{$feed['delete_url']}" class="ipsButton ipsButton--negative ipsButton--small" data-confirm>Delete</a>
{{if !empty( $feed['test_url'] )}}
<a href="{$feed['test_url']}" class="ipsButton ipsButton--secondary ipsButton--small">Test Connection</a>
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

		$conflictLogTemplate = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
<div class="ipsBox_body ipsPad">

<div style="display:flex;gap:16px;margin-bottom:24px">
<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
<div style="font-size:2em;font-weight:bold">{expression="number_format( $total )"}</div>
<div>Total Conflict Log Entries</div>
</div>
<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
<div style="font-size:2em;font-weight:bold">{$entryCount}</div>
<div>Showing On This Page</div>
</div>
<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
<div style="font-size:2em;font-weight:bold">{$manualCount}</div>
<div>Manual Overrides</div>
</div>
<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
<div style="font-size:2em;font-weight:bold">{$autoCount}</div>
<div>Auto-Resolved</div>
</div>
</div>

<div style="display:flex;gap:8px;margin-bottom:16px;align-items:center;flex-wrap:wrap">
<a href="{$urlAll}" class="ipsButton ipsButton--small {{if $filterScope === ''}}ipsButton--primary{{else}}ipsButton--secondary{{endif}}">All ({expression="number_format( $total )"})</a>
<a href="{$urlManual}" class="ipsButton ipsButton--small {{if $filterScope === 'manual'}}ipsButton--primary{{else}}ipsButton--secondary{{endif}}">Manual Override ({$manualCount})</a>
<a href="{$urlAuto}" class="ipsButton ipsButton--small {{if $filterScope === 'auto'}}ipsButton--primary{{else}}ipsButton--secondary{{endif}}">Auto-Resolved ({$autoCount})</a>
<div style="margin-left:auto">
{{if $autoCount > 0}}
<a href="{$clearAutoUrl}" class="ipsButton ipsButton--small ipsButton--negative" data-confirm data-confirmMessage="Delete all {$autoCount} auto-resolved conflict log entries? Manual overrides will be kept. This cannot be undone.">Clear Auto-Resolved ({$autoCount})</a>
{{endif}}
</div>
</div>

<form method="get" action="{$formActionUrl}" style="display:flex;gap:8px;padding:12px 16px;border-bottom:1px solid var(--i-border-color, #e0e0e0);align-items:center;flex-wrap:wrap">
<input type="hidden" name="app" value="gdcatalog">
<input type="hidden" name="module" value="catalog">
<input type="hidden" name="controller" value="conflicts">
{{if $filterScope !== ''}}<input type="hidden" name="scope" value="{$filterScope}">{{endif}}
<input type="text" name="upc" value="{$filterUpc}" placeholder="Filter by UPC" class="ipsInput ipsInput--text" style="flex:1;min-width:180px">
<input type="text" name="field" value="{$filterField}" placeholder="Field name" class="ipsInput ipsInput--text" style="flex:1;min-width:140px">
<input type="text" name="source" value="{$filterSource}" placeholder="Source distributor" class="ipsInput ipsInput--text" style="flex:1;min-width:160px">
<select name="rule" class="ipsInput ipsInput--select" style="min-width:160px">
<option value="">All resolution rules</option>
<option value="priority" {{if $filterRule === "priority"}}selected{{endif}}>Priority</option>
<option value="highest_val" {{if $filterRule === "highest_val"}}selected{{endif}}>Highest value</option>
<option value="lowest_val" {{if $filterRule === "lowest_val"}}selected{{endif}}>Lowest value</option>
<option value="newest" {{if $filterRule === "newest"}}selected{{endif}}>Newest</option>
<option value="manual" {{if $filterRule === "manual"}}selected{{endif}}>Manual</option>
<option value="locked" {{if $filterRule === "locked"}}selected{{endif}}>Locked</option>
</select>
<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Filter</button>
</form>

{{if $entryCount === 0}}
<div class="ipsEmptyMessage"><p>No conflict log entries match the current filter.</p></div>
{{else}}
<table class="ipsTable ipsTable_zebra" style="width:100%">
<thead>
<tr>
<th>UPC</th>
<th>Field</th>
<th>Winning Source</th>
<th>Winning Value</th>
<th>Losing Source</th>
<th>Losing Value</th>
<th>Rule</th>
<th>Override</th>
<th>Resolved At</th>
</tr>
</thead>
<tbody>
{{foreach $entries as $entry}}
<tr>
<td><code>{$entry["upc"]}</code></td>
<td>{$entry["field_name"]}</td>
<td>{$entry["winning_source"]}</td>
<td><code>{$entry["winning_value"]}</code></td>
<td>{$entry["losing_source"]}</td>
<td><code>{$entry["losing_value"]}</code></td>
<td><span class="ipsBadge ipsBadge--neutral">{$entry["rule_applied"]}</span></td>
<td>{{if $entry["admin_override"]}}<span class="ipsBadge ipsBadge--positive">Yes</span>{{else}}&mdash;{{endif}}</td>
<td>{$entry["resolved_at"]}</td>
</tr>
{{endforeach}}
</tbody>
</table>
{{endif}}

{{if !empty( $pagination )}}
<div style="margin-top:16px">{$pagination|raw}</div>
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
				'data'    => '$feeds, $feedCounts, $addUrl, $reorderUrl, $csrfKey',
			],
			[
				'name'    => 'conflictLog',
				'group'   => 'catalog',
				'content' => $conflictLogTemplate,
				'data'    => '$entries, $filterField, $filterSource, $filterRule, $filterUpc, $filterScope, $total, $pagination, $entryCount, $formActionUrl, $manualCount, $autoCount, $urlAll, $urlManual, $urlAuto, $clearAutoUrl',
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
					'template_version'        => '1.0.26',
					'template_has_hookpoints' => 0,
				] );

				try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.26 reseeded template: %s (%d bytes)', $tpl['name'], strlen( $tpl['content'] ) ), 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.26 reseed FAILED for %s: %s', $tpl['name'], $e->getMessage() ), 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
			}
		}

		/* Step 5: Heal feeds stuck in 'running' state due to v1.0.24 postComplete bug.
		 * Idempotent - only fires if status is 'running' AND we've imported significant
		 * data already (last_record_count > 0 AND last_run is set). */
		try
		{
			$updated = \IPS\Db::i()->update( 'gd_distributor_feeds',
				[ 'last_run_status' => 'completed' ],
				[ "last_run_status = ? AND last_run IS NOT NULL", 'running' ]
			);
			if ( $updated )
			{
				try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.26 healed %d stuck-running feed(s)', $updated ), 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.26 stuck-running heal failed: ' . $e->getMessage(), 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
		}

		/* Step 6: Register the PruneConflictLog task in core_tasks.
		 * tasks.json is what install.php seeds from for fresh installs;
		 * existing installs need this explicit insert. */
		try
		{
			$exists = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				'core_tasks',
				[ '`key`=? AND app=?', 'PruneConflictLog', 'gdcatalog' ]
			)->first();

			if ( $exists === 0 )
			{
				\IPS\Db::i()->insert( 'core_tasks', [
					'app'       => 'gdcatalog',
					'key'       => 'PruneConflictLog',
					'frequency' => 'P1D',
					'next_run'  => time() + 86400,
					'last_run'  => 0,
					'enabled'   => 1,
					'running'   => 0,
				] );
				try { \IPS\Log::log( 'gdcatalog v1.0.26 registered PruneConflictLog task (daily)', 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.26 task registration failed: ' . $e->getMessage(), 'gdcatalog_upg_10026' ); } catch ( \Throwable ) {}
		}

		/* Step 7: Cache invalidation */
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
		return 'gdcatalog v1.0.26 - conflict log cleanup feature + bake working templates';
	}
}

class upgrade extends _upgrade {}
