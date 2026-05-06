<?php
/**
 * @brief       GD Master Catalog — Install routine
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Runs after schema.json tables are created.
 * Seeds the six distributor feed records and the category taxonomy.
 */

/* Seed the six distributor feed records with priority hierarchy (Section 2.2.1) */
$distributors = [
	['feed_name' => 'RSR Group Primary',        'distributor' => 'rsr_group',     'priority' => 1],
	['feed_name' => 'Sports South Primary',      'distributor' => 'sports_south',  'priority' => 2],
	['feed_name' => "Davidson's Primary",        'distributor' => 'davidsons',     'priority' => 3],
	['feed_name' => "Lipsey's Primary",          'distributor' => 'lipseys',       'priority' => 4],
	['feed_name' => 'Zanders Sporting Goods',    'distributor' => 'zanders',       'priority' => 5],
	['feed_name' => 'Bill Hicks Primary',        'distributor' => 'bill_hicks',    'priority' => 6],
];

/* Default conflict detection fields per Section 2.11.2 */
$defaultConflictFields = json_encode([
	'restricted_states' => true,
	'nfa_item'          => true,
	'requires_ffl'      => true,
	'caliber'           => true,
	'rounds_per_box'    => true,
	'category'          => false,
	'manufacturer'      => false,
	'description'       => false,
]);

\IPS\Db::i()->delete( 'gd_distributor_feeds' );

foreach ( $distributors as $dist )
{
    try {
        \IPS\Db::i()->insert( 'gd_distributor_feeds', [
            'feed_name'                => $dist['feed_name'],
            'distributor'              => $dist['distributor'],
            'priority'                 => $dist['priority'],
            'feed_url'                 => '',
            'feed_format'              => 'xml',
            'auth_type'                => 'none',
            'auth_credentials'         => NULL,
            'field_mapping'            => NULL,
            'category_mapping'         => NULL,
            'import_schedule'          => '6hr',
            'conflict_detection_fields'=> $defaultConflictFields,
            'active'                   => 0,
            'last_run'                 => NULL,
            'last_record_count'        => 0,
            'last_run_status'          => NULL,
        ]);
    } catch ( \Exception $e ) {}
}

/* Seed category taxonomy (Section 2.4) */
$categories = [
	'Handguns'               => ['Pistols', 'Revolvers', 'Derringers'],
	'Rifles'                 => ['Semi-Automatic', 'Bolt-Action', 'Lever-Action', 'Single-Shot', 'Muzzleloaders'],
	'Shotguns'               => ['Semi-Automatic', 'Pump-Action', 'Break-Action', 'Over/Under', 'Side-by-Side'],
	'Ammunition'             => ['Handgun Ammo', 'Rifle Ammo', 'Shotgun Ammo', 'Rimfire', 'Specialty/Exotic'],
	'NFA Items'              => ['Suppressors', 'Short-Barreled Rifles', 'Short-Barreled Shotguns', 'Machine Guns', 'AOW'],
	'Magazines'              => ['Handgun', 'Rifle', 'Shotgun', 'Drum'],
	'Optics'                 => ['Red Dots', 'Rifle Scopes', 'LPVOs', 'Prism Scopes', 'Night Vision', 'Thermal', 'Magnifiers'],
	'Parts & Accessories'    => ['Barrels', 'Triggers', 'Stocks', 'Grips', 'Rails', 'Handguards', 'Muzzle Devices'],
	'Holsters & Carry'       => ['IWB', 'OWB', 'Shoulder', 'Ankle', 'Appendix', 'Duty', 'Vehicle'],
	'Storage & Safety'       => ['Gun Safes', 'Hard Cases', 'Soft Cases', 'Lock Boxes', 'Trigger Locks'],
	'Cleaning & Maintenance' => ['Cleaning Kits', 'Lubricants', 'Solvents', 'Bore Snakes', 'Patches'],
	'Tactical Gear'          => ['Weapon Lights', 'Lasers', 'Bipods', 'Slings', 'Foregrips', 'Vertical Grips'],
	'Hunting Gear'           => ['Game Calls', 'Scent Control', 'Blinds', 'Feeders', 'Trail Cameras'],
];

\IPS\Db::i()->delete( 'gd_categories' );

