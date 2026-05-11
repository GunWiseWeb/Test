<?php
namespace IPS\gdcatalog\setup\upg_10024;

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
		/* gdcatalog v1.0.24 - Background queue for Sports South full catalog
		 * import (~58k products) in 1000-product chunks via IPS Task queue.
		 *
		 * Background:
		 *   v1.0.10 capped imports at 1000 products per Run Import click
		 *   (MAX_RECORDS_PER_RUN). The full catalog is ~58k - synchronous
		 *   processing would time out (PHP-FPM 30s default).
		 *
		 *   Sports South API confirms (per their Getting Started PDF):
		 *     - DailyItemUpdate defaults to 1000 per call
		 *     - Pass LastItem=<previous max ITEMNO> to page forward
		 *     - Empty response = end of catalog
		 *
		 *   IPS provides QueueAbstract for background tasks: a Queue extension
		 *   class implements preQueueData() + run() + getProgress() +
		 *   postComplete(), and IPS's cron runs run() repeatedly across many
		 *   page loads, persisting $data and $offset between calls.
		 *
		 * Architecture:
		 *   - New file: applications/gdcatalog/extensions/core/Queue/
		 *     SportsSouthImport.php - the Queue extension class
		 *   - New method on Importer: runChunk() - processes one batch of
		 *     pre-fetched records through the normal enrichment + mapping
		 *     pipeline. Bypasses MAX_RECORDS_PER_RUN cap.
		 *   - New controller action: dashboard.php::queueFullImport() - calls
		 *     Task::queue() with feed_id payload
		 *   - dashboard template gets a "Queue Full Catalog Import" button
		 *     next to existing "Run Import Now" button (only on sportssouth feeds)
		 *   - extensions.json registers the Queue extension
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10023). */

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
				'gdcatalog v1.0.24 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10024' ); } catch ( \Throwable ) {}

			if ( $longVer < 10023 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.24 WARNING: app_long_version=%d below 10023',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10024' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.24 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10024' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Reseed the dashboard template with the Queue Full Catalog
		 * Import button alongside the existing Run Import Now button.
		 *
		 * Same pattern as v1.0.16 / v1.0.13 template reseeds: delete + insert
		 * via raw INSERT to core_theme_templates with template_set_id=1. */
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
							<a href="{$ds['queue_full_import_url']}" class="ipsButton ipsButton--alternate ipsButton--small" data-confirm title="Background task for full ~58k catalog. Runs in chunks via cron.">Queue Full Import</a>
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
					<a href="{$osStats['rebuild_url']}" class="ipsButton ipsButton--alternate ipsButton--small" data-confirm>Rebuild Index</a>
					<a href="{$osStats['process_queue_url']}" class="ipsButton ipsButton--normal ipsButton--small" data-confirm>Process Queue Now</a>
				</div>
			</div>
		{{endif}}

		<h2 class="ipsType_sectionHead">Compliance Locks</h2>
		<p>{$lockedFields} fields are currently locked.</p>

	</div>
</div>
TEMPLATE_EOT;

		try
		{
			$masterKey = md5( 'gdcatalog;admin;catalog;dashboard' );

			\IPS\Db::i()->delete( 'core_theme_templates', [
				'template_app=? AND template_name=? AND template_location=?',
				'gdcatalog', 'dashboard', 'admin'
			] );

			\IPS\Db::i()->insert( 'core_theme_templates', [
				'template_set_id'         => 1,
				'template_group'          => 'catalog',
				'template_content'        => $dashboardTemplate,
				'template_name'           => 'dashboard',
				'template_data'           => '$totalProducts, $activeProducts, $reviewProducts, $categoryCounts, $distributorStats, $osExists, $osStats, $pendingConflicts, $pendingCompliance, $lockedFields, $reindexQueue',
				'template_updated'        => time(),
				'template_master_key'     => $masterKey,
				'template_location'       => 'admin',
				'template_app'            => 'gdcatalog',
				'template_version'        => '1.0.24',
				'template_has_hookpoints' => 0,
			] );

			try { \IPS\Log::log( 'gdcatalog v1.0.24 dashboard template reseeded with Queue Full Import button', 'gdcatalog_upg_10024' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.24 dashboard template reseed FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10024' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Cache invalidation. Critical so IPS picks up the new
		 * extensions.json (which registers the Queue extension class) and
		 * the modified controllers / Importer. */
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
		return 'gdcatalog v1.0.24 - background queue for full Sports South catalog import';
	}
}

class upgrade extends _upgrade {}
