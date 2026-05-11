<?php
namespace IPS\gdcatalog\setup\upg_10016;

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
		/* gdcatalog v1.0.16 - Pagination for compliance panel + bake
		 * conflictLog pagination hotfix into the tarball.
		 *
		 * Changes:
		 *
		 * 1. compliance.php controller: paginate the active tab's dataset
		 *    with perPage=50, build $pagination via IPS core's pagination
		 *    template helper, pass to template.
		 *
		 * 2. compliancePanel template: reseeded with pagination output at
		 *    the bottom of each tab. template_data extended to include
		 *    $pagination as the last parameter.
		 *
		 * 3. conflictLog template: bake the production hotfix that
		 *    swapped {$pagination} for {$pagination|raw}. Without this,
		 *    a reinstall of v1.0.15 would re-introduce the broken
		 *    pagination on the Conflict Log page.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10015). */

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
				'gdcatalog v1.0.16 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10016' ); } catch ( \Throwable ) {}

			if ( $longVer < 10015 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.16 WARNING: app_long_version=%d below 10015',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10016' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.16 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10016' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Reseed conflictLog template - bake the pagination |raw fix
		 * that was applied as a production hotfix via SQL UPDATE. */
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
		</div>

		<form method="get" action="{$formActionUrl}" style="display:flex;gap:8px;padding:12px 16px;border-bottom:1px solid var(--i-border-color, #e0e0e0);align-items:center;flex-wrap:wrap">
			<input type="hidden" name="app" value="gdcatalog">
			<input type="hidden" name="module" value="catalog">
			<input type="hidden" name="controller" value="conflicts">
			<input type="text" name="upc" value="{$filterUpc}" placeholder="Filter by UPC" class="ipsInput ipsInput--text" style="flex:1;min-width:180px">
			<input type="text" name="field" value="{$filterField}" placeholder="Field name" class="ipsInput ipsInput--text" style="flex:1;min-width:140px">
			<input type="text" name="source" value="{$filterSource}" placeholder="Source distributor" class="ipsInput ipsInput--text" style="flex:1;min-width:160px">
			<select name="rule" class="ipsInput ipsInput--select" style="min-width:160px">
				<option value="">All resolution rules</option>
				<option value="auto" {{if $filterRule === 'auto'}}selected{{endif}}>Auto-resolved</option>
				<option value="priority" {{if $filterRule === 'priority'}}selected{{endif}}>Priority</option>
				<option value="manual" {{if $filterRule === 'manual'}}selected{{endif}}>Manual</option>
				<option value="locked" {{if $filterRule === 'locked'}}selected{{endif}}>Locked</option>
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
					<th>Source</th>
					<th>Incoming</th>
					<th>Resolved To</th>
					<th>Final Value</th>
					<th>Rule</th>
					<th>Resolved At</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $entries as $entry}}
				<tr>
					<td><code>{$entry['upc']}</code></td>
					<td>{$entry['field_name']}</td>
					<td>{$entry['source_distributor']}</td>
					<td>{$entry['incoming_value']}</td>
					<td>{$entry['resolved_to']}</td>
					<td>{$entry['final_value']}</td>
					<td><span class="ipsBadge ipsBadge--neutral">{$entry['resolution_rule']}</span></td>
					<td>{$entry['resolved_at']}</td>
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

		try
		{
			$masterKey = md5( 'gdcatalog;admin;catalog;conflictLog' );

			\IPS\Db::i()->delete( 'core_theme_templates', [
				'template_app=? AND template_name=? AND template_location=?',
				'gdcatalog', 'conflictLog', 'admin'
			] );

			\IPS\Db::i()->insert( 'core_theme_templates', [
				'template_set_id'         => 1,
				'template_group'          => 'catalog',
				'template_content'        => $conflictLogTemplate,
				'template_name'           => 'conflictLog',
				'template_data'           => '$entries, $filterField, $filterSource, $filterRule, $filterUpc, $total, $pagination, $entryCount, $formActionUrl',
				'template_updated'        => time(),
				'template_master_key'     => $masterKey,
				'template_location'       => 'admin',
				'template_app'            => 'gdcatalog',
				'template_version'        => '1.0.16',
				'template_has_hookpoints' => 0,
			] );

			try { \IPS\Log::log( 'gdcatalog v1.0.16 conflictLog template reseeded with pagination |raw fix baked in', 'gdcatalog_upg_10016' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.16 conflictLog template reseed FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10016' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Reseed compliancePanel template with pagination support.
		 * template_data extended to include $pagination as the last parameter,
		 * matching the controller's updated call signature. */
		$compliancePanelTemplate = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div style="display:flex;justify-content:flex-end;padding:10px 16px;border-bottom:1px solid var(--i-border-color, #e0e0e0)">
		<a href="{$addRestrictionUrl}" class="ipsButton ipsButton--primary ipsButton--small">Add State Restriction</a>
	</div>
	<div class="ipsBox_body ipsPad">

		<div style="display:flex;gap:16px;margin-bottom:24px">
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$counts['new']}</div>
				<div>{lang="gdcatalog_compliance_tab_new"}</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$counts['conflicts']}</div>
				<div>{lang="gdcatalog_compliance_tab_conflicts"}</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$counts['locks']}</div>
				<div>{lang="gdcatalog_compliance_tab_locks"}</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$counts['admin']}</div>
				<div>{lang="gdcatalog_compliance_tab_admin"}</div>
			</div>
		</div>

		<div style="display:flex;gap:8px;padding:12px 16px;border-bottom:1px solid var(--i-border-color, #e0e0e0);justify-content:center">
			<a href="{$tabUrls['new']}" class="ipsButton {{if $tab === 'new'}}ipsButton--primary{{else}}ipsButton--soft{{endif}} ipsButton--small">{lang="gdcatalog_compliance_tab_new"} <span class="ipsBadge ipsBadge--neutral" style="margin-left:6px">{$counts['new']}</span></a>
			<a href="{$tabUrls['conflicts']}" class="ipsButton {{if $tab === 'conflicts'}}ipsButton--primary{{else}}ipsButton--soft{{endif}} ipsButton--small">{lang="gdcatalog_compliance_tab_conflicts"} <span class="ipsBadge ipsBadge--neutral" style="margin-left:6px">{$counts['conflicts']}</span></a>
			<a href="{$tabUrls['locks']}" class="ipsButton {{if $tab === 'locks'}}ipsButton--primary{{else}}ipsButton--soft{{endif}} ipsButton--small">{lang="gdcatalog_compliance_tab_locks"} <span class="ipsBadge ipsBadge--neutral" style="margin-left:6px">{$counts['locks']}</span></a>
			<a href="{$tabUrls['admin']}" class="ipsButton {{if $tab === 'admin'}}ipsButton--primary{{else}}ipsButton--soft{{endif}} ipsButton--small">{lang="gdcatalog_compliance_tab_admin"} <span class="ipsBadge ipsBadge--neutral" style="margin-left:6px">{$counts['admin']}</span></a>
		</div>

		{{if $tab === 'new'}}
			{{if $counts['new'] === 0}}
				<div class="ipsEmptyMessage"><p>{lang="gdcatalog_compliance_empty_new"}</p></div>
			{{else}}
			<table class="ipsTable ipsTable_zebra" style="width:100%">
				<thead>
					<tr>
						<th>UPC</th>
						<th>Type</th>
						<th>Value</th>
						<th>Distributor</th>
						<th>First Seen</th>
						<th style="width:220px">Actions</th>
					</tr>
				</thead>
				<tbody>
					{{foreach $pendingFlags as $flag}}
					<tr>
						<td><code>{$flag['upc']}</code></td>
						<td>{$flag['flag_type']}</td>
						<td><strong>{$flag['flag_value']}</strong></td>
						<td>{$flag['distributor_id']}</td>
						<td>{$flag['first_seen_at']}</td>
						<td>
							<a href="{$flag['approve_url']}" class="ipsButton ipsButton--primary ipsButton--small">{lang="gdcatalog_compliance_approve"}</a>
							<a href="{$flag['reject_url']}" class="ipsButton ipsButton--negative ipsButton--small">{lang="gdcatalog_compliance_reject"}</a>
						</td>
					</tr>
					{{endforeach}}
				</tbody>
			</table>
			{{endif}}
		{{endif}}

		{{if $tab === 'conflicts'}}
			{{if $counts['conflicts'] === 0}}
				<div class="ipsEmptyMessage"><p>{lang="gdcatalog_compliance_empty_conflicts"}</p></div>
			{{else}}
			<table class="ipsTable ipsTable_zebra" style="width:100%">
				<thead>
					<tr>
						<th>UPC</th>
						<th>Field</th>
						<th>Current</th>
						<th>Incoming</th>
						<th>Auto-resolve</th>
						<th style="width:260px">Actions</th>
					</tr>
				</thead>
				<tbody>
					{{foreach $pendingConflicts as $conflict}}
					<tr>
						<td><code>{$conflict['upc']}</code></td>
						<td>{$conflict['field_name']}</td>
						<td>{$conflict['current_value']}</td>
						<td>{$conflict['incoming_value']}</td>
						<td>{$conflict['auto_resolve_at']}</td>
						<td>
							<a href="{$conflict['accept_url']}" class="ipsButton ipsButton--primary ipsButton--small">{lang="gdcatalog_compliance_accept_incoming"}</a>
							<a href="{$conflict['keep_url']}" class="ipsButton ipsButton--normal ipsButton--small">{lang="gdcatalog_compliance_keep_existing"}</a>
							<a href="{$conflict['custom_url']}" class="ipsButton ipsButton--normal ipsButton--small">{lang="gdcatalog_compliance_set_custom"}</a>
						</td>
					</tr>
					{{endforeach}}
				</tbody>
			</table>
			{{endif}}
		{{endif}}

		{{if $tab === 'locks'}}
			{{if $counts['locks'] === 0}}
				<div class="ipsEmptyMessage"><p>{lang="gdcatalog_compliance_empty_locks"}</p></div>
			{{else}}
			<table class="ipsTable ipsTable_zebra" style="width:100%">
				<thead>
					<tr>
						<th>UPC</th>
						<th>Field</th>
						<th>Locked Value</th>
						<th>Type</th>
						<th>Reason</th>
						<th>Locked At</th>
						<th style="width:120px">Actions</th>
					</tr>
				</thead>
				<tbody>
					{{foreach $allLocks as $lock}}
					<tr>
						<td><code>{$lock['upc']}</code></td>
						<td>{$lock['field_name']}</td>
						<td>{$lock['locked_value']}</td>
						<td>
							{{if $lock['is_hard_lock']}}
								<span class="ipsBadge ipsBadge--negative">{lang="gdcatalog_lock_type_hard"}</span>
							{{else}}
								<span class="ipsBadge ipsBadge--warning">{lang="gdcatalog_lock_type_distributor"}</span>
							{{endif}}
						</td>
						<td>{$lock['lock_reason']}</td>
						<td>{$lock['locked_at']}</td>
						<td>
							<a href="{$lock['unlock_url']}" class="ipsButton ipsButton--negative ipsButton--small" data-confirm>{lang="gdcatalog_lock_unlock"}</a>
						</td>
					</tr>
					{{endforeach}}
				</tbody>
			</table>
			{{endif}}
		{{endif}}

		{{if $tab === 'admin'}}
			{{if $counts['admin'] === 0}}
				<div class="ipsEmptyMessage"><p>{lang="gdcatalog_compliance_empty_admin"}</p></div>
			{{else}}
			<table class="ipsTable ipsTable_zebra" style="width:100%">
				<thead>
					<tr>
						<th>UPC</th>
						<th>Scope</th>
						<th>Type</th>
						<th>Value</th>
						<th>Set By</th>
						<th>Date</th>
						<th>Source</th>
					</tr>
				</thead>
				<tbody>
					{{foreach $adminFlags as $flag}}
					<tr>
						<td><code>{$flag['upc']}</code></td>
						<td>
							{{if $flag['listing_id']}}
								Listing
							{{else}}
								Product
							{{endif}}
						</td>
						<td>{$flag['flag_type']}</td>
						<td><strong>{$flag['flag_value']}</strong></td>
						<td>{$flag['admin_reviewed_by']}</td>
						<td>{$flag['admin_reviewed_at']}</td>
						<td>{$flag['source']}</td>
					</tr>
					{{endforeach}}
				</tbody>
			</table>
			{{endif}}
		{{endif}}

		{{if !empty( $pagination )}}
		<div style="margin-top:16px">{$pagination|raw}</div>
		{{endif}}

	</div>