$position = 0;
foreach ( $categories as $parentName => $children )
{
    $slug = mb_strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $parentName ) );
    $slug = trim( $slug, '-' );

    try {
        $parentId = \IPS\Db::i()->insert( 'gd_categories', [
            'parent_id'     => 0,
            'name'          => $parentName,
            'slug'          => $slug,
            'position'      => $position++,
            'product_count' => 0,
        ]);
    } catch ( \Exception $e ) {
        try { $parentId = \IPS\Db::i()->select( 'id', 'gd_categories', [ 'slug=?', $slug ] )->first(); } catch ( \Exception $e2 ) { continue; }
    }

    $childPos = 0;
    foreach ( $children as $childName )
    {
        $childSlug = $slug . '-' . mb_strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $childName ) );
        $childSlug = trim( $childSlug, '-' );

        try {
            \IPS\Db::i()->insert( 'gd_categories', [
                'parent_id'     => $parentId,
                'name'          => $childName,
                'slug'          => $childSlug,
                'position'      => $childPos++,
                'product_count' => 0,
            ]);
        } catch ( \Exception $e ) {}
    }
}

/* Seed templates directly into core_theme_templates to bypass IPS XML import
 * bug that corrupts template comments during theme.xml installation. */
$gdcatalogTemplates = [
	[
		'template_name' => 'feedList',
		'template_data' => '$feeds, $feedCounts',
		'template_content' => <<<'TEMPLATE_EOT'
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

		<p class="ipsType_light" style="margin-bottom:16px">{lang="gdcatalog_feeds_help"}</p>

		{{if count($feeds) === 0}}
			<div class="ipsEmptyMessage"><p>{lang="gdcatalog_feeds_empty"}</p></div>
		{{else}}
		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr>
					<th style="width:5%">#</th>
					<th>{lang="gdcatalog_feed_name"}</th>
					<th>{lang="gdcatalog_feed_distributor"}</th>
					<th>{lang="gdcatalog_feed_format"}</th>
					<th>{lang="gdcatalog_feed_schedule"}</th>
					<th>{lang="gdcatalog_feed_active"}</th>
					<th>{lang="gdcatalog_feed_last_run"}</th>
					<th>{lang="gdcatalog_feed_last_count"}</th>
					<th>{lang="gdcatalog_feed_last_status"}</th>
					<th style="width:100px">Actions</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $feeds as $feed}}
				<tr>
					<td>{$feed['priority']}</td>
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
					<td><a href="{$feed['edit_url']}" class="ipsButton ipsButton--primary ipsButton--small">Edit</a></td>
				</tr>
				{{endforeach}}
			</tbody>
		</table>
		{{endif}}

	</div>
</div>
TEMPLATE_EOT,
	],
	[
		'template_name' => 'conflictLog',
		'template_data' => '$entries, $filterField, $filterSource, $filterRule, $filterUpc, $total, $pagination, $entryCount, $formActionUrl',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">

		<div style="display:flex;gap:16px;margin-bottom:24px">
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{expression="number_format( $total )"}</div>
				<div>Total Conflict Entries</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$entryCount}</div>
				<div>Showing On This Page</div>
			</div>
		</div>

		<form method="get" action="{$formActionUrl}" style="display:flex;gap:8px;padding:12px 16px;border-bottom:1px solid var(--i-border-color, #e0e0e0);align-items:center;flex-wrap:wrap">
			<input type="text" name="upc" value="{$filterUpc}" placeholder="UPC" class="ipsInput ipsInput--text" style="flex:1;min-width:140px">
			<input type="text" name="field" value="{$filterField}" placeholder="Field name" class="ipsInput ipsInput--text" style="flex:1;min-width:140px">
			<input type="text" name="source" value="{$filterSource}" placeholder="Distributor" class="ipsInput ipsInput--text" style="flex:1;min-width:140px">
			<select name="rule" class="ipsInput ipsInput--select" style="min-width:160px">
				<option value="">All rules</option>
				<option value="priority" {{if $filterRule === 'priority'}}selected{{endif}}>Priority</option>
				<option value="longest" {{if $filterRule === 'longest'}}selected{{endif}}>Longest</option>
				<option value="highest_res" {{if $filterRule === 'highest_res'}}selected{{endif}}>Highest Res</option>
				<option value="highest_val" {{if $filterRule === 'highest_val'}}selected{{endif}}>Highest Val</option>
				<option value="flagged_for_review" {{if $filterRule === 'flagged_for_review'}}selected{{endif}}>Flagged</option>
				<option value="any_true" {{if $filterRule === 'any_true'}}selected{{endif}}>Any True</option>
				<option value="admin_override" {{if $filterRule === 'admin_override'}}selected{{endif}}>Admin Override</option>
			</select>
			<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Filter</button>
		</form>

		{{if $entryCount === 0}}
			<div class="ipsEmptyMessage"><p>{lang="gdcatalog_conflicts_empty"}</p></div>
		{{else}}
		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr>
					<th>{lang="gdcatalog_conflict_upc"}</th>
					<th>{lang="gdcatalog_conflict_field"}</th>
					<th>{lang="gdcatalog_conflict_winner"}</th>
					<th>{lang="gdcatalog_conflict_winner_val"}</th>
					<th>{lang="gdcatalog_conflict_loser"}</th>
					<th>{lang="gdcatalog_conflict_loser_val"}</th>
					<th>{lang="gdcatalog_conflict_rule"}</th>
					<th>{lang="gdcatalog_conflict_date"}</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $entries as $entry}}
				<tr>
					<td><code>{$entry['upc']}</code></td>
					<td>{$entry['field_name']}</td>
					<td>{$entry['winning_source']}</td>
					<td>{$entry['winning_value']}</td>
					<td>{$entry['losing_source']}</td>
					<td>{$entry['losing_value']}</td>
					<td><span class="ipsBadge ipsBadge--neutral">{$entry['rule_applied']}</span></td>
					<td>{$entry['resolved_at']}</td>
				</tr>
				{{endforeach}}
			</tbody>
		</table>
		{{endif}}

		<div style="margin-top:16px">{$pagination}</div>

	</div>
