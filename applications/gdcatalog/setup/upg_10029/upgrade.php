<?php
namespace IPS\gdcatalog\setup\upg_10029;

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
		/* gdcatalog v1.0.29 - Discontinue hotfix + dashboard Run-Now buttons.
		 *
		 * Hotfix: The v1.0.27 reimport hit an "offset stuck" abort partway
		 * through (around 1000 products in). The discontinuation pass that
		 * runs after the import counted 57,326 products as "missed" three
		 * times (because they weren't in the aborted run's seenUpcs set),
		 * which tripped the discontinue_threshold=3 and flipped them all
		 * to record_status='discontinued'. Plus 12 other products were
		 * already discontinued from an earlier bug.
		 *
		 * The fix:
		 *   1. Revert ALL discontinued sports_south products that still
		 *      have raw_distributor_data (proof Sports South currently
		 *      carries them) back to record_status='active'
		 *   2. Reset their consecutive_misses to 0 in distributor_last_seen
		 *   3. Patch Importer.php::processDiscontinuations() to never
		 *      increment misses when the import saw zero UPCs (separate patch)
		 *
		 * Dashboard:
		 *   1. Reseed dashboard template with verified bytes from live DB
		 *      (md5 e4950033e37d13b300129e0fc18d76af) plus new Scheduled
		 *      Tasks section with Run Now buttons
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10028). */

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
				'gdcatalog v1.0.29 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10029' ); } catch ( \Throwable ) {}

			if ( $longVer < 10028 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.29 WARNING: app_long_version=%d below 10028',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10029' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.29 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10029' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Hotfix the discontinued products.
		 *
		 * Strategy:
		 *   - target rows where record_status='discontinued' AND primary_source='sports_south'
		 *     AND raw_distributor_data IS NOT NULL (proves Sports South still has it)
		 *   - flip record_status back to 'active'
		 *   - reset consecutive_misses=0 in distributor_last_seen JSON
		 *   - update last_updated timestamp
		 *
		 * Use a single UPDATE with JSON_SET to also fix the JSON in place. */
		try
		{
			$prefix = \IPS\Db::i()->prefix;

			/* Count first so the log is accurate */
			$countBefore = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				'gd_catalog',
				[
					"record_status='discontinued' AND primary_source='sports_south' AND raw_distributor_data IS NOT NULL"
				]
			)->first();

			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.29 hotfix: %d discontinued sports_south products eligible for revert (have raw_distributor_data)', $countBefore ), 'gdcatalog_upg_10029' ); } catch ( \Throwable ) {}

			if ( $countBefore > 0 )
			{
				/* Run the UPDATE in raw SQL because JSON_SET is easier this way.
				 * The IPS Db wrapper doesn't expose JSON_SET cleanly. */
				$nowDt = date( 'Y-m-d H:i:s' );
				$sql = "UPDATE {$prefix}gd_catalog
						SET record_status='active',
						    last_updated=?,
						    distributor_last_seen = JSON_SET(
						        COALESCE(distributor_last_seen, '{}'),
						        '$.sports_south.consecutive_misses', 0
						    )
						WHERE record_status='discontinued'
						  AND primary_source='sports_south'
						  AND raw_distributor_data IS NOT NULL";

				try
				{
					$stmt = \IPS\Db::i()->preparedQuery( $sql, [ $nowDt ] );
					$affected = ( $stmt && method_exists( $stmt, 'rowCount' ) ) ? $stmt->rowCount() : 0;
					try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.29 hotfix: reverted %d discontinued -> active', $affected ), 'gdcatalog_upg_10029' ); } catch ( \Throwable ) {}
				}
				catch ( \Throwable $e )
				{
					/* Fall back to plain query if preparedQuery isn't available */
					try
					{
						\IPS\Db::i()->query( "UPDATE {$prefix}gd_catalog
							SET record_status='active',
							    last_updated='" . addslashes( $nowDt ) . "',
							    distributor_last_seen = JSON_SET(
							        COALESCE(distributor_last_seen, '{}'),
							        '$.sports_south.consecutive_misses', 0
							    )
							WHERE record_status='discontinued'
							  AND primary_source='sports_south'
							  AND raw_distributor_data IS NOT NULL" );
						try { \IPS\Log::log( 'gdcatalog v1.0.29 hotfix: revert via fallback query succeeded', 'gdcatalog_upg_10029' ); } catch ( \Throwable ) {}
					}
					catch ( \Throwable $e2 )
					{
						try { \IPS\Log::log( 'gdcatalog v1.0.29 hotfix FAILED: ' . $e2->getMessage(), 'gdcatalog_upg_10029' ); } catch ( \Throwable ) {}
					}
				}

				/* Count after to verify */
				try
				{
					$countAfter = (int) \IPS\Db::i()->select(
						'COUNT(*)',
						'gd_catalog',
						[
							"record_status='discontinued' AND primary_source='sports_south' AND raw_distributor_data IS NOT NULL"
						]
					)->first();
					try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.29 hotfix: %d discontinued sports_south products remaining after revert (should be 0)', $countAfter ), 'gdcatalog_upg_10029' ); } catch ( \Throwable ) {}
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.29 hotfix outer failure: ' . $e->getMessage(), 'gdcatalog_upg_10029' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Reseed dashboard template.
		 *
		 * Content matches /tmp/dashboard_current.html (md5
		 * e4950033e37d13b300129e0fc18d76af) WITH ADDITIONS:
		 *   - corrected distributor table field references (distributor_label,
		 *     schedule_label, last_run_label, record_count, is_running, is_failed)
		 *   - new "Scheduled Tasks" section between Distributor Feeds
		 *     and OpenSearch with 3 Run Now buttons */
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

		<h2 class="ipsType_sectionHead">Scheduled Tasks</h2>
		<div class="ipsBox" style="padding:16px;margin-bottom:24px">
			<p style="margin-top:0">Run a scheduled task immediately instead of waiting for its next cron tick. Each runs synchronously and shows the result.</p>
			<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
				<a href="{$taskUrls['validate_images']}" class="ipsButton ipsButton--secondary ipsButton--small" data-confirm title="HEAD-checks up to 2000 image URLs to detect broken (404) links">Validate Image URLs Now</a>
				<a href="{$taskUrls['resolve_conflicts']}" class="ipsButton ipsButton--secondary ipsButton--small" data-confirm title="Auto-resolves conflicts that pass the auto-resolve rules">Resolve Conflicts Now</a>
				<a href="{$taskUrls['prune_log']}" class="ipsButton ipsButton--secondary ipsButton--small" data-confirm title="Deletes auto-resolved conflict log entries older than retention setting">Prune Conflict Log Now</a>
			</div>
		</div>

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
				'template_data'           => '$totalProducts, $activeProducts, $reviewProducts, $categoryCounts, $distributorStats, $osExists, $osStats, $pendingConflicts, $pendingCompliance, $lockedFields, $reindexQueue, $taskUrls',
				'template_updated'        => time(),
				'template_master_key'     => $masterKey,
				'template_location'       => 'admin',
				'template_app'            => 'gdcatalog',
				'template_version'        => '1.0.29',
				'template_has_hookpoints' => 0,
			] );

			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.29 reseeded dashboard template (%d bytes)', strlen( $dashboardTemplate ) ), 'gdcatalog_upg_10029' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.29 dashboard reseed FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10029' ); } catch ( \Throwable ) {}
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
		return 'gdcatalog v1.0.29 - discontinue hotfix + Run Now buttons + dashboard fixes';
	}
}

class upgrade extends _upgrade {}