</div>
TEMPLATE_EOT;

		try
		{
			$masterKey = md5( 'gdcatalog;admin;catalog;compliancePanel' );

			\IPS\Db::i()->delete( 'core_theme_templates', [
				'template_app=? AND template_name=? AND template_location=?',
				'gdcatalog', 'compliancePanel', 'admin'
			] );

			\IPS\Db::i()->insert( 'core_theme_templates', [
				'template_set_id'         => 1,
				'template_group'          => 'catalog',
				'template_content'        => $compliancePanelTemplate,
				'template_name'           => 'compliancePanel',
				'template_data'           => '$tab, $counts, $tabUrls, $pendingFlags, $pendingConflicts, $allLocks, $adminFlags, $addRestrictionUrl, $pagination',
				'template_updated'        => time(),
				'template_master_key'     => $masterKey,
				'template_location'       => 'admin',
				'template_app'            => 'gdcatalog',
				'template_version'        => '1.0.16',
				'template_has_hookpoints' => 0,
			] );

			try { \IPS\Log::log( 'gdcatalog v1.0.16 compliancePanel template reseeded with pagination support', 'gdcatalog_upg_10016' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.16 compliancePanel template reseed FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10016' ); } catch ( \Throwable ) {}
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
		return 'gdcatalog v1.0.16 - compliancePanel pagination + bake conflictLog hotfix';
	}
}

class upgrade extends _upgrade {}