</div>
TEMPLATE_EOT,
	],
	[
		'template_name' => 'compliancePanel',
		'template_data' => '$tab, $counts, $tabUrls, $pendingFlags, $pendingConflicts, $allLocks, $adminFlags, $addRestrictionUrl',
		'template_content' => <<<'TEMPLATE_EOT'
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

	</div>
</div>
TEMPLATE_EOT,
	],
	[
		'template_name' => 'dashboard',
		'template_data' => '$totalProducts, $activeProducts, $reviewProducts, $categoryCounts, $distributorStats, $osExists, $osStats, $pendingConflicts, $pendingCompliance, $lockedFields, $reindexQueue',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">
		<div style="display:flex;gap:16px;margin-bottom:24px">
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{expression="number_format($totalProducts)"}</div>
				<div>Total Products</div>
				<div style="color:#666">Active: {expression="number_format($activeProducts)"} | Review: {expression="number_format($reviewProducts)"}</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$pendingConflicts}</div>
				<div>Pending Conflicts</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$pendingCompliance}</div>
				<div>Pending Compliance Flags</div>
			</div>
		</div>

		<h2 class="ipsType_sectionHead" style="margin:0 0 12px">Distributor Feeds</h2>

		{{if count($distributorStats) === 0}}
			<div class="ipsEmptyMessage"><p>{lang="gdcatalog_dash_feeds_empty"}</p></div>
		{{else}}
		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead><tr><th>#</th><th>Feed</th><th>Products</th><th>Status</th><th>Last Run</th><th>Last Status</th><th style="width:140px">Action</th></tr></thead>
			<tbody>
				{{foreach $distributorStats as $ds}}
				<tr>
					<td>{$ds['priority']}</td>
					<td><strong>{$ds['feed_name']}</strong></td>
					<td>{expression="number_format($ds['product_count'])"}</td>
					<td>
						{{if $ds['active']}}
							<span class="ipsBadge ipsBadge--positive">Active</span>
						{{else}}
							<span class="ipsBadge ipsBadge--neutral">Inactive</span>
						{{endif}}
					</td>
					<td>
						{{if $ds['last_run_start']}}
							{$ds['last_run_start']}
						{{else}}
							&mdash;
						{{endif}}
					</td>
					<td>
						{{if $ds['last_status']}}
							{$ds['last_status']}
						{{else}}
							&mdash;
						{{endif}}
					</td>
					<td>
						{{if $ds['active']}}
							<a href="{$ds['run_import_url']}" class="ipsButton ipsButton--primary ipsButton--small">Run Import</a>
						{{endif}}
					</td>
				</tr>
				{{endforeach}}
			</tbody>
		</table>
		{{endif}}
	</div>
