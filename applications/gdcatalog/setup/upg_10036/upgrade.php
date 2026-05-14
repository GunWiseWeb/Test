<?php
namespace IPS\gdcatalog\setup\upg_10036;

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
		/* gdcatalog v1.0.36 - Per-distributor catalog locking.
		 *
		 * Adds the ability to "lock" an entire distributor's catalog so future
		 * imports route ALL field changes to gd_feed_conflicts for admin
		 * review (or 48hr auto-resolve via existing AutoResolveConflicts task).
		 *
		 * Implementation:
		 *
		 *   1. ALTER TABLE gd_distributor_feeds ADD locked / locked_at /
		 *      locked_by columns. Idempotent (checks SHOW COLUMNS first).
		 *
		 *   2. New queue extension LockDistributorCatalog processes products
		 *      in chunks of 500. For each product where distributor is in
		 *      distributor_sources: iterate editable fields, call
		 *      $product->lockField() for each populated one, save.
		 *
		 *   3. New dashboard.php controller methods lockCatalog() and
		 *      unlockCatalog(). Lock enqueues the queue job + sets feed flags.
		 *      Unlock is synchronous (single UPDATE clears locked_fields JSON).
		 *
		 *   4. Dashboard template reseeded to add Lock Catalog / Unlock
		 *      Catalog buttons next to existing Run Import buttons. Shows
		 *      "Locked" badge in status column when feed is locked.
		 *
		 *   5. Distributor class gets markLocked() and markUnlocked() helper
		 *      methods. (Patch applied via v136_PHP_PATCHES.md)
		 *
		 * New products from a locked feed are created normally (not auto-
		 * locked). Admin can run Lock Catalog again to lock newly-created
		 * products, or use the per-product Lock All button.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10035). */

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
				'gdcatalog v1.0.36 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10036' ); } catch ( \Throwable ) {}

			if ( $longVer < 10035 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.36 WARNING: app_long_version=%d below 10035',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10036' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.36 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10036' ); } catch ( \Throwable ) {}
		}

		/* Step 2: ALTER TABLE gd_distributor_feeds.
		 *
		 * Per CLAUDE.md memory: always verify actual table schema via DESCRIBE
		 * before INSERT/UPDATE/ALTER. Per-column try/catch. */
		$existingColumns = [];
		try
		{
			foreach ( \IPS\Db::i()->query( "SHOW COLUMNS FROM gd_distributor_feeds" ) as $col )
			{
				$existingColumns[] = $col['Field'];
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.36 SHOW COLUMNS failed: ' . $e->getMessage(), 'gdcatalog_upg_10036' ); } catch ( \Throwable ) {}
		}

		$columnsToAdd = [
			'locked'    => "ALTER TABLE gd_distributor_feeds ADD COLUMN locked TINYINT(1) NOT NULL DEFAULT 0 AFTER active",
			'locked_at' => "ALTER TABLE gd_distributor_feeds ADD COLUMN locked_at DATETIME NULL AFTER locked",
			'locked_by' => "ALTER TABLE gd_distributor_feeds ADD COLUMN locked_by INT UNSIGNED NULL AFTER locked_at",
		];

		foreach ( $columnsToAdd as $colName => $sql )
		{
			if ( in_array( $colName, $existingColumns, true ) )
			{
				try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.36 column gd_distributor_feeds.%s already exists, skipping ALTER', $colName ), 'gdcatalog_upg_10036' ); } catch ( \Throwable ) {}
				continue;
			}

			try
			{
				\IPS\Db::i()->query( $sql );
				try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.36 added column gd_distributor_feeds.%s', $colName ), 'gdcatalog_upg_10036' ); } catch ( \Throwable ) {}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.36 ALTER for %s FAILED: %s', $colName, $e->getMessage() ), 'gdcatalog_upg_10036' ); } catch ( \Throwable ) {}
			}
		}

		/* Step 3: Reseed dashboard template with Lock Catalog buttons.
		 *
		 * Baseline: v1.0.30 dashboard template (md5 766bcbffb08d0187ed1ffc5b9f930ee4
		 * 4369 bytes - confirmed at v1.0.35 install). v1.0.36 adds:
		 *   - "Locked" badge in status column when $ds['is_locked']
		 *   - Lock Catalog / Unlock Catalog buttons in actions column
		 * Everything else byte-identical. */
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
						<th style="width:420px">Actions</th>
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
							{{if !empty($ds['is_locked'])}}
								<span class="ipsBadge ipsBadge--warning" style="margin-left:4px" title="Catalog locked since {$ds['locked_at_human']}">🔒 Locked</span>
							{{endif}}
						</td>
						<td>
							<a href="{$ds['run_import_url']}" class="ipsButton ipsButton--primary ipsButton--small" data-confirm>Run Import</a>
							{{if !empty($ds['is_sportssouth'])}}
							<a href="{$ds['queue_full_import_url']}" class="ipsButton ipsButton--secondary ipsButton--small" data-confirm title="Background task for full ~58k catalog. Runs in chunks via cron.">Queue Full Import</a>
							{{endif}}
							{{if empty($ds['is_locked'])}}
								<a href="{$ds['lock_catalog_url']}" class="ipsButton ipsButton--secondary ipsButton--small" data-confirm title="Lock all populated fields on every product from this distributor. Future imports route changes to conflicts.">Lock Catalog</a>
							{{else}}
								<a href="{$ds['unlock_catalog_url']}" class="ipsButton ipsButton--negative ipsButton--small" data-confirm title="Unlock all fields. Distributor imports will resume overwriting normally.">Unlock Catalog</a>
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
				'template_data'           => '$totalProducts, $activeProducts, $reviewProducts, $pendingConflicts, $distributorStats, $taskUrls, $osExists, $osStats, $reindexQueue, $lockedFields',
				'template_updated'        => time(),
				'template_master_key'     => $masterKey,
				'template_location'       => 'admin',
				'template_app'            => 'gdcatalog',
				'template_version'        => '1.0.36',
				'template_has_hookpoints' => 0,
			] );

			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.36 reseeded dashboard template with Lock Catalog buttons (%d bytes)', strlen( $dashboardTemplate ) ), 'gdcatalog_upg_10036' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.36 dashboard reseed FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10036' ); } catch ( \Throwable ) {}
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
		return 'gdcatalog v1.0.36 - per-distributor catalog locking';
	}
}

class upgrade extends _upgrade {}