</div>
TEMPLATE_EOT,
	],
	[
		'template_name' => 'productList',
		'template_data' => '$products, $categories, $search, $status, $catId, $total, $pagination, $formActionUrl, $productCount, $categoryCount',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">

		<div style="display:flex;gap:16px;margin-bottom:24px">
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{expression="number_format( $total )"}</div>
				<div>Total Matching Products</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$productCount}</div>
				<div>Showing On This Page</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$categoryCount}</div>
				<div>Categories</div>
			</div>
		</div>

		<form method="get" action="{$formActionUrl}" style="display:flex;gap:8px;padding:12px 16px;border-bottom:1px solid var(--i-border-color, #e0e0e0);align-items:center;flex-wrap:wrap">
			<input type="text" name="q" value="{$search}" placeholder="Search UPC, title, or brand..." class="ipsInput ipsInput--text" style="flex:1;min-width:200px">
			<select name="status" class="ipsInput ipsInput--select" style="min-width:140px">
				<option value="">All statuses</option>
				<option value="active" {{if $status === 'active'}}selected{{endif}}>Active</option>
				<option value="discontinued" {{if $status === 'discontinued'}}selected{{endif}}>Discontinued</option>
				<option value="admin_review" {{if $status === 'admin_review'}}selected{{endif}}>Admin Review</option>
				<option value="pending" {{if $status === 'pending'}}selected{{endif}}>Pending</option>
			</select>
			<select name="category" class="ipsInput ipsInput--select" style="min-width:160px">
				<option value="0">All categories</option>
				{{foreach $categories as $cat}}
					<option value="{$cat['id']}" {{if $catId === $cat['id']}}selected{{endif}}>{$cat['name']}</option>
				{{endforeach}}
			</select>
			<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Filter</button>
		</form>

		{{if $productCount === 0}}
			<div class="ipsEmptyMessage"><p>{lang="gdcatalog_products_empty"}</p></div>
		{{else}}
		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr>
					<th>{lang="gdcatalog_product_upc"}</th>
					<th>{lang="gdcatalog_product_title"}</th>
					<th>{lang="gdcatalog_product_brand"}</th>
					<th>{lang="gdcatalog_product_caliber"}</th>
					<th>{lang="gdcatalog_product_msrp"}</th>
					<th>{lang="gdcatalog_product_status"}</th>
					<th>{lang="gdcatalog_product_primary_source"}</th>
					<th style="width:180px">Actions</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $products as $product}}
				<tr>
					<td><code>{$product['upc']}</code></td>
					<td>{$product['title']}</td>
					<td>{$product['brand']}</td>
					<td>{$product['caliber']}</td>
					<td>{$product['msrp']}</td>
					<td>
						{{if $product['record_status'] === 'active'}}
							<span class="ipsBadge ipsBadge--positive">{lang="gdcatalog_status_active"}</span>
						{{elseif $product['record_status'] === 'admin_review'}}
							<span class="ipsBadge ipsBadge--warning">{lang="gdcatalog_status_admin_review"}</span>
						{{elseif $product['record_status'] === 'discontinued'}}
							<span class="ipsBadge ipsBadge--negative">{lang="gdcatalog_status_discontinued"}</span>
						{{else}}
							<span class="ipsBadge ipsBadge--neutral">{lang="gdcatalog_status_pending"}</span>
						{{endif}}
					</td>
					<td>{$product['primary_source']}</td>
					<td>
						<a href="{$product['edit_url']}" class="ipsButton ipsButton--primary ipsButton--small">Edit</a>
						{{if $product['record_status'] === 'admin_review'}}
							<a href="{$product['approve_url']}" class="ipsButton ipsButton--normal ipsButton--small">Approve</a>
						{{endif}}
					</td>
				</tr>
				{{endforeach}}
			</tbody>
		</table>
		{{endif}}

		<div style="margin-top:16px">{$pagination}</div>

	</div>
</div>
TEMPLATE_EOT,
	],
];

\IPS\Db::i()->delete( 'core_theme_templates', [
    'template_set_id=? AND template_app=? AND template_location=? AND template_group=?',
    1, 'gdcatalog', 'admin', 'catalog',
]);

foreach ( $gdcatalogTemplates as $tpl )
{
    try {
        \IPS\Db::i()->insert( 'core_theme_templates', [
            'template_set_id'   => 1,
            'template_app'      => 'gdcatalog',
            'template_location' => 'admin',
            'template_group'    => 'catalog',
            'template_name'     => $tpl['template_name'],
            'template_data'     => $tpl['template_data'],
            'template_content'  => $tpl['template_content'],
        ]);
    } catch ( \Exception $e ) {}
}
