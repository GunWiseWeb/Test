<?php
/**
 * @brief       GD Dealer Manager — Install routine
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 *
 * Runs after schema.json tables are created. Seeds templates directly into
 * core_theme_templates using nowdoc heredocs so real newlines/tabs are
 * preserved. No comments inside template bodies (Rule #9) — the IPS
 * template compiler does not parse comment syntax.
 */

$gddealerTemplates = [

	/* ===== ADMIN: dashboard ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'admin',
		'group'         => 'dealers',
		'template_name' => 'dashboard',
		'template_data' => '$totalDealers, $activeDealers, $suspendedDealers, $totalListings, $inStockListings, $unmatchedTotal, $lastRunTime, $lastRunStatus, $tierCounts, $dealersUrl, $mrrUrl, $unmatchedUrl',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">

		<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;display:flex;flex-wrap:wrap;margin-bottom:24px">
			<div style="flex:1 1 150px;padding:20px;text-align:center;border-right:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">{lang="gddealer_dash_total_dealers"}</div>
				<div style="font-size:2em;font-weight:700">{$totalDealers}</div>
			</div>
			<div style="flex:1 1 150px;padding:20px;text-align:center;border-right:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">{lang="gddealer_dash_active_dealers"}</div>
				<div style="font-size:2em;font-weight:700;color:#2a8a2a">{$activeDealers}</div>
			</div>
			<div style="flex:1 1 150px;padding:20px;text-align:center;border-right:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">{lang="gddealer_dash_suspended_dealers"}</div>
				<div style="font-size:2em;font-weight:700;color:#c00">{$suspendedDealers}</div>
			</div>
			<div style="flex:1 1 150px;padding:20px;text-align:center;border-right:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">{lang="gddealer_dash_total_listings"}</div>
				<div style="font-size:2em;font-weight:700">{expression="number_format( $totalListings )"}</div>
			</div>
			<div style="flex:1 1 150px;padding:20px;text-align:center;border-right:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">{lang="gddealer_dash_in_stock_listings"}</div>
				<div style="font-size:2em;font-weight:700">{expression="number_format( $inStockListings )"}</div>
			</div>
			<div style="flex:1 1 150px;padding:20px;text-align:center">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">{lang="gddealer_dash_unmatched_total"}</div>
				<div style="font-size:2em;font-weight:700">{expression="number_format( $unmatchedTotal )"}</div>
			</div>
		</div>

		<div style="padding:12px 20px;font-weight:700;font-size:0.9em;text-transform:uppercase;letter-spacing:0.05em;color:#666;border-bottom:1px solid var(--i-border-color,#e0e0e0)">Subscription Tier Breakdown</div>
		<table class="ipsTable ipsTable_zebra" style="width:100%;margin-bottom:24px">
			<thead>
				<tr><th>Tier</th><th>Dealers</th></tr>
			</thead>
			<tbody>
				<tr><td><strong>Basic</strong></td><td>{$tierCounts['basic']}</td></tr>
				<tr><td><strong>Pro</strong></td><td>{$tierCounts['pro']}</td></tr>
				<tr><td><strong>Enterprise</strong></td><td>{$tierCounts['enterprise']}</td></tr>
				<tr><td><strong>Founding</strong></td><td>{$tierCounts['founding']}</td></tr>
			</tbody>
		</table>

		<div style="padding:12px 20px;font-weight:700;font-size:0.9em;text-transform:uppercase;letter-spacing:0.05em;color:#666;border-bottom:1px solid var(--i-border-color,#e0e0e0)">{lang="gddealer_dash_last_run"}</div>
		<div style="padding:12px 20px">
			{{if $lastRunTime}}
				{$lastRunTime} &mdash;
				{{if $lastRunStatus === 'completed'}}
					<span class="ipsBadge ipsBadge--positive">Completed</span>
				{{elseif $lastRunStatus === 'failed'}}
					<span class="ipsBadge ipsBadge--negative">Failed</span>
				{{elseif $lastRunStatus === 'running'}}
					<span class="ipsBadge ipsBadge--warning">Running</span>
				{{else}}
					<span class="ipsBadge ipsBadge--neutral">{$lastRunStatus}</span>
				{{endif}}
			{{else}}
				<em>No imports have run yet.</em>
			{{endif}}
		</div>

		<div style="padding:12px 20px;border-top:1px solid var(--i-border-color,#e0e0e0);display:flex;gap:8px">
			<a href="{$dealersUrl}" class="ipsButton ipsButton--primary">Manage Dealers</a>
			<a href="{$mrrUrl}" class="ipsButton ipsButton--normal">MRR Dashboard</a>
			<a href="{$unmatchedUrl}" class="ipsButton ipsButton--normal">Unmatched UPCs</a>
		</div>

	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== ADMIN: dealerList ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'admin',
		'group'         => 'dealers',
		'template_name' => 'dealerList',
		'template_data' => '$dealers, $onboardUrl',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body">

		<div style="padding:12px 20px;border-bottom:1px solid var(--i-border-color,#e0e0e0);display:flex;gap:8px">
			<a href="{$onboardUrl}" class="ipsButton ipsButton--primary">{lang="gddealer_onboard_button"}</a>
		</div>

		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr>
					<th>{lang="gddealer_dealer_name"}</th>
					<th>{lang="gddealer_dealer_tier"}</th>
					<th>{lang="gddealer_dealer_status"}</th>
					<th>{lang="gddealer_dealer_listing_count"}</th>
					<th>{lang="gddealer_dealer_last_import"}</th>
					<th>{lang="gddealer_dealer_mrr"}</th>
					<th>{lang="gddealer_dealer_actions"}</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $dealers as $d}}
				<tr>
					<td><strong>{$d['dealer_name']}</strong><br><small>ID {$d['dealer_id']}</small></td>
					<td>
						{{if $d['subscription_tier'] === 'enterprise'}}
							<span class="ipsBadge ipsBadge--positive">Enterprise</span>
						{{elseif $d['subscription_tier'] === 'pro'}}
							<span class="ipsBadge ipsBadge--style1">Pro</span>
						{{elseif $d['subscription_tier'] === 'founding'}}
							<span class="ipsBadge ipsBadge--warning">Founding</span>
						{{else}}
							<span class="ipsBadge ipsBadge--neutral">Basic</span>
						{{endif}}
					</td>
					<td>
						{{if $d['suspended']}}
							<span class="ipsBadge ipsBadge--negative">Suspended</span>
						{{elseif $d['active']}}
							<span class="ipsBadge ipsBadge--positive">Active</span>
						{{else}}
							<span class="ipsBadge ipsBadge--neutral">Inactive</span>
						{{endif}}
						{{if $d['disputes_suspended']}}
							<span class="ipsBadge ipsBadge--warning" style="margin-left:4px">Disputes Off</span>
						{{endif}}
					</td>
					<td>{expression="number_format( $d['listing_count'] )"}</td>
					<td>
						{{if $d['last_run']}}
							{$d['last_run']}
							{{if $d['last_run_status'] === 'failed'}}
								<br><span class="ipsBadge ipsBadge--negative">Failed</span>
							{{endif}}
						{{else}}
							&mdash;
						{{endif}}
					</td>
					<td>{$d['mrr']}</td>
					<td>
						<a href="{$d['view_url']}" class="ipsButton ipsButton--small ipsButton--primary">View</a>
						<a href="{$d['edit_url']}" class="ipsButton ipsButton--small ipsButton--normal">Edit</a>
						<a href="{$d['import_url']}" class="ipsButton ipsButton--small ipsButton--normal">Import</a>
						{{if $d['profile_url']}}
							<a href="{$d['profile_url']}" target="_blank" class="ipsButton ipsButton--small ipsButton--normal">Profile</a>
						{{endif}}
						{{if $d['suspended']}}
							<a href="{$d['suspend_url']}" class="ipsButton ipsButton--small ipsButton--positive">Unsuspend</a>
						{{else}}
							<a href="{$d['suspend_url']}" class="ipsButton ipsButton--small ipsButton--negative">Suspend</a>
						{{endif}}
					</td>
				</tr>
				{{endforeach}}
				{{if count( $dealers ) === 0}}
				<tr><td colspan="7" style="text-align:center;color:#999;padding:24px">No dealers yet. Dealers are created on first IPS Commerce subscription purchase.</td></tr>
				{{endif}}
			</tbody>
		</table>

	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== ADMIN: dealerDetail ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'admin',
		'group'         => 'dealers',
		'template_name' => 'dealerDetail',
		'template_data' => '$dealer, $logs, $listings, $backUrl, $editUrl, $importUrl, $suspendUrl, $invoiceUrl, $disputeSuspendUrl, $reviews, $transferUrl, $deleteUrl',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body">

		<div style="padding:16px 20px">
			<a href="{$backUrl}" style="color:#666;text-decoration:none">&larr; Back to dealer list</a>
		</div>

		<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;display:flex;flex-wrap:wrap;margin:0 20px 16px">
			<div style="flex:1 1 180px;padding:16px 20px;border-right:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Subscription Tier</div>
				<div style="font-weight:700;font-size:1.05em">{$dealer['subscription_tier']}</div>
			</div>
			<div style="flex:1 1 180px;padding:16px 20px;border-right:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">MRR Contribution</div>
				<div style="font-weight:700;font-size:1.05em">{$dealer['mrr']}</div>
			</div>
			<div style="flex:1 1 180px;padding:16px 20px;border-right:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Feed Format</div>
				<div style="font-weight:700;font-size:1.05em">{$dealer['feed_format']}</div>
			</div>
			<div style="flex:1 1 180px;padding:16px 20px;border-right:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Schedule</div>
				<div style="font-weight:700;font-size:1.05em">{$dealer['import_schedule']}</div>
			</div>
			<div style="flex:1 1 180px;padding:16px 20px">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Last Record Count</div>
				<div style="font-weight:700;font-size:1.05em">{expression="number_format( $dealer['last_record_count'] )"}</div>
			</div>
		</div>

		<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin:0 20px 16px">
			<div style="padding:16px 20px;border-bottom:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Feed URL</div>
				<div style="font-weight:700;font-size:1.05em"><code>{$dealer['feed_url']}</code></div>
			</div>
			<div style="padding:16px 20px;border-bottom:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">API Key</div>
				<div style="font-family:monospace;font-size:0.85em;background:#f4f4f4;padding:6px 10px;border-radius:4px;word-break:break-all">{$dealer['api_key']}</div>
			</div>
			{{if $dealer['profile_url']}}
			<div style="padding:16px 20px;border-top:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Public Profile URL</div>
				<div style="display:flex;gap:8px;align-items:center">
					<code style="font-size:0.85em;background:#f4f4f4;padding:6px 10px;border-radius:4px;flex:1;word-break:break-all">{$dealer['profile_url']}</code>
					<a href="{$dealer['profile_url']}" target="_blank" class="ipsButton ipsButton--normal ipsButton--small">View</a>
				</div>
			</div>
			{{endif}}
			{{if $dealer['trial_expires_at']}}
			<div style="padding:16px 20px;border-bottom:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Trial Expires</div>
				<div style="font-weight:700;font-size:1.05em">
					{$dealer['trial_expires_at']}
					{{if $dealer['trial_expires_soon']}}
						<span class="ipsBadge ipsBadge--warning" style="margin-left:8px">Expires within 30 days</span>
					{{endif}}
				</div>
			</div>
			{{endif}}
			{{if $dealer['billing_note']}}
			<div style="padding:16px 20px">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Billing Notes</div>
				<div style="font-size:0.95em;white-space:pre-wrap">{$dealer['billing_note']}</div>
			</div>
			{{endif}}
		</div>

		{{if $dealer['disputes_suspended']}}
		<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:10px 16px;margin:0 20px 12px;display:flex;align-items:center;gap:8px">
			<span class="ipsBadge ipsBadge--negative">DISPUTES SUSPENDED</span>
			<span style="font-size:0.85em;color:#991b1b">This dealer cannot file new review contests until an admin lifts the suspension.</span>
		</div>
		{{endif}}

		<div style="padding:12px 20px;border-top:1px solid var(--i-border-color,#e0e0e0);border-bottom:1px solid var(--i-border-color,#e0e0e0);display:flex;gap:8px;flex-wrap:wrap">
			<a href="{$editUrl}" class="ipsButton ipsButton--primary ipsButton--small">Edit Feed Config</a>
			<a href="{$importUrl}" class="ipsButton ipsButton--normal ipsButton--small">Force Import Now</a>
			<a href="{$invoiceUrl}" class="ipsButton ipsButton--normal ipsButton--small">View in Commerce</a>
			<a href="{$suspendUrl}" class="ipsButton ipsButton--negative ipsButton--small">{{if $dealer['suspended']}}Unsuspend Dealer{{else}}Suspend Dealer{{endif}}</a>
			<a href="{$disputeSuspendUrl}" class="ipsButton ipsButton--small {{if $dealer['disputes_suspended']}}ipsButton--positive{{else}}ipsButton--warning{{endif}}">{{if $dealer['disputes_suspended']}}Unsuspend Disputes{{else}}Suspend Disputes{{endif}}</a>
			<a href="{$transferUrl}" class="ipsButton ipsButton--normal ipsButton--small">{lang="gddealer_transfer_ownership"}</a>
			<a href="{$deleteUrl}" class="ipsButton ipsButton--negative ipsButton--small" data-confirm data-confirmMessage="{lang="gddealer_confirm_permanent_delete"}">{lang="gddealer_permanent_delete"}</a>
		</div>

		<div style="padding:12px 20px;font-weight:700;font-size:0.9em;text-transform:uppercase;letter-spacing:0.05em;color:#666;border-bottom:1px solid var(--i-border-color,#e0e0e0)">Recent Import Log</div>
		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr>
					<th>Started</th>
					<th>Status</th>
					<th>Total</th>
					<th>New</th>
					<th>Updated</th>
					<th>Unchanged</th>
					<th>Unmatched</th>
					<th>Drops</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $logs as $l}}
				<tr>
					<td>{$l['run_start']}</td>
					<td>
						{{if $l['status'] === 'completed'}}
							<span class="ipsBadge ipsBadge--positive">OK</span>
						{{elseif $l['status'] === 'failed'}}
							<span class="ipsBadge ipsBadge--negative">Failed</span>
						{{else}}
							<span class="ipsBadge ipsBadge--warning">{$l['status']}</span>
						{{endif}}
					</td>
					<td>{$l['records_total']}</td>
					<td>{$l['records_created']}</td>
					<td>{$l['records_updated']}</td>
					<td>{$l['records_unchanged']}</td>
					<td>{$l['records_unmatched']}</td>
					<td>{$l['price_drops']}</td>
				</tr>
				{{if $l['error_log']}}
				<tr><td colspan="8"><pre style="white-space:pre-wrap;color:#c00;margin:0">{$l['error_log']}</pre></td></tr>
				{{endif}}
				{{endforeach}}
				{{if count( $logs ) === 0}}
				<tr><td colspan="8" style="text-align:center;color:#999;padding:24px">No imports have run for this dealer yet.</td></tr>
				{{endif}}
			</tbody>
		</table>

		<div style="padding:12px 20px;font-weight:700;font-size:0.9em;text-transform:uppercase;letter-spacing:0.05em;color:#666;border-bottom:1px solid var(--i-border-color,#e0e0e0)">Recent Listings</div>
		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr><th>UPC</th><th>Price</th><th>Stock</th><th>Status</th><th>Last Updated</th></tr>
			</thead>
			<tbody>
				{{foreach $listings as $l}}
				<tr>
					<td><code>{$l['upc']}</code></td>
					<td>{$l['dealer_price']}</td>
					<td>
						{{if $l['in_stock']}}
							<span class="ipsBadge ipsBadge--positive">In Stock</span>
						{{else}}
							<span class="ipsBadge ipsBadge--neutral">Out</span>
						{{endif}}
					</td>
					<td>{$l['listing_status']}</td>
					<td>{$l['last_updated']}</td>
				</tr>
				{{endforeach}}
				{{if count( $listings ) === 0}}
				<tr><td colspan="5" style="text-align:center;color:#999;padding:24px">No listings.</td></tr>
				{{endif}}
			</tbody>
		</table>

		<div style="padding:12px 20px;font-weight:700;font-size:0.9em;text-transform:uppercase;letter-spacing:0.05em;color:#666;border-bottom:1px solid var(--i-border-color,#e0e0e0);border-top:1px solid var(--i-border-color,#e0e0e0)">Recent Reviews</div>
		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr><th>Reviewer</th><th>Pricing</th><th>Shipping</th><th>Service</th><th>Status</th><th>Posted</th><th style="text-align:right">Actions</th></tr>
			</thead>
			<tbody>
				{{foreach $reviews as $r}}
				<tr>
					<td><strong>{$r['member_name']}</strong></td>
					<td>{$r['rating_pricing']} / 5</td>
					<td>{$r['rating_shipping']} / 5</td>
					<td>{$r['rating_service']} / 5</td>
					<td>
						{{if $r['dispute_status'] === 'none'}}
							<span class="ipsBadge ipsBadge--neutral">Live</span>
						{{elseif $r['dispute_status'] === 'pending_customer'}}
							<span class="ipsBadge ipsBadge--warning">Pending Customer</span>
						{{elseif $r['dispute_status'] === 'pending_admin'}}
							<span class="ipsBadge ipsBadge--warning">Pending Admin</span>
						{{elseif $r['dispute_status'] === 'resolved_dealer'}}
							<span class="ipsBadge ipsBadge--positive">Upheld</span>
						{{elseif $r['dispute_status'] === 'dismissed'}}
							<span class="ipsBadge ipsBadge--neutral">Dismissed</span>
						{{else}}
							<span class="ipsBadge ipsBadge--neutral">{$r['dispute_status']}</span>
						{{endif}}
					</td>
					<td>{$r['created_at']}</td>
					<td style="text-align:right">
						<a href="{$r['delete_url']}" class="ipsButton ipsButton--negative ipsButton--tiny"
						   data-confirm data-confirmMessage="Are you sure you want to delete this review? This cannot be undone.">
							<i class="fa-solid fa-trash" aria-hidden="true"></i>
						</a>
					</td>
				</tr>
				{{if $r['review_body']}}
				<tr><td colspan="7" style="color:#555;background:#fafafa"><em>{$r['review_body']|raw}</em></td></tr>
				{{endif}}
				{{endforeach}}
				{{if count( $reviews ) === 0}}
				<tr><td colspan="7" style="text-align:center;color:#999;padding:24px">No reviews yet.</td></tr>
				{{endif}}
			</tbody>
		</table>

	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== ADMIN: disputeCounts ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'admin',
		'group'         => 'dealers',
		'template_name' => 'disputeCounts',
		'template_data' => '$rows, $monthKey',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body">
		<h2 class="ipsType_sectionHead" style="padding:16px 20px;margin:0;border-bottom:1px solid var(--i-border-color,#e0e0e0)">Dispute Counts — {$monthKey}</h2>
		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr>
					<th>Dealer</th>
					<th>Tier</th>
					<th>Base Limit</th>
					<th>Bonus</th>
					<th>Used</th>
					<th>Remaining</th>
					<th style="text-align:right">Actions</th>
				</tr>
			</thead>
			<tbody>
				{{if count($rows) === 0}}
					<tr><td colspan="7" style="padding:20px;text-align:center;color:#999">No dealers found.</td></tr>
				{{endif}}
				{{foreach $rows as $r}}
					<tr>
						<td>{$r['dealer_name']}</td>
						<td>{$r['tier']}</td>
						<td>{{if $r['unlimited']}}Unlimited{{else}}{$r['limit']}{{endif}}</td>
						<td>{$r['bonus']}</td>
						<td>{$r['used']}</td>
						<td>{{if $r['unlimited']}}Unlimited{{else}}{$r['remaining']}{{endif}}</td>
						<td style="text-align:right;white-space:nowrap">
							<a href="{$r['reset_url']}" class="ipsButton ipsButton--small ipsButton--light" title="Reset count to 0">Reset</a>
							<a href="{$r['grant1_url']}" class="ipsButton ipsButton--small ipsButton--positive" title="Grant 1 bonus dispute">+1</a>
							<a href="{$r['grant5_url']}" class="ipsButton ipsButton--small ipsButton--positive" title="Grant 5 bonus disputes">+5</a>
						</td>
					</tr>
				{{endforeach}}
			</tbody>
		</table>
	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== ADMIN: allReviews ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'admin',
		'group'         => 'dealers',
		'template_name' => 'allReviews',
		'template_data' => '$rows, $dealerOptions, $filterStatus, $filterDealer, $formUrl',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body">

		<div style="padding:16px 20px;border-bottom:1px solid var(--i-border-color,#e0e0e0)">
			<form method="get" action="{$formUrl}" style="display:flex;flex-wrap:wrap;gap:12px;align-items:end">
				<input type="hidden" name="app" value="gddealer">
				<input type="hidden" name="module" value="dealers">
				<input type="hidden" name="controller" value="dealers">
				<input type="hidden" name="do" value="reviews">
				<div>
					<label style="display:block;font-size:0.8em;color:#666;margin-bottom:4px">Dealer</label>
					<select name="dealer_id">
						{{foreach $dealerOptions as $did => $dname}}
							<option value="{$did}" {{if $did === $filterDealer}}selected{{endif}}>{$dname}</option>
						{{endforeach}}
					</select>
				</div>
				<div>
					<label style="display:block;font-size:0.8em;color:#666;margin-bottom:4px">Status</label>
					<select name="status">
						<option value="" {{if $filterStatus === ''}}selected{{endif}}>All statuses</option>
						<option value="none" {{if $filterStatus === 'none'}}selected{{endif}}>Live</option>
						<option value="pending_customer" {{if $filterStatus === 'pending_customer'}}selected{{endif}}>Pending Customer</option>
						<option value="pending_admin" {{if $filterStatus === 'pending_admin'}}selected{{endif}}>Pending Admin</option>
						<option value="resolved_dealer" {{if $filterStatus === 'resolved_dealer'}}selected{{endif}}>Upheld</option>
						<option value="dismissed" {{if $filterStatus === 'dismissed'}}selected{{endif}}>Dismissed</option>
					</select>
				</div>
				<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Filter</button>
			</form>
		</div>

		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr>
					<th>Dealer</th>
					<th>Reviewer</th>
					<th>Pricing</th>
					<th>Shipping</th>
					<th>Service</th>
					<th>Status</th>
					<th>Posted</th>
					<th style="text-align:right">Actions</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $rows as $r}}
				<tr>
					<td><strong>{$r['dealer_name']}</strong></td>
					<td>{$r['member_name']}</td>
					<td>{$r['rating_pricing']} / 5</td>
					<td>{$r['rating_shipping']} / 5</td>
					<td>{$r['rating_service']} / 5</td>
					<td>
						{{if $r['dispute_status'] === 'none'}}
							<span class="ipsBadge ipsBadge--neutral">Live</span>
						{{elseif $r['dispute_status'] === 'pending_customer'}}
							<span class="ipsBadge ipsBadge--warning">Pending Customer</span>
						{{elseif $r['dispute_status'] === 'pending_admin'}}
							<span class="ipsBadge ipsBadge--warning">Pending Admin</span>
						{{elseif $r['dispute_status'] === 'resolved_dealer'}}
							<span class="ipsBadge ipsBadge--positive">Upheld</span>
						{{elseif $r['dispute_status'] === 'dismissed'}}
							<span class="ipsBadge ipsBadge--neutral">Dismissed</span>
						{{else}}
							<span class="ipsBadge ipsBadge--neutral">{$r['dispute_status']}</span>
						{{endif}}
					</td>
					<td>{$r['created_at']}</td>
					<td style="text-align:right">
						<a href="{$r['delete_url']}" class="ipsButton ipsButton--negative ipsButton--tiny"
						   data-confirm data-confirmMessage="Are you sure you want to delete this review? This cannot be undone.">
							<i class="fa-solid fa-trash" aria-hidden="true"></i>
						</a>
					</td>
				</tr>
				{{if $r['review_body']}}
				<tr><td colspan="8" style="color:#555;background:#fafafa"><em>{$r['review_body']|raw}</em></td></tr>
				{{endif}}
				{{endforeach}}
				{{if count( $rows ) === 0}}
				<tr><td colspan="8" style="text-align:center;color:#999;padding:24px">No reviews match the current filter.</td></tr>
				{{endif}}
			</tbody>
		</table>

	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== ADMIN: mrrDashboard ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'admin',
		'group'         => 'dealers',
		'template_name' => 'mrrDashboard',
		'template_data' => '$totalMrr, $tierRows, $newSignups, $churn',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">

		<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;display:flex;flex-wrap:wrap;margin-bottom:24px">
			<div style="flex:1 1 200px;padding:20px;text-align:center;border-right:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">{lang="gddealer_mrr_total"}</div>
				<div style="font-size:2em;font-weight:700">{$totalMrr}</div>
			</div>
			<div style="flex:1 1 200px;padding:20px;text-align:center;border-right:1px solid var(--i-border-color,#e0e0e0)">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">{lang="gddealer_mrr_new_signups"}</div>
				<div style="font-size:2em;font-weight:700;color:#2a8a2a">{$newSignups}</div>
			</div>
			<div style="flex:1 1 200px;padding:20px;text-align:center">
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">{lang="gddealer_mrr_churn"}</div>
				<div style="font-size:2em;font-weight:700;color:#c00">{$churn}</div>
			</div>
		</div>

		<div style="padding:12px 20px;font-weight:700;font-size:0.9em;text-transform:uppercase;letter-spacing:0.05em;color:#666;border-bottom:1px solid var(--i-border-color,#e0e0e0)">{lang="gddealer_mrr_by_tier"}</div>
		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr><th>Tier</th><th>Dealers</th><th>MRR</th></tr>
			</thead>
			<tbody>
				{{foreach $tierRows as $r}}
				<tr>
					<td><strong>{$r['label']}</strong></td>
					<td>{$r['count']}</td>
					<td>{$r['mrr']}</td>
				</tr>
				{{endforeach}}
			</tbody>
		</table>

	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== ADMIN: unmatchedList ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'admin',
		'group'         => 'dealers',
		'template_name' => 'unmatchedList',
		'template_data' => '$rows, $total, $pagination',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body">

		<div style="padding:12px 20px;color:#666;font-size:0.9em">{expression="number_format( $total )"} unmatched UPCs across all dealer feeds.</div>

		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr>
					<th>{lang="gddealer_unmatched_upc"}</th>
					<th>{lang="gddealer_unmatched_dealer"}</th>
					<th>{lang="gddealer_unmatched_first_seen"}</th>
					<th>{lang="gddealer_unmatched_last_seen"}</th>
					<th>{lang="gddealer_unmatched_count"}</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				{{foreach $rows as $r}}
				<tr>
					<td><code>{$r['upc']}</code></td>
					<td>{$r['dealer_name']}</td>
					<td>{$r['first_seen']}</td>
					<td>{$r['last_seen']}</td>
					<td>{$r['occurrence_count']}</td>
					<td>
						<a href="{$r['add_url']}" class="ipsButton ipsButton--small ipsButton--positive">{lang="gddealer_unmatched_add_to_catalog"}</a>
						<a href="{$r['exclude_url']}" class="ipsButton ipsButton--small ipsButton--neutral">{lang="gddealer_unmatched_exclude"}</a>
					</td>
				</tr>
				{{endforeach}}
				{{if count( $rows ) === 0}}
				<tr><td colspan="6" style="text-align:center;color:#999;padding:24px">No unmatched UPCs.</td></tr>
				{{endif}}
			</tbody>
		</table>

		<div style="padding:12px 20px">{$pagination|raw}</div>

	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== ADMIN: disputeQueue ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'admin',
		'group'         => 'dealers',
		'template_name' => 'disputeQueue',
		'template_data' => '$rows, $filterStatus, $counts, $baseUrl',
		'template_content' => <<<'TEMPLATE_EOT'
<style>
details.gdDisputeCard > summary { list-style:none }
details.gdDisputeCard > summary::-webkit-details-marker { display:none }
details.gdDisputeCard > summary .gdChevron { transition:transform 0.2s }
details.gdDisputeCard[open] > summary .gdChevron { transform:rotate(90deg) }
</style>
<div class="ipsBox">
	<h2 class="ipsBox_title">{lang="gddealer_disputes_title"}</h2>
	<div style="padding:16px">

		<div style="margin-bottom:16px">
			<select id="gdDisputeFilter" onchange="window.location.href=this.value" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.9em;background:#fff;cursor:pointer;min-width:240px">
				<option value="{$baseUrl}&amp;status=active" {{if $filterStatus === 'active'}}selected{{endif}}>Active ({$counts['active']})</option>
				<option value="{$baseUrl}&amp;status=pending_admin" {{if $filterStatus === 'pending_admin'}}selected{{endif}}>Awaiting Admin ({$counts['pending_admin']})</option>
				<option value="{$baseUrl}&amp;status=pending_customer" {{if $filterStatus === 'pending_customer'}}selected{{endif}}>Awaiting Customer ({$counts['pending_customer']})</option>
				<option value="{$baseUrl}&amp;status=resolved_dealer" {{if $filterStatus === 'resolved_dealer'}}selected{{endif}}>Upheld ({$counts['resolved_dealer']})</option>
				<option value="{$baseUrl}&amp;status=dismissed" {{if $filterStatus === 'dismissed'}}selected{{endif}}>Dismissed ({$counts['dismissed']})</option>
				<option value="{$baseUrl}&amp;status=all" {{if $filterStatus === 'all'}}selected{{endif}}>All ({$counts['all']})</option>
			</select>
		</div>

		{{if count($rows) === 0}}
			<div class="ipsEmptyMessage"><p>No disputes match this filter.</p></div>
		{{else}}
			{{foreach $rows as $r}}

			<details class="gdDisputeCard" style="margin-bottom:16px" {{if $r['dispute_status'] === 'pending_admin'}}open{{endif}}>
				<summary style="cursor:pointer;background:#fff;border:1px solid {$r['status_border']};border-radius:8px;padding:12px 16px;font-size:0.9em;display:flex;align-items:center;gap:8px">
					<i class="fa-solid fa-chevron-right gdChevron" style="font-size:0.7em;color:#999" aria-hidden="true"></i>
					<strong>{$r['dealer_name']}</strong>
					<span style="color:#999;font-size:0.9em">vs {$r['member_name']}</span>
					<span style="margin-left:auto;display:inline-block;padding:2px 10px;border-radius:9999px;font-size:0.8em;font-weight:600;background:{$r['status_bg']};color:{$r['status_color']}">{$r['status_label']}</span>
					<span style="color:#999;font-size:0.8em">{$r['dispute_at_formatted']}</span>
				</summary>

				<div style="background:#fff;border:1px solid #e0e0e0;border-radius:0 0 8px 8px;border-top:0;padding:20px">
				<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap;gap:8px">
					<div>
						<strong>{$r['dealer_name']}</strong>
						<span style="color:#999;font-size:0.85em">(dealer #{$r['dealer_id']})</span>
						<span style="color:#666;font-size:0.85em;margin-left:8px">Reviewer: {$r['member_name']}</span>
					</div>
					<div style="font-size:0.8em;color:#999;text-align:right">
						Review: {$r['created_at']}<br>
						{{if $r['dispute_status'] === 'pending_customer'}}
							<span class="ipsBadge ipsBadge--warning">Awaiting customer &mdash; {$r['days_remaining']} days left (deadline {$r['dispute_deadline']})</span>
						{{elseif $r['dispute_status'] === 'pending_admin'}}
							<span class="ipsBadge ipsBadge--style1">Awaiting admin decision (disputed {$r['dispute_at']})</span>
						{{elseif $r['dispute_status'] === 'resolved_dealer'}}
							<span class="ipsBadge ipsBadge--positive">Upheld &mdash; resolved {$r['dispute_resolved_at']}</span>
						{{elseif $r['dispute_status'] === 'dismissed'}}
							<span class="ipsBadge ipsBadge--negative">Dismissed &mdash; resolved {$r['dispute_resolved_at']}</span>
						{{endif}}
					</div>
				</div>

				<div style="display:flex;gap:16px;margin-bottom:8px;flex-wrap:wrap">
					<span style="font-size:0.8em;color:#666">Pricing: <strong>{$r['rating_pricing']}/5</strong></span>
					<span style="font-size:0.8em;color:#666">Shipping: <strong>{$r['rating_shipping']}/5</strong></span>
					<span style="font-size:0.8em;color:#666">Service: <strong>{$r['rating_service']}/5</strong></span>
				</div>

				{{if $r['review_body']}}
				<div style="background:#f9fafb;border:1px solid #e5e7eb;padding:12px;border-radius:4px;margin-bottom:12px">
					<div style="font-size:0.75em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Customer review</div>
					<div style="margin:0;color:#333;font-size:0.9em">{$r['review_body']|raw}</div>
					{{if count($r['review_body_attachments']) > 0}}
					{{if $r['review_body_has_unembedded_images']}}<p style="font-size:12px;color:#9ca3af;margin:8px 0 4px">Attached images:</p>{{endif}}
					<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:8px">
					{{foreach $r['review_body_attachments'] as $att}}
					{{if $att['is_image']}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:block;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;background:#fff"><img src="{$att['thumb_url']}" alt="{$att['file_name']}" style="display:block;max-width:140px;max-height:140px;object-fit:cover" loading="lazy"></a>{{else}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;color:#374151;text-decoration:none;font-size:13px"><i class="fa-solid fa-paperclip" aria-hidden="true"></i> {$att['file_name']}</a>{{endif}}
					{{endforeach}}
					</div>
					{{endif}}
				</div>
				{{endif}}

				{{if $r['dealer_response']}}
				<div style="background:#f0f7ff;border-left:3px solid #2563eb;padding:8px 12px;border-radius:0 4px 4px 0;margin-bottom:12px;font-size:0.85em">
					<strong style="color:#2563eb">Dealer public response:</strong> {$r['dealer_response']|raw}
				</div>
				{{endif}}

				<div style="background:#fff8f0;border-left:3px solid #f59e0b;padding:10px 14px;border-radius:0 4px 4px 0;margin-bottom:12px;font-size:0.85em;color:#92400e">
					<strong>Dealer contest ({$r['dispute_at']}):</strong>
					<div style="margin-top:4px">{$r['dispute_reason']|raw}</div>
					{{if count($r['dispute_reason_attachments']) > 0}}
					{{if $r['dispute_reason_has_unembedded_images']}}<p style="font-size:12px;color:#9ca3af;margin:6px 0 4px">Attached images:</p>{{endif}}
					<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:8px">
					{{foreach $r['dispute_reason_attachments'] as $att}}
					{{if $att['is_image']}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:block;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;background:#fff"><img src="{$att['thumb_url']}" alt="{$att['file_name']}" style="display:block;max-width:140px;max-height:140px;object-fit:cover" loading="lazy"></a>{{else}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;color:#374151;text-decoration:none;font-size:13px"><i class="fa-solid fa-paperclip" aria-hidden="true"></i> {$att['file_name']}</a>{{endif}}
					{{endforeach}}
					</div>
					{{endif}}
					{{if $r['dispute_evidence']}}
					<div style="margin-top:8px"><strong>Evidence:</strong><div style="margin-top:4px">{$r['dispute_evidence']|raw}</div></div>
					{{if count($r['dispute_evidence_attachments']) > 0}}
					{{if $r['dispute_evidence_has_unembedded_images']}}<p style="font-size:12px;color:#9ca3af;margin:6px 0 4px">Attached images:</p>{{endif}}
					<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:8px">
					{{foreach $r['dispute_evidence_attachments'] as $att}}
					{{if $att['is_image']}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:block;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;background:#fff"><img src="{$att['thumb_url']}" alt="{$att['file_name']}" style="display:block;max-width:140px;max-height:140px;object-fit:cover" loading="lazy"></a>{{else}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;color:#374151;text-decoration:none;font-size:13px"><i class="fa-solid fa-paperclip" aria-hidden="true"></i> {$att['file_name']}</a>{{endif}}
					{{endforeach}}
					</div>
					{{endif}}
					{{endif}}
				</div>

				{{if $r['customer_response']}}
				<div style="background:#ecfdf5;border-left:3px solid #10b981;padding:10px 14px;border-radius:0 4px 4px 0;margin-bottom:12px;font-size:0.85em;color:#065f46">
					<strong>Customer response ({$r['customer_responded_at']}):</strong>
					<div style="margin-top:4px">{$r['customer_response']|raw}</div>
					{{if count($r['customer_response_attachments']) > 0}}
					{{if $r['customer_response_has_unembedded_images']}}<p style="font-size:12px;color:#9ca3af;margin:6px 0 4px">Attached images:</p>{{endif}}
					<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:8px">
					{{foreach $r['customer_response_attachments'] as $att}}
					{{if $att['is_image']}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:block;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;background:#fff"><img src="{$att['thumb_url']}" alt="{$att['file_name']}" style="display:block;max-width:140px;max-height:140px;object-fit:cover" loading="lazy"></a>{{else}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;color:#374151;text-decoration:none;font-size:13px"><i class="fa-solid fa-paperclip" aria-hidden="true"></i> {$att['file_name']}</a>{{endif}}
					{{endforeach}}
					</div>
					{{endif}}
					{{if $r['customer_evidence']}}
					<div style="margin-top:8px"><strong>Evidence:</strong><div style="margin-top:4px">{$r['customer_evidence']|raw}</div></div>
					{{if count($r['customer_evidence_attachments']) > 0}}
					{{if $r['customer_evidence_has_unembedded_images']}}<p style="font-size:12px;color:#9ca3af;margin:6px 0 4px">Attached images:</p>{{endif}}
					<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:8px">
					{{foreach $r['customer_evidence_attachments'] as $att}}
					{{if $att['is_image']}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:block;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;background:#fff"><img src="{$att['thumb_url']}" alt="{$att['file_name']}" style="display:block;max-width:140px;max-height:140px;object-fit:cover" loading="lazy"></a>{{else}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;color:#374151;text-decoration:none;font-size:13px"><i class="fa-solid fa-paperclip" aria-hidden="true"></i> {$att['file_name']}</a>{{endif}}
					{{endforeach}}
					</div>
					{{endif}}
					{{endif}}
				</div>
				{{else}}
				<div style="background:#f3f4f6;border:1px dashed #d1d5db;padding:10px 14px;border-radius:4px;margin-bottom:12px;font-size:0.85em;color:#6b7280">
					<em>Customer has not yet responded.</em>
				</div>
				{{endif}}

				{{if $r['dispute_status'] === 'pending_admin' || $r['dispute_status'] === 'pending_customer'}}
				<div style="display:flex;gap:8px;flex-wrap:wrap">
					<a href="{$r['uphold_url']}" class="ipsButton ipsButton--negative ipsButton--small">Uphold Dealer (exclude from avg)</a>
					<a href="{$r['dismiss_url']}" class="ipsButton ipsButton--positive ipsButton--small">Dismiss Contest (keep review)</a>
					<details style="display:inline-block">
						<summary style="cursor:pointer;padding:6px 12px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85em">Request Customer Edit</summary>
						<form method="post" action="{$r['request_edit_url']}" style="margin-top:8px;background:#f9fafb;padding:12px;border-radius:4px">
							<textarea name="admin_note" rows="3" style="width:100%;border:1px solid #ccc;border-radius:4px;padding:8px;font-size:0.9em;box-sizing:border-box" placeholder="Note to customer explaining what to clarify..."></textarea>
							<button type="submit" class="ipsButton ipsButton--normal ipsButton--small" style="margin-top:8px">Send request to customer</button>
						</form>
					</details>
				</div>
				{{endif}}

				{{if count($r['events']) > 0}}
				<div style="margin-top:16px;border-top:1px solid #e5e7eb;padding-top:12px">
					<div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:10px">Dispute history</div>
					{{foreach $r['events'] as $e}}
					<div style="display:flex;gap:12px;margin-bottom:10px;font-size:13px;color:#374151">
						<span style="color:#9ca3af;font-size:12px;flex-shrink:0;width:160px">{$e['when']}</span>
						<div style="flex:1">
							<strong style="text-transform:capitalize">{$e['actor_role']}</strong> {$e['verb']}
							{{if $e['note']}}
							<div style="font-size:12px;color:#4b5563;margin-top:4px;padding:8px 12px;background:#f9fafb;border-radius:6px;border-left:2px solid #d1d5db">{$e['note']}</div>
							{{endif}}
						</div>
					</div>
					{{endforeach}}
				</div>
				{{endif}}
			</div>

			</details>

			{{endforeach}}
		{{endif}}

	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: notSubscribed ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'notSubscribed',
		'template_data' => '$joinUrl, $contactEmail',
		'template_content' => <<<'TEMPLATE_EOT'
<div style="max-width:600px;margin:40px auto;padding:0 16px">
	<div class="ipsBox" style="text-align:center;padding:48px 32px">
		<i class="fa-solid fa-store-slash" style="font-size:3em;color:#9ca3af;margin-bottom:16px;display:block" aria-hidden="true"></i>
		<h2 style="margin:0 0 12px;font-size:1.4em;font-weight:800">No Dealer Subscription Found</h2>
		<p style="color:#6b7280;margin:0 0 24px;line-height:1.6">
			You don't have an active dealer subscription on your account.<br>
			Sign up to list your inventory and reach buyers on GunRack.deals.
		</p>
		<a href="{$joinUrl}" class="ipsButton ipsButton--primary" style="padding:12px 32px">
			<i class="fa-solid fa-store" aria-hidden="true"></i>
			<span>Become a Dealer</span>
		</a>
		<p style="margin:16px 0 0;font-size:0.85em;color:#9ca3af">
			Already subscribed? Contact us at <a href="mailto:{$contactEmail}">{$contactEmail}</a> and we'll get your account set up.
		</p>
	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: dealerShell (sidebar layout wrapper) ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'dealerShell',
		'template_data' => '$dealer, $activeTab, $nav, $body, $suspended=false, $suspensionReason=\'\'',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="gdDealerApp">

	<div class="gdMobileBar">
		<div class="gdMobileBar__brand">
			<span class="gdSidebar__brandMark">GD</span>
			<div>
				<div class="gdSidebar__brandText">{$dealer['dealer_name']}</div>
				<div class="gdSidebar__brandSub">{$dealer['tier_label']}</div>
			</div>
		</div>
		<a href="#gdDrawer" class="gdMobileBar__menuBtn" aria-label="Open menu">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
		</a>
	</div>

	<div class="gdDrawer" id="gdDrawer">
		<div class="gdDrawer__panel">
			<a href="#" class="gdDrawer__close" aria-label="Close">&times;</a>
			{template="dealerSidebar" group="dealers" app="gddealer" params="$dealer, $activeTab, $nav"}
		</div>
	</div>

	<div class="gdDealerShell">
		<aside class="gdSidebar">
			{template="dealerSidebar" group="dealers" app="gddealer" params="$dealer, $activeTab, $nav"}
		</aside>

		<main class="gdMain">
			{$body|raw}
		</main>
	</div>

</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: dealerSidebar (reused in sidebar + mobile drawer) ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'dealerSidebar',
		'template_data' => '$dealer, $activeTab, $nav',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="gdSidebar__brand">
	<span class="gdSidebar__brandMark">GD</span>
	<div>
		<div class="gdSidebar__brandText">gunrack.deals</div>
		<div class="gdSidebar__brandSub">Dealer Dashboard</div>
	</div>
</div>

{{foreach $nav as $groupKey => $group}}
<div class="gdNavGroup">
	<div class="gdNavGroup__label">{$group['label']}</div>
	{{foreach $group['items'] as $item}}
	<a href="{$item['url']}" class="gdNavItem {expression="$activeTab === $item['key'] ? 'is-active' : ''"}">
		{template="dealerNavIcon" group="dealers" app="gddealer" params="$item['icon']"}
		<span class="gdNavItem__label">{$item['label']}</span>
		{{if $item['badge']}}
		<span class="gdNavItem__count is-{$item['badge']['variant']}">{$item['badge']['count']}</span>
		{{endif}}
	</a>
	{{endforeach}}
</div>
{{endforeach}}

<div class="gdSidebar__footer">
	<div class="gdSidebar__user">
		<span class="gdSidebar__avatar">
			{{if $dealer['avatar_url']}}
				<img src="{$dealer['avatar_url']}" alt="">
			{{else}}
				{expression="mb_substr($dealer['dealer_name'], 0, 2)"}
			{{endif}}
		</span>
		<div class="gdSidebar__userInfo">
			<div class="gdSidebar__userName">{$dealer['dealer_name']}</div>
			<div class="gdSidebar__userRole">{$dealer['tier_label']} dealer</div>
		</div>
	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: dealerNavIcon (SVG icon map for sidebar nav) ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'dealerNavIcon',
		'template_data' => '$icon',
		'template_content' => <<<'TEMPLATE_EOT'
{{if $icon === 'dashboard'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
{{elseif $icon === 'listings'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/></svg>
{{elseif $icon === 'reviews'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
{{elseif $icon === 'feed'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
{{elseif $icon === 'wizard'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4V2"/><path d="M15 16v-2"/><path d="M8 9h2"/><path d="M20 9h2"/><path d="M17.8 11.8 19 13"/><path d="M15 9h0"/><path d="M17.8 6.2 19 5"/><path d="M3 21l9-9"/><path d="M12.2 6.2 11 5"/></svg>
{{elseif $icon === 'validator'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
{{elseif $icon === 'unmatched'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
{{elseif $icon === 'analytics'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
{{elseif $icon === 'billing'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
{{elseif $icon === 'help'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>
{{elseif $icon === 'support'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
{{elseif $icon === 'profile'}}
<svg class="gdNavItem__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
{{endif}}
TEMPLATE_EOT,
	],

	/* ===== FRONT: overview tab body ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'overview',
		'template_data' => '$data',
		'template_content' => <<<'TEMPLATE_EOT'
{{if isset($data['announcement']) && $data['announcement']['show']}}
<div class="ipsMessage ipsMessage--{$data['announcement']['style']} gdDealerAnnounce" data-gd-announce data-version="{$data['announcement']['version']}" data-dismissurl="{$data['announce_dismiss_url']}" data-csrfkey="{$data['csrfKey']}">
	<div class="gdDealerAnnounce__body">{$data['announcement']['body']|raw}</div>
	<button type="button" class="gdDealerAnnounce__close" data-gd-announce-close aria-label="Dismiss">&times;</button>
</div>
{{endif}}
<div class="gdPageHeader">
    <div class="gdPageHeader__titleBlock">
        <h1 class="gdPageHeader__title">Overview</h1>
        <p class="gdPageHeader__sub">Last updated {expression="date('g:i A')"}</p>
    </div>
    <div class="gdPageHeader__actions">
        <a href="{$data['tab_urls']['analytics']}" class="gdBtn gdBtn--secondary">Analytics</a>
        <a href="{$data['tab_urls']['feedSettings']}" class="gdBtn gdBtn--primary">Upload feed</a>
    </div>
</div>

<div class="gdIdentity">
    <div class="gdIdentity__left">
        <div class="gdIdentity__avatar">
            {{if $data['dealer']['avatar_url']}}
                <img src="{$data['dealer']['avatar_url']}" alt="">
            {{else}}
                {expression="mb_substr($data['dealer']['dealer_name'], 0, 2)"}
            {{endif}}
        </div>
        <div>
            <div class="gdIdentity__name">
                <h2>{$data['dealer']['dealer_name']}</h2>
                <span class="gdTierBadge gdTierBadge--{$data['dealer']['subscription_tier']}">{$data['dealer']['tier_label']} dealer</span>
            </div>
            <p class="gdIdentity__url">gunrack.deals/dealers/{$data['dealer']['dealer_slug']}
                <button type="button" class="gdBtn gdBtn--secondary" style="margin-left:8px;padding:2px 10px;font-size:11px;vertical-align:middle" data-gd-regenerate-slug data-preview-url="{$data['regenerate_preview_url']}" data-confirm-url="{$data['regenerate_confirm_url']}">Regenerate</button>
            </p>
        </div>
    </div>
    <div class="gdIdentity__actions">
        <a href="{$data['public_profile_url']}" class="gdBtn gdBtn--secondary">View public profile</a>
        <a href="{$data['tab_urls']['subscription']}" class="gdBtn gdBtn--secondary">Manage subscription</a>
    </div>
</div>

{{if $data['steps_pct'] < 100}}
<div class="gdSetupCard">
    <div class="gdSetupCard__head">
        <div>
            <div class="gdSetupCard__title">Finish setting up your storefront</div>
            <div class="gdSetupCard__sub">{$data['steps_done']} of {$data['steps_total']} steps complete</div>
        </div>
        <div class="gdSetupCard__pct">{$data['steps_pct']}%</div>
    </div>
    <div class="gdProgress">
        <div class="gdProgress__fill" style="width: {$data['steps_pct']}%"></div>
    </div>
    <div class="gdSetupSteps">
        {{foreach $data['setup_steps'] as $step}}
        <div class="gdStep gdStep--{$step['state']}">
            <span class="gdStep__icon">
                {{if $step['state'] === 'done'}}&#10003;{{elseif $step['state'] === 'active'}}!{{endif}}
            </span>
            <span class="gdStep__text">{$step['label']}</span>
        </div>
        {{endforeach}}
    </div>
</div>
{{endif}}

<div class="gdKpiGrid">
    <div class="gdKpi">
        <div class="gdKpi__label">Active listings</div>
        <div class="gdKpi__value">{expression="number_format($data['stats']['active'])"} <span style="font-size:0.55em;color:#6b7280;font-weight:400">/ {$data['stats']['cap_label']}</span></div>
    </div>
    <div class="gdKpi">
        <div class="gdKpi__label">Out of stock</div>
        <div class="gdKpi__value">{expression="number_format($data['stats']['out'])"}</div>
    </div>
    <div class="gdKpi">
        <div class="gdKpi__label">Unmatched UPCs</div>
        <div class="gdKpi__value">{expression="number_format($data['stats']['unmatched'])"}</div>
    </div>
    <div class="gdKpi">
        <div class="gdKpi__label">Clicks &middot; 30d</div>
        <div class="gdKpi__value">{expression="number_format($data['stats']['clicks_30'])"}</div>
    </div>
</div>

<div class="gdContentSplit">

    <div>
        <div class="gdPanel">
            <div class="gdPanel__head">
                <div>
                    <div class="gdPanel__title">Last feed import</div>
                    <div class="gdPanel__sub">Most recent import activity</div>
                </div>
                <a href="{$data['tab_urls']['feedSettings']}" class="gdPanel__link">Feed settings &rarr;</a>
            </div>
            {{if $data['last_import']}}
            <table class="gdTable">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Status</th>
                        <th class="is-num">Records</th>
                        <th class="is-num">Updated</th>
                        <th class="is-num">Unmatched</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{$data['last_import']['when_label']}</td>
                        <td><span class="gdLogStatus gdLogStatus--{$data['last_import']['status']}">{expression="ucfirst($data['last_import']['status'])"}</span></td>
                        <td class="is-num">{expression="number_format($data['last_import']['records'])"}</td>
                        <td class="is-num">{expression="number_format($data['last_import']['updated'])"}</td>
                        <td class="is-num">{expression="number_format($data['last_import']['unmatched'])"}</td>
                    </tr>
                </tbody>
            </table>
            {{else}}
            <p style="color: var(--gd-text-subtle); font-size: 13px; margin: 0;">No feed imports yet. <a href="{$data['tab_urls']['feedSettings']}" style="color: var(--gd-brand)">Configure your feed &rarr;</a></p>
            {{endif}}
        </div>
    </div>

    <div>
        <div class="gdRailCard">
            <div class="gdRailCard__title">Quick actions</div>
            <div class="gdActionList">
                <a href="{$data['tab_urls']['feedSettings']}" class="gdActionList__item">
                    <span>Upload product feed</span>
                    <span class="gdActionList__arrow">&rarr;</span>
                </a>
                <a href="{$data['tab_urls']['unmatched']}" class="gdActionList__item">
                    <span>Resolve unmatched UPCs</span>
                    {{if $data['stats']['unmatched'] > 0}}
                    <span class="gdActionList__count is-urgent">{$data['stats']['unmatched']}</span>
                    {{else}}
                    <span class="gdActionList__arrow">&rarr;</span>
                    {{endif}}
                </a>
                <a href="{$data['tab_urls']['reviews']}" class="gdActionList__item">
                    <span>Respond to reviews</span>
                    {{if $data['dealer']['new_reviews'] > 0}}
                    <span class="gdActionList__count is-warn">{$data['dealer']['new_reviews']}</span>
                    {{else}}
                    <span class="gdActionList__arrow">&rarr;</span>
                    {{endif}}
                </a>
                <a href="{$data['tab_urls']['analytics']}" class="gdActionList__item">
                    <span>View analytics</span>
                    <span class="gdActionList__arrow">&rarr;</span>
                </a>
                <a href="{$data['tab_urls']['subscription']}" class="gdActionList__item">
                    <span>Manage subscription</span>
                    <span class="gdActionList__arrow">&rarr;</span>
                </a>
            </div>
        </div>
    </div>

</div>

<!-- v1.0.183: Regenerate slug confirmation modal (overview page) -->
<div id="gd-regen-slug-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center">
	<div style="background:#fff;border-radius:8px;padding:24px;max-width:520px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.2)">
		<h3 style="margin:0 0 16px;font-size:1.2em;font-weight:600">Regenerate URL slug</h3>
		<div style="margin-bottom:12px">
			<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Current slug</div>
			<code id="gd-regen-old" style="display:block;background:#f4f4f4;padding:8px 12px;border-radius:4px;font-size:0.95em">&mdash;</code>
		</div>
		<div style="margin-bottom:12px">
			<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">New slug</div>
			<code id="gd-regen-new" style="display:block;background:#e8f5e9;padding:8px 12px;border-radius:4px;font-size:0.95em">&mdash;</code>
		</div>
		<p style="margin:16px 0;padding:12px;background:#fff8e1;border-left:3px solid #ffa726;font-size:0.9em;line-height:1.5"><strong>Heads up:</strong> the old URL will 301-redirect to the new URL. Already-shared links keep working. The new slug is generated from your current saved business name.</p>
		<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
			<button type="button" class="gdBtn gdBtn--secondary" data-gd-regen-cancel>Cancel</button>
			<form id="gd-regen-confirm-form" method="POST" action="" style="display:inline">
				<input type="hidden" name="csrfKey" id="gd-regen-csrf" value="">
				<button type="submit" class="gdBtn gdBtn--primary" id="gd-regen-submit">Regenerate slug</button>
			</form>
		</div>
	</div>
</div>
<script>
(function(){
	var triggers = document.querySelectorAll('[data-gd-regenerate-slug]');
	var modal = document.getElementById('gd-regen-slug-modal');
	var oldEl = document.getElementById('gd-regen-old');
	var newEl = document.getElementById('gd-regen-new');
	var form = document.getElementById('gd-regen-confirm-form');
	var csrfInput = document.getElementById('gd-regen-csrf');
	var submitBtn = document.getElementById('gd-regen-submit');
	if ( !modal || !triggers.length ) { return; }

	function openModal(trigger){
		var previewUrl = trigger.getAttribute('data-preview-url');
		var confirmUrl = trigger.getAttribute('data-confirm-url');
		oldEl.textContent = 'Loading...';
		newEl.textContent = 'Loading...';
		submitBtn.disabled = false;
		submitBtn.textContent = 'Regenerate slug';
		modal.style.display = 'flex';
		fetch(previewUrl, {credentials: 'same-origin'})
			.then(function(r){return r.json();})
			.then(function(d){
				oldEl.textContent = d.old_slug || '(none)';
				if ( !d.would_change ) {
					newEl.textContent = '(no change needed)';
					submitBtn.disabled = true;
					submitBtn.textContent = 'No change needed';
				} else {
					newEl.textContent = d.new_slug;
					submitBtn.disabled = false;
					submitBtn.textContent = 'Regenerate slug';
				}
				/* v1.0.184 fix: csrfKey is already baked into confirmUrl via
				 * the controller's ->csrf() call. Extract it directly and
				 * stuff it into the hidden input AND keep it in the action
				 * URL - both belt and suspenders for IPS's csrfCheck(). */
				form.action = confirmUrl;
				var csrfMatch = confirmUrl.match(/[?&]csrfKey=([^&]+)/);
				if ( csrfMatch ) {
					csrfInput.value = decodeURIComponent(csrfMatch[1]);
				}
			})
			.catch(function(){
				oldEl.textContent = 'Error';
				newEl.textContent = 'Could not load preview.';
				submitBtn.disabled = true;
			});
	}

	function closeModal(){ modal.style.display = 'none'; }

	triggers.forEach(function(t){
		t.addEventListener('click', function(e){ e.preventDefault(); openModal(t); });
	});

	modal.addEventListener('click', function(e){
		if ( e.target === modal || e.target.hasAttribute('data-gd-regen-cancel') ) { closeModal(); }
	});
})();
</script>

{{if !empty( $data['verified_badge']['show'] )}}
<div class="gdPanel" id="gdBadgePicker" data-badges='{$data['verified_badge']['badges_json']|raw}' data-profile-url="{$data['verified_badge']['profile_url']}" style="margin-top:24px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;">
	<div style="margin-bottom:18px;">
		<h2 style="margin:0 0 4px;font-size:16px;font-weight:600;color:#0f172a;display:flex;align-items:center;gap:8px;">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="#10b981" style="flex-shrink:0;"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
			Get your verified-dealer badge
		</h2>
		<p style="margin:0;font-size:13px;color:#64748b;">Show customers you're a verified Gun Rack Deals dealer. Pick a style and paste the embed code on your website.</p>
	</div>

	<div style="display:grid;grid-template-columns:1fr;gap:16px;">
		<div>
			<label for="gd_badge_picker" style="display:block;font-size:13px;font-weight:600;color:#0f172a;margin-bottom:6px;">Badge style</label>
			<select id="gd_badge_picker" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font:inherit;background:#fff;">
				{{foreach $data['verified_badge']['badges'] as $b}}
				<option value="{$b['id']}">{$b['label']}</option>
				{{endforeach}}
			</select>
		</div>

		<div>
			<div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px;">Live preview</div>
			<div style="padding:24px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;display:flex;align-items:center;justify-content:center;min-height:90px;">
				<a id="gd_badge_preview_link" href="{$data['verified_badge']['profile_url']}" target="_blank" rel="noopener">
					<img id="gd_badge_preview_img" src="" alt="Verified Gun Rack Deals dealer">
				</a>
			</div>
		</div>

		<div>
			<div style="display:flex;gap:6px;border-bottom:1px solid #e5e7eb;margin-bottom:0;">
				<button type="button" data-tab="html" class="gdBadgeTab" style="padding:8px 14px;border:0;border-bottom:2px solid #1e40af;background:none;color:#1e40af;font-weight:600;font-size:13px;cursor:pointer;">HTML embed</button>
				<button type="button" data-tab="md" class="gdBadgeTab" style="padding:8px 14px;border:0;border-bottom:2px solid transparent;background:none;color:#64748b;font-weight:500;font-size:13px;cursor:pointer;">Markdown</button>
				<button type="button" data-tab="link" class="gdBadgeTab" style="padding:8px 14px;border:0;border-bottom:2px solid transparent;background:none;color:#64748b;font-weight:500;font-size:13px;cursor:pointer;">Direct link</button>
			</div>
			<div style="position:relative;">
				<textarea id="gd_badge_code" readonly rows="4" style="width:100%;padding:12px;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 6px 6px;font-family:ui-monospace,'SF Mono',Menlo,monospace;font-size:12px;color:#0f172a;background:#f8fafc;resize:vertical;box-sizing:border-box;"></textarea>
				<button type="button" id="gd_badge_copy_btn" style="position:absolute;top:8px;right:8px;padding:6px 12px;background:#1e40af;color:#fff;border:0;border-radius:5px;font-size:12px;font-weight:600;cursor:pointer;">Copy</button>
			</div>
		</div>
	</div>
</div>
{{endif}}
TEMPLATE_EOT,
	],

	/* ===== FRONT: dashboardCustomize ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'dashboardCustomize',
		'template_data' => '$data',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="gdPageHeader">
    <div class="gdPageHeader__titleBlock">
        <h1 class="gdPageHeader__title">Edit dealer profile</h1>
        <p class="gdPageHeader__sub">This is what customers see on your public storefront. Take a few minutes to make it yours.</p>
    </div>
    <div class="gdPageHeader__actions">
        <a href="{$data['public_profile_url']}" target="_blank" class="gdBtn gdBtn--secondary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            View public profile
        </a>
    </div>
</div>

<form method="post" action="{$data['save_url']}" class="gdProfileForm">
    <input type="hidden" name="csrfKey" value="{$data['csrf_key']}">

    <div class="gdPanel">
        <div class="gdPanel__head">
            <div>
                <div class="gdPanel__title">Identity</div>
                <div class="gdPanel__sub">Business name, logo, cover photo, and tagline.</div>
            </div>
        </div>

        <div class="gdField">
            <label class="gdField__label gdField__label--required">Business name</label>
            <input type="text" name="dealer_name" value="{$data['profile']['dealer_name']}" maxlength="150" class="gdInput" required>
        </div>

        <div class="gdField">
            <label class="gdField__label">Tagline</label>
            <input type="text" name="tagline" value="{$data['profile']['tagline']}" maxlength="160" class="gdInput" placeholder="One short sentence about what makes you different">
            <div class="gdField__hint">Appears under your business name on the public profile. Keep it brief.</div>
        </div>

        <div class="gdField">
            <label class="gdField__label">Logo URL</label>
            <input type="url" name="logo_url" value="{$data['profile']['logo_url']}" maxlength="500" class="gdInput gdInput--mono" placeholder="https://example.com/logo.png">
            <div class="gdField__hint">Square image recommended, 200x200 or larger. Paste a direct image URL.</div>
        </div>

        <div class="gdField">
            <label class="gdField__label">Cover photo URL</label>
            <input type="url" name="cover_url" value="{$data['profile']['cover_url']}" maxlength="500" class="gdInput gdInput--mono" placeholder="https://example.com/cover.jpg">
            <div class="gdField__hint">Wide banner image, 1600x400 recommended.</div>
        </div>

        <div class="gdField">
            <label class="gdField__label">About / description</label>
            <textarea name="about" rows="5" class="gdTextarea" placeholder="Tell customers about your shop — history, specialties, what makes you different.">{$data['profile']['about']}</textarea>
        </div>

        <div class="gdField">
            <label class="gdField__label">Brand accent color</label>
            <div class="gdColorPicker">
                <input type="color" name="brand_color_picker" value="{$data['profile']['brand_color']}" class="gdColorPicker__swatch">
                <input type="text" name="brand_color" value="{$data['profile']['brand_color']}" class="gdInput gdInput--mono" style="max-width:120px" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$">
            </div>
            <div class="gdField__hint">Used as the accent color on your public profile.</div>
        </div>
    </div>

    <div class="gdPanel">
        <div class="gdPanel__head">
            <div>
                <div class="gdPanel__title">Contact</div>
                <div class="gdPanel__sub">How customers reach you.</div>
            </div>
        </div>

        <div class="gdField gdField--split">
            <div>
                <label class="gdField__label">Phone</label>
                <input type="tel" name="public_phone" value="{$data['profile']['public_phone']}" maxlength="32" class="gdInput" placeholder="(555) 123-4567">
            </div>
            <div>
                <label class="gdField__label">Email</label>
                <input type="email" name="public_email" value="{$data['profile']['public_email']}" maxlength="160" class="gdInput" placeholder="sales@yourshop.com">
            </div>
        </div>

        <div class="gdField">
            <label class="gdField__label">Website</label>
            <input type="url" name="website_url" value="{$data['profile']['website_url']}" maxlength="500" class="gdInput" placeholder="https://yourshop.com">
        </div>
    </div>

    <div class="gdPanel" style="margin-bottom:24px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;">
        <div class="gdPanel__head" style="margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #f1f5f9;">
            <h2 style="margin:0 0 4px 0;font-size:16px;font-weight:600;color:#0f172a;">FFL license</h2>
            <p style="margin:0;font-size:13px;color:#64748b;">Required for the verified badge on your public profile. Admin reviews within 24 hours.</p>
        </div>

        {{if $data['profile']['ffl_status'] === 'pending'}}
        <div style="background:#fef3c7;border:1px solid #fde68a;color:#92400e;padding:12px 14px;border-radius:6px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <div style="font-size:13px;line-height:1.5;"><strong>Pending review.</strong> We'll email you once a site admin has verified your license (usually within 24 hours).</div>
        </div>
        {{elseif $data['profile']['ffl_status'] === 'verified'}}
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:12px 14px;border-radius:6px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink:0;margin-top:1px;"><path d="M12 2 4 5v6c0 5.5 3.8 10.7 8 12 4.2-1.3 8-6.5 8-12V5l-8-3zm-1.4 14.2L7 12.6l1.4-1.4 2.2 2.2 4.4-4.4L16.4 10l-5.8 6.2z"/></svg>
            <div style="font-size:13px;line-height:1.5;"><strong>FFL verified.</strong> The green checkmark now appears on your public profile.</div>
        </div>
        {{elseif $data['profile']['ffl_status'] === 'rejected'}}
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:12px 14px;border-radius:6px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;font-size:13px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <strong>Rejected (attempt {$data['profile']['ffl_rejection_count']} of 3).</strong>
            </div>
            <div style="font-size:13px;line-height:1.5;margin-left:28px;">Reason: {$data['profile']['ffl_rejection_reason']}</div>
            <div style="font-size:12px;margin-top:8px;margin-left:28px;opacity:0.85;">Update your FFL number or license URL below and save to re-submit for review.</div>
        </div>
        {{elseif $data['profile']['ffl_status'] === 'blocked'}}
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:12px 14px;border-radius:6px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div style="font-size:13px;line-height:1.5;"><strong>Verification blocked.</strong> Your submission has been rejected 3 times. Please contact support to resolve.</div>
        </div>
        {{endif}}

        <div class="gdField">
            <label for="gd_ffl_number" class="gdField__label">FFL number</label>
            <input type="text" id="gd_ffl_number" name="ffl_number" class="gdInput gdInput--mono" pattern="\d-\d{2}-\d{5}" placeholder="3-37-06855" value="{$data['profile']['ffl_number']}" {{if $data['profile']['ffl_blocked']}}disabled{{endif}}>
            <div class="gdField__hint">Format: X-XX-XXXXX (shown on your Federal Firearms License, e.g. 3-37-06855).</div>
        </div>

        <div class="gdField">
            <label for="gd_ffl_license_url" class="gdField__label">FFL license URL</label>
            <input type="url" id="gd_ffl_license_url" name="ffl_license_url" class="gdInput gdInput--mono" placeholder="https://drive.google.com/..." value="{$data['profile']['ffl_license_url']}" {{if $data['profile']['ffl_blocked']}}disabled{{endif}}>
            <div class="gdField__hint">Upload your FFL license to Dropbox, Google Drive, or your own host and paste the public/shareable direct link here.</div>
        </div>
    </div>

    <div class="gdPanel">
        <div class="gdPanel__head">
            <div>
                <div class="gdPanel__title">Location</div>
                <div class="gdPanel__sub">Helps customers find you. You can show just city &amp; state publicly if you prefer.</div>
            </div>
        </div>

        <div class="gdField">
            <label class="gdField__label">Street address</label>
            <input type="text" name="address_street" value="{$data['profile']['address_street']}" maxlength="255" class="gdInput" placeholder="123 Main St">
        </div>

        <div class="gdField gdField--split3">
            <div style="flex:2">
                <label class="gdField__label">City</label>
                <input type="text" name="address_city" value="{$data['profile']['address_city']}" maxlength="100" class="gdInput">
            </div>
            <div>
                <label class="gdField__label">State</label>
                <select name="address_state" class="gdSelect">
                    <option value="">&mdash;</option>
                    {{foreach $data['states'] as $code => $name}}
                    <option value="{$code}" {expression="$data['profile']['address_state'] === $code ? 'selected' : ''"}>{$code}</option>
                    {{endforeach}}
                </select>
            </div>
            <div>
                <label class="gdField__label">ZIP</label>
                <input type="text" name="address_zip" value="{$data['profile']['address_zip']}" maxlength="10" class="gdInput">
            </div>
        </div>

        <label class="gdCheckbox">
            <input type="checkbox" name="address_public" value="1" {expression="$data['profile']['address_public'] ? 'checked' : ''"}>
            <span>Show full street address on my public profile</span>
        </label>
        <div class="gdField__hint" style="margin-top:2px;margin-left:24px">When off, customers see only city &amp; state.</div>
    </div>

    <div class="gdPanel">
        <div class="gdPanel__head">
            <div>
                <div class="gdPanel__title">Hours</div>
                <div class="gdPanel__sub">When customers can reach you or visit your storefront.</div>
            </div>
        </div>

        <div class="gdHoursGrid">
            {{foreach $data['profile']['hours'] as $dayKey => $d}}
            <div class="gdHoursRow">
                <div class="gdHoursRow__day">{$d['label']}</div>
                <label class="gdCheckbox gdCheckbox--inline">
                    <input type="checkbox" name="hours_{$dayKey}_closed" value="1" {expression="$d['closed'] ? 'checked' : ''"}>
                    <span>Closed</span>
                </label>
                <input type="time" name="hours_{$dayKey}_open" value="{$d['open']}" class="gdInput gdInput--sm" {expression="$d['closed'] ? 'disabled' : ''"}>
                <span class="gdHoursRow__sep">to</span>
                <input type="time" name="hours_{$dayKey}_close" value="{$d['close']}" class="gdInput gdInput--sm" {expression="$d['closed'] ? 'disabled' : ''"}>
            </div>
            {{endforeach}}
        </div>
    </div>

    <div class="gdPanel">
        <div class="gdPanel__head">
            <div>
                <div class="gdPanel__title">Social links</div>
                <div class="gdPanel__sub">Paste full URLs to your social profiles. Blank fields won't display.</div>
            </div>
        </div>

        <div class="gdField gdField--split">
            <div>
                <label class="gdField__label">Facebook</label>
                <input type="url" name="social_facebook" value="{$data['profile']['social_facebook']}" maxlength="500" class="gdInput" placeholder="https://facebook.com/yourshop">
            </div>
            <div>
                <label class="gdField__label">Instagram</label>
                <input type="url" name="social_instagram" value="{$data['profile']['social_instagram']}" maxlength="500" class="gdInput" placeholder="https://instagram.com/yourshop">
            </div>
        </div>

        <div class="gdField gdField--split">
            <div>
                <label class="gdField__label">YouTube</label>
                <input type="url" name="social_youtube" value="{$data['profile']['social_youtube']}" maxlength="500" class="gdInput" placeholder="https://youtube.com/@yourshop">
            </div>
            <div>
                <label class="gdField__label">X (Twitter)</label>
                <input type="url" name="social_twitter" value="{$data['profile']['social_twitter']}" maxlength="500" class="gdInput" placeholder="https://x.com/yourshop">
            </div>
        </div>

        <div class="gdField">
            <label class="gdField__label">TikTok</label>
            <input type="url" name="social_tiktok" value="{$data['profile']['social_tiktok']}" maxlength="500" class="gdInput" placeholder="https://tiktok.com/@yourshop">
        </div>
    </div>

    <div class="gdPanel">
        <div class="gdPanel__head">
            <div>
                <div class="gdPanel__title">Payment methods accepted</div>
                <div class="gdPanel__sub">Shown as icons on your public profile.</div>
            </div>
        </div>

        <div class="gdCheckboxGrid">
            {{foreach $data['payment_options'] as $key => $name}}
            <label class="gdCheckbox gdCheckbox--card">
                <input type="checkbox" name="payment_methods[]" value="{$key}" {expression="in_array($key, $data['profile']['payment_methods'], true) ? 'checked' : ''"}>
                <span>{$name}</span>
            </label>
            {{endforeach}}
        </div>
    </div>

    <div class="gdPanel">
        <div class="gdPanel__head">
            <div>
                <div class="gdPanel__title">Policies</div>
                <div class="gdPanel__sub">Shown on your public profile. Be specific so customers know what to expect.</div>
            </div>
        </div>

        <div class="gdField">
            <label class="gdField__label">Shipping policy</label>
            <textarea name="shipping_policy" rows="4" class="gdTextarea" placeholder="Carriers, transit times, signature requirements, FFL shipping details...">{$data['profile']['shipping_policy']}</textarea>
        </div>

        <div class="gdField">
            <label class="gdField__label">Return policy</label>
            <textarea name="return_policy" rows="4" class="gdTextarea" placeholder="Return window, restocking fees, conditions...">{$data['profile']['return_policy']}</textarea>
        </div>

        <div class="gdField">
            <label class="gdField__label">Additional notes <span style="color:var(--gd-text-faint);font-weight:400">(optional)</span></label>
            <textarea name="additional_notes" rows="3" class="gdTextarea" placeholder="Anything else customers should know?">{$data['profile']['additional_notes']}</textarea>
        </div>
    </div>

    <div class="gdPanel">
        <div class="gdPanel__head">
            <div>
                <div class="gdPanel__title">Dashboard preferences</div>
                <div class="gdPanel__sub">Control what you see on your Overview tab.</div>
            </div>
        </div>

        <div class="gdSubsection__label">Overview cards</div>

        <label class="gdWidgetRow">
            <span class="gdWidgetRow__icon" style="background:var(--gd-success-bg);color:var(--gd-success)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
            <div class="gdWidgetRow__body"><div class="gdWidgetRow__title">Active listings</div><div class="gdWidgetRow__desc">Your active, in-stock listings</div></div>
            <span class="gdToggle"><input type="checkbox" name="show_active" value="1" {expression="$data['prefs']['show_active'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
        </label>

        <label class="gdWidgetRow">
            <span class="gdWidgetRow__icon" style="background:var(--gd-warn-bg);color:var(--gd-warn)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg></span>
            <div class="gdWidgetRow__body"><div class="gdWidgetRow__title">Out of stock</div><div class="gdWidgetRow__desc">Listings marked out of stock</div></div>
            <span class="gdToggle"><input type="checkbox" name="show_outofstock" value="1" {expression="$data['prefs']['show_outofstock'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
        </label>

        <label class="gdWidgetRow">
            <span class="gdWidgetRow__icon" style="background:var(--gd-danger-bg);color:var(--gd-danger)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></span>
            <div class="gdWidgetRow__body"><div class="gdWidgetRow__title">Unmatched UPCs</div><div class="gdWidgetRow__desc">Feed products not matched to catalog</div></div>
            <span class="gdToggle"><input type="checkbox" name="show_unmatched" value="1" {expression="$data['prefs']['show_unmatched'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
        </label>

        <label class="gdWidgetRow">
            <span class="gdWidgetRow__icon" style="background:var(--gd-brand-light);color:var(--gd-brand)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/></svg></span>
            <div class="gdWidgetRow__body"><div class="gdWidgetRow__title">Clicks &mdash; 7 days</div><div class="gdWidgetRow__desc">Click-throughs last week</div></div>
            <span class="gdToggle"><input type="checkbox" name="show_clicks_7d" value="1" {expression="$data['prefs']['show_clicks_7d'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
        </label>

        <label class="gdWidgetRow">
            <span class="gdWidgetRow__icon" style="background:var(--gd-brand-light);color:var(--gd-brand)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/></svg></span>
            <div class="gdWidgetRow__body"><div class="gdWidgetRow__title">Clicks &mdash; 30 days</div><div class="gdWidgetRow__desc">Click-throughs last month</div></div>
            <span class="gdToggle"><input type="checkbox" name="show_clicks_30d" value="1" {expression="$data['prefs']['show_clicks_30d'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
        </label>

        <label class="gdWidgetRow">
            <span class="gdWidgetRow__icon" style="background:var(--gd-surface-muted);color:var(--gd-text-muted)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span>
            <div class="gdWidgetRow__body"><div class="gdWidgetRow__title">Last import</div><div class="gdWidgetRow__desc">Most recent feed import summary</div></div>
            <span class="gdToggle"><input type="checkbox" name="show_last_import" value="1" {expression="$data['prefs']['show_last_import'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
        </label>

        <label class="gdWidgetRow">
            <span class="gdWidgetRow__icon" style="background:var(--gd-surface-muted);color:var(--gd-text-muted)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/></svg></span>
            <div class="gdWidgetRow__body"><div class="gdWidgetRow__title">Profile URL</div><div class="gdWidgetRow__desc">Quick-copy public profile link</div></div>
            <span class="gdToggle"><input type="checkbox" name="show_profile_url" value="1" {expression="$data['prefs']['show_profile_url'] ? 'checked' : ''"}><span class="gdToggle__slider"></span></span>
        </label>

        <div class="gdSubsection__label" style="margin-top:20px">Card theme</div>

        <div class="gdThemePicker">
            <label class="gdThemeOption {expression="$data['prefs']['card_theme'] === 'default' ? 'is-selected' : ''"}">
                <input type="radio" name="card_theme" value="default" {expression="$data['prefs']['card_theme'] === 'default' ? 'checked' : ''"}>
                <div class="gdThemeOption__preview gdThemeOption__preview--default"><div class="gdThemeOption__kpi"><div class="gdThemeOption__label">Active</div><div class="gdThemeOption__value">1,234</div></div></div>
                <div class="gdThemeOption__name">Default</div>
                <div class="gdThemeOption__desc">Clean and neutral</div>
            </label>
            <label class="gdThemeOption {expression="$data['prefs']['card_theme'] === 'dark' ? 'is-selected' : ''"}">
                <input type="radio" name="card_theme" value="dark" {expression="$data['prefs']['card_theme'] === 'dark' ? 'checked' : ''"}>
                <div class="gdThemeOption__preview gdThemeOption__preview--dark"><div class="gdThemeOption__kpi"><div class="gdThemeOption__label">Active</div><div class="gdThemeOption__value">1,234</div></div></div>
                <div class="gdThemeOption__name">Dark</div>
                <div class="gdThemeOption__desc">High contrast</div>
            </label>
            <label class="gdThemeOption {expression="$data['prefs']['card_theme'] === 'accent' ? 'is-selected' : ''"}">
                <input type="radio" name="card_theme" value="accent" {expression="$data['prefs']['card_theme'] === 'accent' ? 'checked' : ''"}>
                <div class="gdThemeOption__preview gdThemeOption__preview--accent"><div class="gdThemeOption__kpi"><div class="gdThemeOption__label">Active</div><div class="gdThemeOption__value">1,234</div></div></div>
                <div class="gdThemeOption__name">Accent</div>
                <div class="gdThemeOption__desc">Brand-colored</div>
            </label>
        </div>
    </div>

    <div class="gdProfileForm__footer">
        <a href="{$data['forum_profile_url']}" class="gdProfileForm__footerLink">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Edit your forum profile &rarr;
        </a>
    </div>

    <div class="gdCustomizeActions">
        <a href="{$data['cancel_url']}" class="gdBtn gdBtn--secondary">Cancel</a>
        <button type="submit" class="gdBtn gdBtn--primary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save changes
        </button>
    </div>

</form>

{{if isset($data['reset_action_url']) && $data['reset_action_url']}}
<div class="gdPanel" style="margin-top:32px;border-left:4px solid #dc2626;background:#fef2f2">
    <div class="gdPanel__head">
        <div>
            <div class="gdPanel__title" style="color:#dc2626">{lang="gddealer_front_customize_danger_zone_title"}</div>
            <div class="gdPanel__sub">{lang="gddealer_front_customize_danger_zone_desc"}</div>
        </div>
    </div>

    <div style="font-size:13px;line-height:1.6;margin-bottom:16px">
        <strong style="color:#dc2626">{lang="gddealer_front_customize_danger_zone_wiped"}</strong> feed URL, feed mapping, all listings, all unmatched UPCs, all category mappings, all import logs, all price history.<br>
        <strong style="color:#16a34a">{lang="gddealer_front_customize_danger_zone_kept"}</strong> dealer profile info, FFL information, subscription, founding status.
    </div>

    <form method="post" action="{$data['reset_action_url']}">
        <input type="hidden" name="csrfKey" value="{$data['csrf_key']}">
        <div class="gdField">
            <label class="gdField__label">{lang="gddealer_front_customize_danger_zone_confirm_label"}</label>
            <div class="gdField__hint" style="margin-bottom:6px">{$data['dealer_name_escaped']}</div>
            <input type="text" name="confirm_name" class="gdInput" style="max-width:360px" autocomplete="off" required>
        </div>
        <button type="submit" class="gdBtn" style="background:#dc2626;color:#fff;border:none;margin-top:8px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            {lang="gddealer_front_customize_danger_zone_button"}
        </button>
    </form>
</div>
{{endif}}

<script>
(function(){
    document.querySelectorAll('.gdColorPicker').forEach(function(wrap){
        var swatch = wrap.querySelector('input[type="color"]');
        var text   = wrap.querySelector('input[type="text"]');
        if ( !swatch || !text ) return;
        swatch.addEventListener('input', function(){ text.value = swatch.value.toUpperCase(); });
        text.addEventListener('input', function(){
            if ( /^#[0-9A-Fa-f]{6}$/.test(text.value) ) swatch.value = text.value;
        });
    });
    document.querySelectorAll('.gdHoursRow').forEach(function(row){
        var closedCb = row.querySelector('input[type="checkbox"][name$="_closed"]');
        var timeInputs = row.querySelectorAll('input[type="time"]');
        if ( !closedCb ) return;
        function sync(){ timeInputs.forEach(function(i){ i.disabled = closedCb.checked; }); }
        closedCb.addEventListener('change', sync);
    });
})();
</script>
TEMPLATE_EOT,
	],

	/* ===== FRONT: feedValidator (public feed check tool) ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'feedValidator',
		'template_data' => '',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="gd-feedval">
<style>
.gd-feedval {
    --gd-brand: #1E40AF;
    --gd-brand-hover: #1E3A8A;
    --gd-brand-light: #EFF6FF;
    --gd-brand-border: #BFDBFE;
    --gd-surface: #FFFFFF;
    --gd-surface-muted: #F8FAFC;
    --gd-border: #E5E7EB;
    --gd-border-strong: #CBD5E1;
    --gd-text: #0F172A;
    --gd-text-muted: #475569;
    --gd-text-subtle: #64748B;
    --gd-error: #B91C1C;
    --gd-error-bg: #FEE2E2;
    --gd-warn: #B45309;
    --gd-warn-bg: #FEF3C7;
    --gd-ok: #047857;
    --gd-ok-bg: #D1FAE5;
    --gd-r-md: 6px;
    --gd-r-lg: 10px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    color: var(--gd-text);
    font-size: 14px;
    line-height: 1.5;
    max-width: 1446px;
    margin: 0 auto;
    padding: 2rem 24px;
    box-sizing: border-box;
}
.gd-feedval *, .gd-feedval *::before, .gd-feedval *::after { box-sizing: border-box; }
.gd-feedval h1 { font-size: 28px; font-weight: 600; margin: 0 0 8px; letter-spacing: -0.02em; }
.gd-feedval .lede { color: var(--gd-text-muted); margin: 0 0 24px; max-width: 720px; }
.gd-feedval .lede a { color: var(--gd-brand); }

.gd-feedval .card { background: var(--gd-surface); border: 1px solid var(--gd-border); border-radius: var(--gd-r-lg); padding: 1.5rem; margin-bottom: 1rem; }

.gd-feedval .field { margin-bottom: 1rem; }
.gd-feedval .field-label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; }
.gd-feedval .formats { display: flex; gap: 12px; }
.gd-feedval .formats label { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid var(--gd-border); border-radius: var(--gd-r-md); cursor: pointer; font-size: 13px; }
.gd-feedval .formats label.active { border-color: var(--gd-brand); background: var(--gd-brand-light); color: var(--gd-brand); }
.gd-feedval .formats input { margin: 0; }

.gd-feedval textarea { width: 100%; min-height: 280px; font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; font-size: 12px; padding: 12px; border: 1px solid var(--gd-border); border-radius: var(--gd-r-md); resize: vertical; box-sizing: border-box; }
.gd-feedval textarea:focus { outline: none; border-color: var(--gd-brand); box-shadow: 0 0 0 3px rgba(30,64,175,0.1); }

.gd-feedval .actions { display: flex; gap: 8px; align-items: center; }
.gd-feedval .btn { padding: 10px 18px; border-radius: var(--gd-r-md); border: 1px solid var(--gd-border); background: var(--gd-surface); color: var(--gd-text); cursor: pointer; font-family: inherit; font-size: 13px; font-weight: 500; }
.gd-feedval .btn:hover { background: var(--gd-surface-muted); border-color: var(--gd-border-strong); }
.gd-feedval .btn.primary { background: var(--gd-brand); color: white; border-color: var(--gd-brand); }
.gd-feedval .btn.primary:hover { background: var(--gd-brand-hover); }
.gd-feedval .btn:disabled { opacity: 0.5; cursor: not-allowed; }

.gd-feedval .results { display: none; }
.gd-feedval .results.show { display: block; }
.gd-feedval .summary { display: flex; gap: 12px; flex-wrap: wrap; padding: 14px 18px; border-radius: var(--gd-r-md); margin-bottom: 1rem; align-items: center; }
.gd-feedval .summary.ok { background: var(--gd-ok-bg); color: var(--gd-ok); }
.gd-feedval .summary.bad { background: var(--gd-error-bg); color: var(--gd-error); }
.gd-feedval .summary-stat { font-size: 13px; }
.gd-feedval .summary-stat strong { font-weight: 600; }
.gd-feedval .summary-icon { font-size: 18px; }

.gd-feedval .issue-table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; font-size: 13px; }
.gd-feedval .issue-table th { text-align: left; font-weight: 600; padding: 10px 12px; background: var(--gd-surface-muted); border-bottom: 1px solid var(--gd-border); }
.gd-feedval .issue-table td { padding: 10px 12px; border-bottom: 1px solid var(--gd-border); vertical-align: top; }
.gd-feedval .issue-table .row-num { font-variant-numeric: tabular-nums; color: var(--gd-text-subtle); width: 60px; }
.gd-feedval .issue-table .field-name { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; color: var(--gd-text-muted); width: 140px; }
.gd-feedval .issue-table .upc-cell { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; color: var(--gd-text-subtle); width: 130px; }
.gd-feedval .section-head { font-weight: 600; margin: 1.5rem 0 8px; display: flex; align-items: center; gap: 8px; }
.gd-feedval .section-head .pill { font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
.gd-feedval .section-head .pill.err { background: var(--gd-error-bg); color: var(--gd-error); }
.gd-feedval .section-head .pill.warn { background: var(--gd-warn-bg); color: var(--gd-warn); }
.gd-feedval .section-head .pill.note { background: #EFF6FF; color: #1E40AF; }

.gd-feedval .raw-toggle { font-size: 12px; color: var(--gd-text-subtle); cursor: pointer; user-select: none; margin-top: 1rem; }
.gd-feedval pre.raw { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 11px; background: var(--gd-surface-muted); padding: 12px; border-radius: var(--gd-r-md); overflow-x: auto; max-height: 400px; display: none; }
.gd-feedval pre.raw.show { display: block; }
</style>

    <h1>Feed validator</h1>
    <p class="lede">Paste a sample of your XML, JSON, or CSV feed below and we'll check it against the GunRack v1 schema. No data is saved &mdash; this is a pure dry-run check. See the <a href="/dealers/feed-schema">schema docs</a> for the full field reference.</p>

    <div class="card">
        <div class="field">
            <label class="field-label">Format</label>
            <div class="formats" id="gdfvFormats">
                <label class="active"><input type="radio" name="format" value="xml" checked> XML</label>
                <label><input type="radio" name="format" value="json"> JSON</label>
                <label><input type="radio" name="format" value="csv"> CSV</label>
            </div>
        </div>

        <div class="field">
            <label class="field-label" for="gdfvBody">Feed body</label>
            <textarea id="gdfvBody" placeholder="Paste your feed contents here..."></textarea>
        </div>

        <div class="actions">
            <button type="button" class="btn primary" id="gdfvCheck">Validate feed</button>
            <button type="button" class="btn" id="gdfvClear">Clear</button>
        </div>
    </div>

    <div class="card results" id="gdfvResults">
        <div id="gdfvSummary"></div>
        <div id="gdfvErrors"></div>
        <div id="gdfvWarnings"></div>
        <div id="gdfvNotes"></div>
        <div class="raw-toggle" id="gdfvRawToggle">Show raw JSON response</div>
        <pre class="raw" id="gdfvRaw"></pre>
    </div>

<script>
(function(){
    var formats = document.getElementById('gdfvFormats');
    var body    = document.getElementById('gdfvBody');
    var check   = document.getElementById('gdfvCheck');
    var clear   = document.getElementById('gdfvClear');
    var results = document.getElementById('gdfvResults');
    var sumDiv  = document.getElementById('gdfvSummary');
    var errDiv  = document.getElementById('gdfvErrors');
    var warnDiv = document.getElementById('gdfvWarnings');
    var noteDiv = document.getElementById('gdfvNotes');
    var rawTog  = document.getElementById('gdfvRawToggle');
    var rawPre  = document.getElementById('gdfvRaw');

    formats.addEventListener('click', function(e){
        var lbl = e.target.closest('label');
        if (!lbl) return;
        formats.querySelectorAll('label').forEach(function(l){ l.classList.remove('active'); });
        lbl.classList.add('active');
    });

    rawTog.addEventListener('click', function(){
        rawPre.classList.toggle('show');
        rawTog.textContent = rawPre.classList.contains('show') ? 'Hide raw JSON response' : 'Show raw JSON response';
    });

    clear.addEventListener('click', function(){
        body.value = '';
        results.classList.remove('show');
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
        });
    }

    function renderIssueTable(issues, kind) {
        if (!issues || !issues.length) return '';
        var label = kind === 'error' ? 'Errors' : (kind === 'warning' ? 'Warnings' : 'Notes');
        var pill  = kind === 'error' ? 'err' : (kind === 'warning' ? 'warn' : 'note');
        var html  = '<div class="section-head">' + label + ' <span class="pill ' + pill + '">' + issues.length + '</span></div>';
        html += '<table class="issue-table"><thead><tr><th>Row</th><th>UPC</th><th>Field</th><th>Message</th></tr></thead><tbody>';
        issues.forEach(function(i){
            html += '<tr><td class="row-num">' + escapeHtml(i.row) + '</td>';
            html += '<td class="upc-cell">' + escapeHtml(i.upc || '-') + '</td>';
            html += '<td class="field-name">' + escapeHtml(i.field) + '</td>';
            html += '<td>' + escapeHtml(i.message) + '</td></tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    check.addEventListener('click', function(){
        var fmt = formats.querySelector('input[name="format"]:checked').value;
        var b   = body.value;
        if (!b.trim()) { alert('Paste a feed body first.'); return; }

        check.disabled = true;
        check.textContent = 'Validating...';

        var fd = new FormData();
        fd.append('format', fmt);
        fd.append('body', b);

        fetch('/dealers/feed-validator?do=check', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(data){
                results.classList.add('show');
                var s = data.summary || {};
                var sumClass = data.valid ? 'ok' : 'bad';
                var sumIcon  = data.valid ? '&check;' : '&cross;';
                var sumLabel = data.valid ? 'Feed is valid' : 'Feed has errors';
                sumDiv.innerHTML = '<div class="summary ' + sumClass + '">' +
                    '<span class="summary-icon">' + sumIcon + '</span>' +
                    '<span class="summary-stat"><strong>' + sumLabel + '</strong></span>' +
                    '<span class="summary-stat">&middot; <strong>' + (s.total_records || 0) + '</strong> total</span>' +
                    '<span class="summary-stat">&middot; <strong>' + (s.valid_records || 0) + '</strong> valid</span>' +
                    '<span class="summary-stat">&middot; <strong>' + (s.error_records || 0) + '</strong> with errors</span>' +
                    '<span class="summary-stat">&middot; <strong>' + (s.warning_records || 0) + '</strong> with warnings</span>' +
                    ((s.note_records || 0) > 0 ? '<span class="summary-stat">&middot; <strong>' + s.note_records + '</strong> with notes</span>' : '') +
                    '</div>';
                errDiv.innerHTML  = renderIssueTable(data.errors, 'error');
                warnDiv.innerHTML = renderIssueTable(data.warnings, 'warning');
                noteDiv.innerHTML = renderIssueTable(data.notes, 'note');
                rawPre.textContent = JSON.stringify(data, null, 2);
            })
            .catch(function(err){
                results.classList.add('show');
                sumDiv.innerHTML = '<div class="summary bad"><span class="summary-icon">&cross;</span><span class="summary-stat"><strong>Network error:</strong> ' + escapeHtml(err.message) + '</span></div>';
                errDiv.innerHTML = '';
                warnDiv.innerHTML = '';
                noteDiv.innerHTML = '';
                rawPre.textContent = '';
            })
            .finally(function(){
                check.disabled = false;
                check.textContent = 'Validate feed';
            });
    });
})();
</script>

</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: feedSettings tab body ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'feedSettings',
		'template_data' => '$data',
		'template_content' => <<<'TEMPLATE_EOT'
<div>

	<div class="ipsBox i-margin-bottom_block" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:24px">
		<h3 class="ipsBox__header" style="margin:0;padding:14px 18px;border-bottom:1px solid var(--i-border-color,#f0f0f0);font-size:1em;font-weight:700">Feed Configuration</h3>
		<div class="i-padding_2" style="padding:18px">
			{$form|raw}
			<div style="margin-top:16px">
				<a href="{$importUrl}" class="ipsButton ipsButton--primary">{lang="gddealer_front_run_import"}</a>
			</div>
		</div>
	</div>

	<div class="ipsBox" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px">
		<h3 class="ipsBox__header" style="margin:0;padding:14px 18px;border-bottom:1px solid var(--i-border-color,#f0f0f0);font-size:1em;font-weight:700">{lang="gddealer_front_import_history"}</h3>
		<div class="gdTableWrap">
		<table class="ipsTable ipsTable_zebra gdResponsiveTable" style="width:100%">
		<thead>
			<tr>
				<th>Started</th>
				<th>Status</th>
				<th>Total</th>
				<th>New</th>
				<th>Updated</th>
				<th>Unchanged</th>
				<th>Unmatched</th>
				<th>Drops</th>
			</tr>
		</thead>
		<tbody>
			{{foreach $logs as $l}}
			<tr>
				<td data-label="Started">{$l['run_start']}</td>
				<td data-label="Status">
					{{if $l['status'] === 'completed'}}
						<span class="ipsBadge ipsBadge--positive">OK</span>
					{{elseif $l['status'] === 'failed'}}
						<span class="ipsBadge ipsBadge--negative">Failed</span>
					{{else}}
						<span class="ipsBadge ipsBadge--warning">{$l['status']}</span>
					{{endif}}
				</td>
				<td data-label="Total">{$l['records_total']}</td>
				<td data-label="New">{$l['records_created']}</td>
				<td data-label="Updated">{$l['records_updated']}</td>
				<td data-label="Unchanged">{$l['records_unchanged']}</td>
				<td data-label="Unmatched">{$l['records_unmatched']}</td>
				<td data-label="Drops">{$l['price_drops']}</td>
			</tr>
			{{if $l['error_log']}}
			<tr><td colspan="8"><pre style="white-space:pre-wrap;color:#c00;margin:0">{$l['error_log']}</pre></td></tr>
			{{endif}}
			{{endforeach}}
			{{if count( $logs ) === 0}}
			<tr><td colspan="8" style="text-align:center;color:#999;padding:24px">No imports have run yet.</td></tr>
			{{endif}}
		</tbody>
	</table>
		</div>
	</div>

</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: listings tab body ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'listings',
		'template_data' => '$data',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px">
	<h3 class="ipsBox__header" style="margin:0;padding:14px 18px;border-bottom:1px solid var(--i-border-color,#f0f0f0);font-size:1em;font-weight:700">{lang="gddealer_front_tab_listings"}</h3>
	<div class="i-padding_2" style="padding:18px">

	<form method="get" action="{$tabUrls['listings']}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:16px">
		<input type="hidden" name="app" value="gddealer">
		<input type="hidden" name="module" value="dealers">
		<input type="hidden" name="controller" value="dashboard">
		<input type="hidden" name="do" value="listings">
		<div>
			<label style="display:block;font-size:0.85em;color:#666">{lang="gddealer_front_filter"}</label>
			<select name="filter" style="border:1px solid var(--i-border-color,#ccc);border-radius:4px;padding:6px 10px;background:#fff">
				<option value="" {expression="$filter === '' ? 'selected' : ''"}>All</option>
				<option value="active" {expression="$filter === 'active' ? 'selected' : ''"}>Active</option>
				<option value="in_stock" {expression="$filter === 'in_stock' ? 'selected' : ''"}>In Stock</option>
				<option value="out_of_stock" {expression="$filter === 'out_of_stock' ? 'selected' : ''"}>Out of Stock</option>
				<option value="suspended" {expression="$filter === 'suspended' ? 'selected' : ''"}>Suspended</option>
				<option value="discontinued" {expression="$filter === 'discontinued' ? 'selected' : ''"}>Discontinued</option>
			</select>
		</div>
		<div>
			<label style="display:block;font-size:0.85em;color:#666">{lang="gddealer_front_search_upc"}</label>
			<input type="text" name="q" value="{$search}" placeholder="{lang='gddealer_front_search_placeholder'}" style="border:1px solid var(--i-border-color,#ccc);border-radius:4px;padding:6px 10px;background:#fff">
		</div>
		<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">{lang="gddealer_front_apply"}</button>
		<a href="{$exportUrl}" class="ipsButton ipsButton--normal ipsButton--small">{lang="gddealer_front_export_csv"}</a>
	</form>

	<p style="color:#666">{expression="number_format( $total )"} listings total.</p>

	<div class="gdTableWrap">
	<table class="ipsTable ipsTable_zebra gdResponsiveTable" style="width:100%">
		<thead>
			<tr>
				<th>{lang="gddealer_listing_upc"}</th>
				<th>{lang="gddealer_listing_price"}</th>
				<th>{lang="gddealer_listing_stock"}</th>
				<th>{lang="gddealer_listing_condition"}</th>
				<th>Status</th>
				<th>{lang="gddealer_listing_last_updated"}</th>
			</tr>
		</thead>
		<tbody>
			{{foreach $rows as $r}}
			<tr>
				<td data-label="UPC"><code>{$r['upc']}</code></td>
				<td data-label="Price">{$r['dealer_price']}</td>
				<td data-label="Stock">
					{{if $r['in_stock']}}
						<span class="ipsBadge ipsBadge--positive">In Stock</span>
					{{else}}
						<span class="ipsBadge ipsBadge--neutral">Out</span>
					{{endif}}
				</td>
				<td data-label="Condition">{$r['condition']}</td>
				<td data-label="Status">{$r['listing_status']}</td>
				<td data-label="Last Updated">{$r['last_updated']}</td>
			</tr>
			{{endforeach}}
			{{if count( $rows ) === 0}}
			<tr><td colspan="6" style="text-align:center;color:#999;padding:24px">{lang="gddealer_front_listings_empty"}</td></tr>
			{{endif}}
		</tbody>
	</table>
	</div>

	{{if $pages > 1}}
	<div style="margin-top:16px">
		Page {$page} of {$pages}
	</div>
	{{endif}}

	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: unmatched tab body ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'unmatched',
		'template_data' => '$data',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px">
	<h3 class="ipsBox__header" style="margin:0;padding:14px 18px;border-bottom:1px solid var(--i-border-color,#f0f0f0);font-size:1em;font-weight:700">{lang="gddealer_front_tab_unmatched"}</h3>
	<div class="i-padding_2" style="padding:18px">

	<p>{lang="gddealer_front_unmatched_intro"}</p>

	<div class="gdNote" role="note">
		<span class="gdNote__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
		<span class="gdNote__text">{lang="gddealer_front_unmatched_60day"}</span>
	</div>

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
TEMPLATE_EOT,
	],

	/* ===== FRONT: analytics tab body ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'analytics',
		'template_data' => '$data',
		'template_content' => <<<'TEMPLATE_EOT'
<div>

	{{if $gated}}
		<div style="text-align:center;padding:48px;background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px">
			<div style="font-size:2em;margin-bottom:8px">&#x1F4CA;</div>
			<h3 style="margin:0 0 8px">Analytics &mdash; Pro &amp; Enterprise Only</h3>
			<p style="color:#666;margin:0 0 16px">Upgrade to Pro or Enterprise to unlock full analytics including price competitiveness, revenue opportunities, and click-through data.</p>
			<a href="{$tabUrls['subscription']}" class="ipsButton ipsButton--primary">View Upgrade Options</a>
		</div>
	{{else}}

		<div class="gdStatCards" style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap">
			<div style="flex:1 1 180px;background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:16px;text-align:center">
				<div class="gdStatCard__value" style="font-size:2em;font-weight:700;color:#16a34a">{$analytics['comp_lowest']}</div>
				<div style="color:#666;font-size:0.9em">Listings &mdash; Lowest Price</div>
			</div>
			<div style="flex:1 1 180px;background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:16px;text-align:center">
				<div class="gdStatCard__value" style="font-size:2em;font-weight:700;color:#f59e0b">{$analytics['comp_mid']}</div>
				<div style="color:#666;font-size:0.9em">Listings &mdash; Mid Range</div>
			</div>
			<div style="flex:1 1 180px;background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:16px;text-align:center">
				<div class="gdStatCard__value" style="font-size:2em;font-weight:700;color:#dc2626">{$analytics['comp_high']}</div>
				<div style="color:#666;font-size:0.9em">Listings &mdash; Highest Price</div>
			</div>
			<div style="flex:1 1 180px;background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:16px;text-align:center">
				<div class="gdStatCard__value" style="font-size:2em;font-weight:700;color:#2563eb">{$analytics['comp_only']}</div>
				<div style="color:#666;font-size:0.9em">Only Dealer for UPC</div>
			</div>
			<div style="flex:1 1 180px;background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:16px;text-align:center">
				<div class="gdStatCard__value" style="font-size:2em;font-weight:700">{$analytics['price_drop_count']}</div>
				<div style="color:#666;font-size:0.9em">Price Drops (30 days)</div>
			</div>
		</div>

		<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:24px">
			<div style="padding:16px;border-bottom:1px solid var(--i-border-color,#e0e0e0)">
				<h3 style="margin:0;font-size:1em;font-weight:700">Top 20 Most-Clicked Listings (Last 30 Days)</h3>
			</div>
			<div class="gdTableWrap">
			<table class="ipsTable ipsTable_zebra gdResponsiveTable" style="width:100%">
				<thead><tr>
					<th>UPC</th>
					<th style="width:120px">Your Price</th>
					<th style="width:100px">Clicks (30d)</th>
					<th style="width:100px">Clicks (7d)</th>
					<th style="width:100px">Status</th>
				</tr></thead>
				<tbody>
				{{if count( $topClicked ) === 0}}
					<tr><td colspan="5" style="text-align:center;color:#999;padding:24px">No click-through data yet.</td></tr>
				{{else}}
					{{foreach $topClicked as $r}}
					<tr>
						<td data-label="UPC"><code>{$r['upc']}</code></td>
						<td data-label="Your Price">${expression="number_format( (float) $r['dealer_price'], 2 )"}</td>
						<td data-label="Clicks (30d)">{$r['click_count_30d']}</td>
						<td data-label="Clicks (7d)">{$r['click_count_7d']}</td>
						<td data-label="Status">{{if $r['in_stock']}}<span style="color:#16a34a;font-weight:600">In Stock</span>{{else}}<span style="color:#dc2626">Out of Stock</span>{{endif}}</td>
					</tr>
					{{endforeach}}
				{{endif}}
				</tbody>
			</table>
			</div>
		</div>

		<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px">
			<div style="padding:16px;border-bottom:1px solid var(--i-border-color,#e0e0e0)">
				<h3 style="margin:0;font-size:1em;font-weight:700">Revenue Opportunities &mdash; You Are Not the Lowest Price</h3>
				<p style="margin:4px 0 0;color:#666;font-size:0.85em">Products where lowering your price could win more clicks.</p>
			</div>
			<div class="gdTableWrap">
			<table class="ipsTable ipsTable_zebra gdResponsiveTable" style="width:100%">
				<thead><tr>
					<th>UPC</th>
					<th style="width:120px">Your Price</th>
					<th style="width:120px">Lowest Price</th>
					<th style="width:100px">Gap</th>
					<th style="width:100px">Clicks (30d)</th>
				</tr></thead>
				<tbody>
				{{if count( $opportunities ) === 0}}
					<tr><td colspan="5" style="text-align:center;color:#999;padding:24px">No opportunities found &mdash; you may already be competitive!</td></tr>
				{{else}}
					{{foreach $opportunities as $r}}
					<tr>
						<td data-label="UPC"><code>{$r['upc']}</code></td>
						<td data-label="Your Price">${expression="number_format( (float) $r['your_price'], 2 )"}</td>
						<td data-label="Lowest Price">${expression="number_format( (float) $r['lowest_price'], 2 )"}</td>
						<td data-label="Gap" style="color:#dc2626;font-weight:600">+${expression="number_format( (float) $r['gap'], 2 )"}</td>
						<td data-label="Clicks (30d)">{$r['click_count_30d']}</td>
					</tr>
					{{endforeach}}
				{{endif}}
				</tbody>
			</table>
			</div>
		</div>

	{{endif}}

</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: subscription tab body ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'subscription',
		'template_data' => '$dealer, $sub, $billingNote, $tabUrls, $plans',
		'template_content' => <<<'TEMPLATE_EOT'
<div>

	<div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap">
		<div class="ipsBox" style="flex:1 1 200px;padding:16px;text-align:center">
			<div style="color:#666;font-size:0.9em">{lang="gddealer_front_subscription_current"}</div>
			<div style="font-size:1.6em;font-weight:bold;margin-top:4px">{$sub['tier_label']}</div>
		</div>
		<div class="ipsBox" style="flex:1 1 200px;padding:16px;text-align:center">
			<div style="color:#666;font-size:0.9em">{lang="gddealer_front_subscription_mrr"}</div>
			<div style="font-size:1.6em;font-weight:bold;margin-top:4px">{$sub['mrr']}</div>
		</div>
		<div class="ipsBox" style="flex:1 1 200px;padding:16px;text-align:center">
			<div style="color:#666;font-size:0.9em">{lang="gddealer_front_subscription_status"}</div>
			<div style="font-size:1.2em;font-weight:bold;margin-top:4px">
				{{if $sub['suspended']}}
					<span class="ipsBadge ipsBadge--negative">Suspended</span>
				{{elseif $sub['active']}}
					<span class="ipsBadge ipsBadge--positive">Active</span>
				{{else}}
					<span class="ipsBadge ipsBadge--neutral">Pending Setup</span>
				{{endif}}
			</div>
		</div>
	</div>

	{{if $sub['trial_expires_at']}}
	<div style="margin-bottom:24px;padding:16px;border-radius:8px;border:1px solid {expression="$sub['trial_expiring_soon'] ? '#fca5a5' : 'var(--i-border-color,#e0e0e0)'"};background:{expression="$sub['trial_expiring_soon'] ? '#fff5f5' : '#fff'"}">
		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
			<div>
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Trial Period</div>
				<div style="font-weight:700;font-size:1.05em">
					Expires {$sub['trial_expires_formatted']}
				</div>
				{{if $sub['trial_expiring_soon']}}
					<div style="margin-top:4px;color:#dc2626;font-size:0.9em;font-weight:600">
						Expires in {$sub['trial_days_left']} day{{if $sub['trial_days_left'] !== 1}}s{{endif}} — subscribe to keep your listings live
					</div>
				{{else}}
					<div style="margin-top:4px;color:#666;font-size:0.85em">
						{$sub['trial_days_left']} days remaining on your trial
					</div>
				{{endif}}
			</div>
			{{if $sub['trial_expiring_soon']}}
			<div>
				<a href="{$sub['subscribe_url']}" class="ipsButton ipsButton--primary ipsButton--small">Subscribe Now</a>
			</div>
			{{endif}}
		</div>
	</div>
	{{endif}}

	<p>{$billingNote}</p>

</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: help tab body ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'help',
		'template_data' => '$helpData',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="gdHelpPage">
	<div class="gdHelpPage__header">
		<h2>Feed Setup Guide</h2>
		<p>{$helpData['intro']}</p>
	</div>

	<div class="gdHelpPage__grid">
		<div class="gdHelpPage__main">
			<div class="gdHelpPage__card">
				<h3><span class="gdHelpPage__num">1</span> Prepare your product feed</h3>
				<p>{$helpData['step1']}</p>
				<p><strong>Required fields per product:</strong></p>
				<ul>
					<li><strong>UPC</strong> &mdash; 12-digit UPC barcode. Must match our catalog exactly.</li>
					<li><strong>Price</strong> &mdash; Your retail price as a decimal (e.g. 499.99)</li>
					<li><strong>In Stock</strong> &mdash; Boolean or quantity (0/1, true/false, or quantity integer)</li>
				</ul>
				<p><strong>Optional but recommended:</strong></p>
				<ul>
					<li><strong>SKU</strong> &mdash; Your internal product identifier</li>
					<li><strong>Shipping Info</strong> &mdash; Free-form text describing shipping. Optional. Max 60 chars.</li>
					<li><strong>Condition</strong> &mdash; new, used, or refurbished</li>
					<li><strong>Product URL</strong> &mdash; Direct link to the product on your website</li>
					<li><strong>Stock Quantity</strong> &mdash; Exact quantity on hand</li>
				</ul>
			</div>

			<div class="gdHelpPage__card">
				<h3><span class="gdHelpPage__num">2</span> Format your feed</h3>
				<p>{$helpData['step2']}</p>
				<p><strong>CSV format example:</strong></p>
				<pre>upc,price,in_stock,shipping_info,condition,product_url
026495088565,499.99,1,$15.00 flat,new,https://yourstore.com/product/123
000000000000,299.99,0,Free shipping,new,https://yourstore.com/product/456</pre>
				<p><strong>JSON format example:</strong></p>
				<pre>[
  {
    "upc": "026495088565",
    "price": 499.99,
    "in_stock": true,
    "shipping_info": "$15.00 flat",
    "condition": "new",
    "product_url": "https://yourstore.com/product/123"
  }
]</pre>
				<p><strong>XML format example:</strong></p>
				<pre>&lt;products&gt;
  &lt;product&gt;
    &lt;upc&gt;026495088565&lt;/upc&gt;
    &lt;price&gt;499.99&lt;/price&gt;
    &lt;in_stock&gt;1&lt;/in_stock&gt;
    &lt;shipping_info&gt;$15.00 flat&lt;/shipping_info&gt;
    &lt;condition&gt;new&lt;/condition&gt;
  &lt;/product&gt;
&lt;/products&gt;</pre>
			</div>

			<div class="gdHelpPage__card">
				<h3><span class="gdHelpPage__num">3</span> Configure field mapping</h3>
				<p>{$helpData['step3']}</p>
				<pre>{
  "UPC": "upc",
  "PRICE": "dealer_price",
  "QTY": "stock_qty",
  "INSTOCK": "in_stock",
  "SHIP": "shipping_info",
  "COND": "condition",
  "URL": "listing_url"
}</pre>
			</div>

			<div class="gdHelpPage__card">
				<h3><span class="gdHelpPage__num">4</span> Enter your feed URL</h3>
				<p>{$helpData['step4']}</p>
				<ul>
					<li>Basic Auth: <code>{"username":"user","password":"pass"}</code></li>
					<li>API Key: <code>{"api_key":"your-key-here"}</code></li>
				</ul>
			</div>

			<div class="gdHelpPage__card">
				<h3><span class="gdHelpPage__num">5</span> Review your listings</h3>
				<p>{$helpData['step5']}</p>
			</div>

			<div class="gdHelpPage__card gdHelpPage__card--info">
				<h3>Feed Requirements Summary</h3>
				<ul>
					{{foreach $helpData['requirements'] as $req}}
					<li>{$req}</li>
					{{endforeach}}
				</ul>
			</div>
		</div>

		<aside class="gdHelpPage__sidebar">
			<div class="gdHelpPage__card">
				<h3>Quick Field Reference</h3>
				<table>
					<tr><td>upc</td><td class="gdHelpPage__req">Required</td></tr>
					<tr><td>dealer_price</td><td class="gdHelpPage__req">Required</td></tr>
					<tr><td>in_stock</td><td class="gdHelpPage__req">Required</td></tr>
					<tr><td>shipping_info</td><td class="gdHelpPage__opt">Optional</td></tr>
					<tr><td>condition</td><td class="gdHelpPage__opt">Optional</td></tr>
					<tr><td>listing_url</td><td class="gdHelpPage__opt">Optional</td></tr>
					<tr><td>stock_qty</td><td class="gdHelpPage__opt">Optional</td></tr>
					<tr><td>dealer_sku</td><td class="gdHelpPage__opt">Optional</td></tr>
				</table>
			</div>

			<div class="gdHelpPage__card">
				<h3>Sync Schedule</h3>
				<table>
					<tr><td>Basic</td><td>Every 6 hours</td></tr>
					<tr><td>Pro</td><td>Every 30 min</td></tr>
					<tr><td>Enterprise</td><td>Every 15 min</td></tr>
				</table>
			</div>

			{{if $helpData['contact']}}
			<div class="gdHelpPage__card">
				<h3>Need Help?</h3>
				<p>Our team can help you get your feed configured and your first import running.</p>
				<a href="mailto:{$helpData['contact']}" class="ipsButton ipsButton--primary" style="width:100%;text-align:center;display:block">Email Support</a>
			</div>
			{{endif}}
		</aside>
	</div>
</div>

<style>
.gdHelpPage { max-width: 1200px; margin: 0 auto; padding: 20px; }
.gdHelpPage__header { margin-bottom: 24px; }
.gdHelpPage__header h2 { margin: 0 0 8px; font-size: 1.5em; font-weight: 700; color: #111827; }
.gdHelpPage__header p { margin: 0; color: #64748b; }

.gdHelpPage__grid {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 300px;
	gap: 24px;
	align-items: start;
}
.gdHelpPage__main { min-width: 0; display: flex; flex-direction: column; gap: 16px; }
.gdHelpPage__sidebar {
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 16px;
	position: sticky;
	top: 20px;
}

.gdHelpPage__card {
	background: #fff;
	border: 1px solid var(--i-border-color, #e0e0e0);
	border-radius: 8px;
	padding: 20px;
}
.gdHelpPage__card--info {
	background: #f0f7ff;
	border-color: #bfdbfe;
}
.gdHelpPage__card--info h3 { color: #1e40af; }
.gdHelpPage__card--info ul { color: #1e3a5f; }
.gdHelpPage__card h3 {
	margin: 0 0 12px;
	font-size: 1.05em;
	font-weight: 700;
	color: #1e3a5f;
	display: flex;
	align-items: center;
	gap: 8px;
}
.gdHelpPage__num {
	background: #2563eb;
	color: #fff;
	border-radius: 50%;
	width: 24px;
	height: 24px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-size: 0.8em;
	font-weight: 700;
	flex-shrink: 0;
}
.gdHelpPage__card ul { margin: 8px 0; padding-left: 20px; }
.gdHelpPage__card p { margin: 0 0 12px; }
.gdHelpPage__card p:last-child { margin-bottom: 0; }
.gdHelpPage__card pre {
	background: #f4f4f4;
	padding: 12px;
	border-radius: 4px;
	overflow-x: auto;
	font-size: 0.85em;
	margin: 12px 0 0;
}
.gdHelpPage__card code {
	background: #f4f4f4;
	padding: 1px 6px;
	border-radius: 3px;
}
.gdHelpPage__card table {
	width: 100%;
	font-size: 0.9em;
	border-collapse: collapse;
}
.gdHelpPage__card table tr { border-bottom: 1px solid #f0f0f0; }
.gdHelpPage__card table tr:last-child { border-bottom: none; }
.gdHelpPage__card table td { padding: 8px 0; font-weight: 500; }
.gdHelpPage__card table td:last-child { text-align: right; color: #64748b; font-weight: 400; }
.gdHelpPage__req { color: #16a34a !important; }
.gdHelpPage__opt { color: #64748b !important; }

@media (max-width: 900px) {
	.gdHelpPage__grid {
		grid-template-columns: 1fr;
	}
	.gdHelpPage__sidebar {
		position: static;
		top: auto;
	}
}

@media (max-width: 480px) {
	.gdHelpPage { padding: 12px; }
	.gdHelpPage__card { padding: 16px; }
	.gdHelpPage__header h2 { font-size: 1.3em; }
}
</style>
TEMPLATE_EOT,
	],

	/* ===== FRONT: join (landing) ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'join',
		'template_data' => '$tiers, $contactEmail, $guidelinesUrl',
		'template_content' => <<<'TEMPLATE_EOT'
<div style="max-width:1100px;margin:0 auto;padding:0 16px">

	<div style="text-align:center;padding:48px 24px 40px;border-bottom:1px solid var(--i-border-color,#e0e0e0);margin-bottom:48px">
		<div style="display:inline-block;background:#eff6ff;color:#2563eb;font-size:0.8em;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;padding:6px 14px;border-radius:20px;margin-bottom:16px">Dealer Program</div>
		<h1 style="font-size:2.2em;font-weight:800;margin:0 0 16px;line-height:1.2">Reach Buyers Actively Searching<br>for What You Sell</h1>
		<p style="font-size:1.1em;color:#555;max-width:620px;margin:0 auto 24px">GunRack.deals puts your inventory in front of price-conscious buyers comparing products across multiple dealers. List once, sync automatically, sell more.</p>
		<div style="display:flex;gap:32px;justify-content:center;flex-wrap:wrap">
			<div style="text-align:center">
				<div style="font-size:1.8em;font-weight:800;color:#2563eb">3</div>
				<div style="font-size:0.85em;color:#666">Feed Formats</div>
			</div>
			<div style="text-align:center">
				<div style="font-size:1.8em;font-weight:800;color:#2563eb">Auto</div>
				<div style="font-size:0.85em;color:#666">Price Sync</div>
			</div>
			<div style="text-align:center">
				<div style="font-size:1.8em;font-weight:800;color:#2563eb">Live</div>
				<div style="font-size:0.85em;color:#666">Click Analytics</div>
			</div>
			<div style="text-align:center">
				<div style="font-size:1.8em;font-weight:800;color:#2563eb">FFL</div>
				<div style="font-size:0.85em;color:#666">Verified Platform</div>
			</div>
		</div>
	</div>

	<h2 style="text-align:center;font-size:1.5em;font-weight:700;margin:0 0 8px">Choose Your Plan</h2>
	<p style="text-align:center;color:#666;margin:0 0 32px">All plans include unlimited listings, automatic sync, and a self-service dealer dashboard.</p>

	<div style="display:flex;gap:20px;margin-bottom:48px;flex-wrap:wrap;align-items:stretch">
		{{foreach $tiers as $t}}
		<div style="flex:1 1 280px;background:#fff;border:2px solid {expression="$t['popular'] ? '#2563eb' : 'var(--i-border-color,#e0e0e0)'"};border-radius:12px;padding:28px;position:relative;display:flex;flex-direction:column">
			{{if $t['popular']}}
			<div style="position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:#2563eb;color:#fff;font-size:0.75em;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;padding:4px 14px;border-radius:20px">Most Popular</div>
			{{endif}}
			<div style="margin-bottom:20px">
				<h3 style="margin:0 0 4px;font-size:1.1em;font-weight:700">{$t['label']}</h3>
				<div style="font-size:2em;font-weight:800;margin:8px 0 4px">{$t['price']}</div>
				<div style="font-size:0.85em;color:#666">Feed sync: <strong>{$t['schedule']}</strong></div>
			</div>
			<ul style="list-style:none;padding:0;margin:0 0 24px;flex:1">
				{{foreach $t['features'] as $f}}
				<li style="display:flex;align-items:flex-start;gap:8px;padding:6px 0;border-bottom:1px solid #f5f5f5;font-size:0.9em">
					<span style="color:#16a34a;font-weight:700;flex-shrink:0">&#10003;</span>
					<span>{$f}</span>
				</li>
				{{endforeach}}
			</ul>
			<a href="{$t['commerce_url']}" class="ipsButton {expression="$t['popular'] ? 'ipsButton--primary' : 'ipsButton--normal'"}" style="width:100%;text-align:center;display:block;padding:10px">Get Started</a>
		</div>
		{{endforeach}}
	</div>

	<div style="background:#f8faff;border:1px solid #dbeafe;border-radius:12px;padding:40px;margin-bottom:48px">
		<h2 style="text-align:center;font-size:1.4em;font-weight:700;margin:0 0 32px">How It Works</h2>
		<div style="display:flex;gap:24px;flex-wrap:wrap;justify-content:center">
			<div style="flex:1 1 180px;text-align:center;max-width:220px">
				<div style="width:48px;height:48px;background:#2563eb;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.2em;font-weight:800;margin:0 auto 12px">1</div>
				<h4 style="margin:0 0 6px;font-weight:700">Subscribe</h4>
				<p style="margin:0;font-size:0.85em;color:#666">Choose a plan and complete checkout through our secure store.</p>
			</div>
			<div style="flex:1 1 180px;text-align:center;max-width:220px">
				<div style="width:48px;height:48px;background:#2563eb;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.2em;font-weight:800;margin:0 auto 12px">2</div>
				<h4 style="margin:0 0 6px;font-weight:700">Connect Your Feed</h4>
				<p style="margin:0;font-size:0.85em;color:#666">Add your product feed URL in your dealer dashboard. XML, JSON, and CSV supported.</p>
			</div>
			<div style="flex:1 1 180px;text-align:center;max-width:220px">
				<div style="width:48px;height:48px;background:#2563eb;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.2em;font-weight:800;margin:0 auto 12px">3</div>
				<h4 style="margin:0 0 6px;font-weight:700">Go Live</h4>
				<p style="margin:0;font-size:0.85em;color:#666">Your listings go live immediately after your first import completes.</p>
			</div>
			<div style="flex:1 1 180px;text-align:center;max-width:220px">
				<div style="width:48px;height:48px;background:#2563eb;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.2em;font-weight:800;margin:0 auto 12px">4</div>
				<h4 style="margin:0 0 6px;font-weight:700">Track Performance</h4>
				<p style="margin:0;font-size:0.85em;color:#666">Monitor clicks, price competitiveness, and revenue opportunities in your dashboard.</p>
			</div>
		</div>
	</div>

	<div style="margin-bottom:48px">
		<h2 style="text-align:center;font-size:1.4em;font-weight:700;margin:0 0 24px">Frequently Asked Questions</h2>
		<div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
			<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:20px">
				<h4 style="margin:0 0 8px;font-weight:700">What feed formats do you support?</h4>
				<p style="margin:0;color:#555;font-size:0.9em">We support XML, JSON, and CSV feeds. Your feed must include UPC, price, and in-stock status at minimum. See the Help &amp; Setup guide in your dashboard for full requirements.</p>
			</div>
			<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:20px">
				<h4 style="margin:0 0 8px;font-weight:700">How often does my inventory sync?</h4>
				<p style="margin:0;color:#555;font-size:0.9em">Basic syncs every 6 hours, Pro every 30 minutes, Enterprise every 15 minutes. You can also trigger a manual import anytime from your dealer dashboard.</p>
			</div>
			<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:20px">
				<h4 style="margin:0 0 8px;font-weight:700">Do I need to be an FFL dealer?</h4>
				<p style="margin:0;color:#555;font-size:0.9em">Yes — GunRack.deals is a licensed FFL platform. You must hold a valid FFL to list firearms. Accessories and non-firearm products do not require an FFL.</p>
			</div>
			<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:20px">
				<h4 style="margin:0 0 8px;font-weight:700">Can I cancel anytime?</h4>
				<p style="margin:0;color:#555;font-size:0.9em">Yes. Cancel anytime from your Subscription tab. Your listings remain live until the end of your current billing period, then are automatically suspended.</p>
			</div>
			<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:20px">
				<h4 style="margin:0 0 8px;font-weight:700">What if my UPCs aren't in your catalog?</h4>
				<p style="margin:0;color:#555;font-size:0.9em">Unmatched UPCs are logged in your dashboard. Contact us and we'll add missing products to the catalog promptly — usually within 24 hours.</p>
			</div>
			<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:20px">
				<h4 style="margin:0 0 8px;font-weight:700">How do I upgrade or downgrade?</h4>
				<p style="margin:0;color:#555;font-size:0.9em">Manage your subscription from the Subscription tab in your dealer dashboard. Changes take effect on your next billing cycle.</p>
			</div>
		</div>
	</div>

	<div style="text-align:center;padding:40px 24px;background:#eff6ff;border-radius:12px;margin-bottom:32px">
		<h2 style="margin:0 0 8px;font-size:1.4em;font-weight:700">Ready to reach more buyers?</h2>
		<p style="margin:0 0 20px;color:#555">Join dealers already listing on GunRack.deals.</p>
		<a href="{$tiers[1]['commerce_url']}" class="ipsButton ipsButton--primary" style="padding:12px 32px;font-size:1em">Get Started with Pro</a>
		<p style="margin:12px 0 0;font-size:0.85em;color:#666">Questions? Email us at <a href="mailto:{$contactEmail}" style="color:#2563eb">{$contactEmail}</a></p>
	</div>

	<div style="text-align:center;padding:16px 0 32px;font-size:0.85em;color:#666">
		<a href="{$guidelinesUrl}" style="color:#2563eb">Review &amp; Dispute Policy</a>
	</div>

</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: dealerRegister (self-service onboarding) ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'dealerRegister',
		'template_data' => '$form, $tier, $name, $guidelinesUrl',
		'template_content' => <<<'TEMPLATE_EOT'
<div style="max-width:600px;margin:0 auto;padding:24px 16px">
	<div class="ipsBox">
		<div style="padding:32px 28px;text-align:center;border-bottom:1px solid var(--i-border-color,#e0e0e0)">
			<i class="fa-solid fa-store" style="font-size:2.5em;color:#2563eb;margin-bottom:12px;display:block" aria-hidden="true"></i>
			<h1 style="margin:0 0 8px;font-size:1.4em;font-weight:800">Complete Your Dealer Setup</h1>
			<p style="margin:0;color:#666">Your subscription is active. Just a few details to get your dealer profile live.</p>
			<div style="margin-top:12px">
				<span style="background:#2563eb;color:#fff;padding:3px 12px;border-radius:20px;font-size:0.8em;font-weight:700;text-transform:uppercase">{$tier} Plan</span>
			</div>
		</div>
		<div style="padding:28px">
			{$form|raw}
		</div>
	</div>
	<p style="text-align:center;margin-top:16px;font-size:0.85em;color:#888">
		Need help? Visit our <a href="{$guidelinesUrl}" style="color:#2563eb">Review &amp; Setup Guidelines</a>
	</p>
</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: dealerReviews ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'dealerReviews',
		'template_data' => '$data, $csrfKey',
		'template_content' => <<<'TEMPLATE_EOT'
<div style="margin-bottom:24px">

	<div class="gdStatCards gdRatingCards" style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap">
		<div style="flex:1 1 160px;background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:16px;text-align:center">
			<div class="gdStatCard__value" style="font-size:2em;font-weight:800;color:{$data['rating_color']};line-height:1">{$data['avg_overall']}</div>
			<div style="color:#666;font-size:0.85em;margin-top:4px">Overall Rating</div>
			<div style="font-size:0.72em;font-weight:600;color:{$data['rating_color']};margin-top:4px">{$data['rating_label']}</div>
			<div style="color:#999;font-size:0.8em;margin-top:4px">{$data['total']} reviews</div>
		</div>
		<div style="flex:1 1 160px;background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:16px;text-align:center">
			<div class="gdStatCard__value" style="font-size:2em;font-weight:800;color:{$data['color_pricing']}">{$data['avg_pricing']}</div>
			<div style="color:#666;font-size:0.85em">Pricing Accuracy</div>
		</div>
		<div style="flex:1 1 160px;background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:16px;text-align:center">
			<div class="gdStatCard__value" style="font-size:2em;font-weight:800;color:{$data['color_shipping']}">{$data['avg_shipping']}</div>
			<div style="color:#666;font-size:0.85em">Shipping Speed</div>
		</div>
		<div style="flex:1 1 160px;background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:16px;text-align:center">
			<div class="gdStatCard__value" style="font-size:2em;font-weight:800;color:{$data['color_service']}">{$data['avg_service']}</div>
			<div style="color:#666;font-size:0.85em">Customer Service</div>
		</div>
	</div>

	{{if $data['disputes_suspended']}}
	<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
		<div>
			<strong style="color:#991b1b">Disputes Suspended</strong>
			<span style="color:#7f1d1d;margin-left:6px">&mdash; Your ability to contest reviews has been suspended by a site administrator. Contact <a href="mailto:{$data['help_email']}" style="color:#2563eb">{$data['help_email']}</a> for more information.</span>
		</div>
	</div>
	{{endif}}

	<div style="background:#f8fafc;border:1px solid var(--i-border-color,#e0e0e0);border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:0.85em;color:#334155">
		{{if $data['disputes_unlimited']}}
			You have <strong>unlimited</strong> review contests this month (Enterprise plan).
		{{else}}
			You have <strong>{$data['disputes_remaining']}</strong> review contests remaining this month.
		{{endif}}
	</div>

	<div class="gdSubNav" style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
		{{foreach $data['subNav'] as $tab}}
		<a href="{$tab['url']}" style="display:inline-block;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;{{if $tab['active']}}background:#1e3a5f;color:#fff{{else}}background:#f1f5f9;color:#64748b{{endif}}">{$tab['label']} ({$tab['count']})</a>
		{{endforeach}}
	</div>

	<form method="get" action="{$data['filterFormUrl']}" class="gdFilterBar" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;padding:12px 16px;background:#f8fafc;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px">
		<input type="hidden" name="tab" value="{$data['activeTab']}">
		<select name="rating" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;background:#fff">
			<option value="all"{{if $data['ratingFilter'] === 'all'}} selected{{endif}}>All ratings</option>
			<option value="5"{{if $data['ratingFilter'] === '5'}} selected{{endif}}>5&#9733; only</option>
			<option value="4-5"{{if $data['ratingFilter'] === '4-5'}} selected{{endif}}>4-5&#9733;</option>
			<option value="3-"{{if $data['ratingFilter'] === '3-'}} selected{{endif}}>3&#9733; and below</option>
			<option value="1-2"{{if $data['ratingFilter'] === '1-2'}} selected{{endif}}>1-2&#9733; only</option>
		</select>
		<select name="date" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;background:#fff">
			<option value="any"{{if $data['dateFilter'] === 'any'}} selected{{endif}}>Any date</option>
			<option value="7"{{if $data['dateFilter'] === '7'}} selected{{endif}}>Last 7 days</option>
			<option value="30"{{if $data['dateFilter'] === '30'}} selected{{endif}}>Last 30 days</option>
			<option value="90"{{if $data['dateFilter'] === '90'}} selected{{endif}}>Last 90 days</option>
			<option value="year"{{if $data['dateFilter'] === 'year'}} selected{{endif}}>This year</option>
		</select>
		<input type="text" name="q" value="{$data['search']}" placeholder="Search reviews..." style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;flex:1 1 160px;min-width:120px">
		<button type="submit" style="padding:6px 16px;background:#1e3a5f;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer">Apply</button>
	</form>

	{{if $data['totalCount'] > 0}}
		<div style="font-size:0.8em;color:#64748b;margin-bottom:10px">{expression="number_format( $data['totalCount'] )"} {expression="$data['totalCount'] === 1 ? 'review' : 'reviews'"} found</div>
	{{endif}}

	{{if count($data['rows']) === 0}}
		<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:32px;text-align:center;color:#64748b">
			{{if $data['activeTab'] === 'attention'}}
				<p style="margin:0;font-size:0.95em">No reviews need your attention right now.</p>
			{{elseif $data['activeTab'] === 'contested'}}
				<p style="margin:0;font-size:0.95em">No contested reviews.</p>
			{{else}}
				<p style="margin:0;font-size:0.95em">No reviews match your filters.</p>
			{{endif}}
		</div>
	{{else}}
		{{foreach $data['rows'] as $r}}
		<div class="gdReviewCard" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:20px;margin-bottom:12px">
			<div class="gdReviewCard__header" style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap;gap:8px">
				<div class="gdReviewCard__ratings" style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
					<span style="font-size:0.8em;font-weight:700;color:{$r['avg_color']};background:{$r['avg_color']}18;padding:2px 8px;border-radius:12px">{$r['avg_overall']} / 5</span>
					<span style="font-size:0.8em;color:#666">Pricing: <strong>{$r['rating_pricing']}/5</strong></span>
					<span style="font-size:0.8em;color:#666">Shipping: <strong>{$r['rating_shipping']}/5</strong></span>
					<span style="font-size:0.8em;color:#666">Service: <strong>{$r['rating_service']}/5</strong></span>
				</div>
				<span style="font-size:0.8em;color:#999">{$r['created_at']}</span>
			</div>

			{{if $r['review_body']}}
			<div style="margin:0 0 12px;color:#333">{$r['review_body']|raw}</div>
			{{if count($r['review_body_attachments']) > 0}}
			{{if $r['review_body_has_unembedded_images']}}<p style="font-size:12px;color:#9ca3af;margin:4px 0 4px">Attached images:</p>{{endif}}
			<div style="margin-bottom:12px;display:flex;flex-wrap:wrap;gap:8px">
			{{foreach $r['review_body_attachments'] as $att}}
			{{if $att['is_image']}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:block;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;background:#fff"><img src="{$att['thumb_url']}" alt="{$att['file_name']}" style="display:block;max-width:120px;max-height:120px;object-fit:cover" loading="lazy"></a>{{else}}<a href="{$att['url']}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;color:#374151;text-decoration:none;font-size:13px"><i class="fa-solid fa-paperclip" aria-hidden="true"></i> {$att['file_name']}</a>{{endif}}
			{{endforeach}}
			</div>
			{{endif}}
			{{endif}}

			{{if $r['dealer_response']}}
				<div data-resp="{$r['id']}" style="background:#f0f7ff;border-left:3px solid #2563eb;padding:12px 16px;border-radius:0 6px 6px 0;margin-top:8px">
					<div class="gd-resp-head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:8px;flex-wrap:wrap">
						<div style="font-size:0.8em;color:#2563eb;font-weight:700">Your response &mdash; {$r['response_at']}</div>
						<div class="gd-resp-actions" style="display:flex;gap:8px;align-items:center">
							<a href="#" onclick="var w=this.closest('div[data-resp]');w.querySelector('.gd-resp-text').style.display='none';w.querySelector('.gd-resp-actions').style.display='none';w.querySelector('.gd-resp-edit').style.display='block';return false" style="font-size:0.75em;color:#2563eb;font-weight:600;text-decoration:none">Edit</a>
							<form method="post" action="{$r['delete_response_url']}" style="display:inline;margin:0" onsubmit="return confirm('Delete your response?')">
								<input type="hidden" name="csrfKey" value="{$csrfKey}">
								<button type="submit" style="background:none;border:none;padding:0;font-size:0.75em;color:#dc2626;font-weight:600;cursor:pointer">Delete</button>
							</form>
						</div>
					</div>
					<div class="gd-resp-text" style="margin:0;font-size:0.9em">{$r['dealer_response']|raw}</div>
					<form class="gd-resp-edit" method="post" action="{$r['respond_url']}" style="display:none;margin-top:8px">
						<input type="hidden" name="csrfKey" value="{$csrfKey}">
						{$r['edit_editor_html']|raw}
						<div style="display:flex;gap:6px;margin-top:8px">
							<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Save changes</button>
							<button type="button" onclick="var w=this.closest('div[data-resp]');w.querySelector('.gd-resp-text').style.display='block';w.querySelector('.gd-resp-actions').style.display='flex';w.querySelector('.gd-resp-edit').style.display='none';return false" class="ipsButton ipsButton--normal ipsButton--small">Cancel</button>
						</div>
					</form>
				</div>
			{{endif}}

			{{if $r['dispute_status'] === 'pending_customer'}}
				<div style="background:#fff8f0;border-left:3px solid #f59e0b;padding:10px 14px;border-radius:0 4px 4px 0;margin-top:8px;font-size:0.85em;color:#92400e">
					<strong>Contest submitted &mdash; awaiting customer response.</strong>
					{{if $r['dispute_deadline']}}<div style="font-size:0.8em;margin-top:2px">Customer has until {$r['dispute_deadline']} to respond.</div>{{endif}}
				</div>
			{{endif}}

			{{if $r['dispute_status'] === 'pending_admin'}}
				<div style="background:#eff6ff;border-left:3px solid #2563eb;padding:10px 14px;border-radius:0 4px 4px 0;margin-top:8px;font-size:0.85em;color:#1e3a8a">
					<strong>Contest under admin review.</strong> The customer has responded and admin will resolve shortly.
				</div>
			{{endif}}

			{{if $r['dispute_status'] === 'resolved_dealer'}}
				<div style="background:#f0fdf4;border-left:3px solid #16a34a;padding:10px 14px;border-radius:0 4px 4px 0;margin-top:8px;font-size:0.85em;color:#14532d">
					<strong>Contest resolved in your favor.</strong> This review no longer affects your rating average.
				</div>
			{{endif}}

			{{if $r['dispute_status'] === 'resolved_customer'}}
				<div style="background:#fef2f2;border-left:3px solid #dc2626;padding:10px 14px;border-radius:0 4px 4px 0;margin-top:8px;font-size:0.85em;color:#7f1d1d">
					<strong>Contest resolved in the customer's favor.</strong> The review stands.
				</div>
			{{endif}}

			{{if $r['dispute_status'] === 'dismissed'}}
				<div style="background:#f1f5f9;border-left:3px solid #64748b;padding:10px 14px;border-radius:0 4px 4px 0;margin-top:8px;font-size:0.85em;color:#334155">
					<strong>Contest dismissed.</strong> This review cannot be contested again.
				</div>
			{{endif}}

			{{if count($r['events']) > 0}}
			<div style="margin-top:14px;border-top:1px solid var(--i-border-color,#e5e7eb);padding-top:12px">
				<div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8px">Dispute history</div>
				{{foreach $r['events'] as $e}}
				<div style="display:flex;gap:12px;margin-bottom:8px;font-size:13px;color:#374151">
					<span style="color:#9ca3af;font-size:12px;flex-shrink:0;width:160px">{$e['when']}</span>
					<div style="flex:1">
						<strong style="text-transform:capitalize">{$e['actor_role']}</strong> {$e['verb']}
						{{if $e['note']}}
						<div style="font-size:12px;color:#4b5563;margin-top:4px;padding:8px 12px;background:#f9fafb;border-radius:6px;border-left:2px solid #d1d5db">{$e['note']}</div>
						{{endif}}
					</div>
				</div>
				{{endforeach}}
			</div>
			{{endif}}

			{{if $r['dispute_status'] === 'none'}}
				{{if $r['dealer_response'] === ''}}
				<details style="margin-top:8px">
					<summary style="cursor:pointer;font-size:0.85em;color:#2563eb;font-weight:600">Respond to this review</summary>
					<form method="post" action="{$r['respond_url']}" style="margin-top:8px">
						<input type="hidden" name="csrfKey" value="{$csrfKey}">
						{$r['respond_editor_html']|raw}
						<button type="submit" class="ipsButton ipsButton--primary ipsButton--small" style="margin-top:8px">Post Response</button>
					</form>
				</details>
				{{endif}}

				{{if $data['disputes_unlimited']}}
				<details style="margin-top:8px">
					<summary style="cursor:pointer;font-size:0.85em;color:#dc2626;font-weight:600">Contest this review</summary>
					<form method="post" action="{$r['dispute_url']}" style="margin-top:8px">
						<input type="hidden" name="csrfKey" value="{$csrfKey}">
						<p style="margin:0 0 8px;font-size:0.8em;color:#666">Read the <a href="{$data['guidelines_url']}" style="color:#2563eb" target="_blank">Dispute Guidelines</a> before contesting a review.</p>
						<label style="display:block;font-size:0.8em;font-weight:600;margin-bottom:4px">Reason for contest</label>
						<div style="margin-bottom:8px">{$r['dispute_reason_editor_html']|raw}</div>
						<label style="display:block;font-size:0.8em;font-weight:600;margin-bottom:4px">Supporting evidence (order numbers, screenshots, transaction IDs)</label>
						<div>{$r['dispute_evidence_editor_html']|raw}</div>
						<button type="submit" class="ipsButton ipsButton--negative ipsButton--small" style="margin-top:8px">Submit Contest</button>
					</form>
				</details>
				{{else}}
					{{if $data['disputes_remaining'] > 0}}
					<details style="margin-top:8px">
						<summary style="cursor:pointer;font-size:0.85em;color:#dc2626;font-weight:600">Contest this review</summary>
						<form method="post" action="{$r['dispute_url']}" style="margin-top:8px">
							<input type="hidden" name="csrfKey" value="{$csrfKey}">
							<p style="margin:0 0 8px;font-size:0.8em;color:#666">Read the <a href="{$data['guidelines_url']}" style="color:#2563eb" target="_blank">Dispute Guidelines</a> before contesting a review.</p>
							<label style="display:block;font-size:0.8em;font-weight:600;margin-bottom:4px">Reason for contest</label>
							<div style="margin-bottom:8px">{$r['dispute_reason_editor_html']|raw}</div>
							<label style="display:block;font-size:0.8em;font-weight:600;margin-bottom:4px">Supporting evidence (order numbers, screenshots, transaction IDs)</label>
							<div>{$r['dispute_evidence_editor_html']|raw}</div>
							<button type="submit" class="ipsButton ipsButton--negative ipsButton--small" style="margin-top:8px">Submit Contest</button>
						</form>
					</details>
					{{else}}
					<div style="background:#f1f5f9;border-left:3px solid #64748b;padding:8px 12px;border-radius:0 4px 4px 0;margin-top:8px;font-size:0.8em;color:#475569">
						You have reached your monthly contest limit. Upgrade your plan for more contests.
					</div>
					{{endif}}
				{{endif}}
			{{endif}}
		</div>
		{{endforeach}}

		{{if count($data['pageLinks']) > 0}}
		<div class="gdPagination" style="display:flex;justify-content:center;gap:4px;margin-top:20px;flex-wrap:wrap">
			{{foreach $data['pageLinks'] as $pl}}
				{{if $pl['disabled']}}
					<span style="padding:6px 10px;font-size:13px;color:#9ca3af">{$pl['label']}</span>
				{{elseif $pl['active']}}
					<span style="padding:6px 12px;background:#1e3a5f;color:#fff;border-radius:6px;font-size:13px;font-weight:700">{$pl['label']}</span>
				{{else}}
					<a href="{$pl['url']}" style="padding:6px 12px;background:#f1f5f9;color:#374151;border-radius:6px;font-size:13px;text-decoration:none;font-weight:600">{$pl['label']}</a>
				{{endif}}
			{{endforeach}}
		</div>
		{{endif}}
	{{endif}}

</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: dealerProfile ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'dealerProfile',
		'template_data' => '$data',
		'template_content' => <<<'TEMPLATE_EOT'
<style>
.gdDealerPage {
	--gd-brand: {expression="$data['dealer']['brand_color'] ?? '#1E40AF'"};
	--gd-brand-hover: #1E3A8A;
	--gd-brand-light: #EFF6FF;
	--gd-brand-border: #BFDBFE;
	--gd-tier-founding: #B45309;
	--gd-tier-founding-bg: #FEF3C7;
	--gd-tier-basic: #64748B;
	--gd-tier-basic-bg: #F1F5F9;
	--gd-tier-pro: #1E40AF;
	--gd-tier-pro-bg: #DBEAFE;
	--gd-tier-enterprise: #6D28D9;
	--gd-tier-enterprise-bg: #EDE9FE;
	--gd-success: #047857;
	--gd-success-bg: #D1FAE5;
	--gd-warn: #B45309;
	--gd-warn-bg: #FEF3C7;
	--gd-danger: #B91C1C;
	--gd-danger-bg: #FEE2E2;
	--gd-surface: #FFFFFF;
	--gd-surface-muted: #F8FAFC;
	--gd-border: #E5E7EB;
	--gd-border-strong: #CBD5E1;
	--gd-border-subtle: #F1F5F9;
	--gd-text: #0F172A;
	--gd-text-muted: #475569;
	--gd-text-subtle: #64748B;
	--gd-text-faint: #94A3B8;
	--gd-rating-great: #16A34A;
	--gd-star: #F59E0B;
	--gd-r-md: 6px;
	--gd-r-lg: 10px;
	--gd-r-xl: 14px;
	--gd-r-pill: 999px;
	font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	color: var(--gd-text);
	font-size: 14px;
	line-height: 1.5;
	max-width: 1446px;
	margin: 0 auto;
	padding: 1.5rem;
	box-sizing: border-box;
}
.gdDealerPage *, .gdDealerPage *::before, .gdDealerPage *::after { box-sizing: border-box; }
.gdDealerPage a { color: inherit; text-decoration: none; }
.gdDealerPage h1, .gdDealerPage h2, .gdDealerPage h3 { margin: 0; font-weight: 600; }
.gdDealerPage p { margin: 0; }

.gdDealerPage .gd-breadcrumbs { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--gd-text-subtle); margin-bottom: 1rem; }
.gdDealerPage .gd-breadcrumbs a { color: var(--gd-text-muted); }
.gdDealerPage .gd-breadcrumbs a:hover { color: var(--gd-text); text-decoration: underline; }
.gdDealerPage .gd-breadcrumbs .sep { color: var(--gd-text-faint); }

.gdDealerPage .hero { background: var(--gd-surface); border: 1px solid var(--gd-border); border-radius: var(--gd-r-xl); overflow: hidden; margin-bottom: 1.5rem; }
.gdDealerPage .hero-cover { height: 180px; background: var(--gd-brand); background-image: linear-gradient(135deg, var(--gd-brand) 0%, color-mix(in srgb, var(--gd-brand) 70%, white) 100%); position: relative; background-size: cover; background-position: center; }
.gdDealerPage .hero-body { padding: 0 2rem 1.5rem; position: relative; }
.gdDealerPage .hero-identity { display: grid; grid-template-columns: auto 1fr auto; gap: 1.25rem; align-items: start; margin-top: -46px; margin-bottom: 1.25rem; position: relative; z-index: 1; }
.gdDealerPage .hero-name-block { padding-top: 50px; min-width: 0; }
.gdDealerPage .hero-actions { padding-top: 50px; }
.gdDealerPage .hero-avatar { width: 92px; height: 92px; border-radius: var(--gd-r-xl); background: var(--gd-brand); color: white; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 32px; border: 4px solid var(--gd-surface); flex-shrink: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; line-height: 1; }
.gdDealerPage .hero-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.gdDealerPage .hero-name-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 4px; row-gap: 6px; }
.gdDealerPage .hero-name-meta { display: inline-flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 13px; color: var(--gd-text-subtle); margin-left: 4px; }
.gdDealerPage .hero-name-meta .hero-meta-item { gap: 5px; }
.gdDealerPage .hero-name-meta .hero-meta-icon { width: 18px; height: 18px; border-radius: 5px; }
.gdDealerPage .hero-name-meta .hero-meta-icon svg { width: 10px; height: 10px; }
.gdDealerPage .hero-rating-stars { display: inline-flex; gap: 2px; vertical-align: middle; margin-left: 6px; }
.gdDealerPage .hero-rating-stars svg { width: 16px; height: 16px; }
.gdDealerPage .rating-stars-row { display: inline-flex; gap: 3px; align-items: center; vertical-align: middle; margin-left: 10px; }
.gdDealerPage .rating-stars-row svg { width: 22px; height: 22px; color: var(--gd-star); }
@media (max-width: 640px) {
	.gdDealerPage .hero-name-meta { width: 100%; margin-left: 0; }
}
.gdDealerPage .hero-name { font-size: 28px; font-weight: 600; letter-spacing: -0.02em; line-height: 1.2; color: var(--gd-text); }
.gdDealerPage .hero-tagline { font-size: 14px; color: var(--gd-text-muted); margin-bottom: 6px; }
.gdDealerPage .hero-meta { display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; font-size: 13px; color: var(--gd-text-subtle); }
.gdDealerPage .hero-meta-item { display: inline-flex; align-items: center; gap: 6px; }
.gdDealerPage .hero-meta-icon { width: 22px; height: 22px; border-radius: 6px; background: var(--gd-brand-light); color: var(--gd-brand); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.gdDealerPage .hero-meta-icon svg { width: 12px; height: 12px; }
.gdDealerPage .hero-meta-sep { color: var(--gd-text-faint); }
.gdDealerPage .hero-actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; align-items: flex-start; }

.gdDealerPage .badge { font-size: 11px; padding: 3px 10px; border-radius: var(--gd-r-pill); font-weight: 600; display: inline-flex; align-items: center; gap: 4px; text-transform: uppercase; }
.gdDealerPage .badge-founding { background: var(--gd-tier-founding-bg); color: var(--gd-tier-founding); }
.gdDealerPage .badge-basic { background: var(--gd-tier-basic-bg); color: var(--gd-tier-basic); }
.gdDealerPage .badge-pro { background: var(--gd-tier-pro-bg); color: var(--gd-tier-pro); }
.gdDealerPage .badge-enterprise { background: var(--gd-tier-enterprise-bg); color: var(--gd-tier-enterprise); }
.gdDealerPage .badge-inactive { background: var(--gd-surface-muted); color: var(--gd-text-subtle); }

.gdDealerPage .hero-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; border-top: 1px solid var(--gd-border-subtle); padding-top: 1.25rem; }
.gdDealerPage .hero-stat { padding: 0 1.25rem; border-right: 1px solid var(--gd-border-subtle); }
.gdDealerPage .hero-stat:last-child { border-right: none; padding-right: 0; }
.gdDealerPage .hero-stat:first-child { padding-left: 0; }
.gdDealerPage .hero-stat-label { font-size: 11px; color: var(--gd-text-subtle); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600; margin-bottom: 6px; }
.gdDealerPage .hero-stat-value { font-size: 24px; font-weight: 600; line-height: 1.1; color: var(--gd-text); }
.gdDealerPage .hero-stat-sub { font-size: 12px; color: var(--gd-text-subtle); margin-top: 2px; }

.gdDealerPage .btn { font-family: inherit; font-size: 13px; font-weight: 500; padding: 8px 14px; border-radius: var(--gd-r-md); cursor: pointer; border: 1px solid transparent; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; line-height: 1.2; }
.gdDealerPage .btn-primary { background: var(--gd-brand); color: #fff; }
.gdDealerPage .btn-primary:hover { background: var(--gd-brand-hover); color: #fff; }
.gdDealerPage .btn-secondary { background: var(--gd-surface); border-color: var(--gd-border); color: var(--gd-text); }
.gdDealerPage .btn-secondary:hover { background: var(--gd-surface-muted); border-color: var(--gd-border-strong); color: var(--gd-text); }
.gdDealerPage .btn-ghost { background: transparent; color: var(--gd-text-muted); border-color: var(--gd-border); }

.gdDealerPage .gd-grid { display: grid; grid-template-columns: 1fr 320px; gap: 1.5rem; align-items: start; }
.gdDealerPage .card { background: var(--gd-surface); border: 1px solid var(--gd-border); border-radius: var(--gd-r-lg); padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; }
.gdDealerPage .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; flex-wrap: wrap; gap: 8px; }
.gdDealerPage .card-title { font-size: 16px; font-weight: 600; color: var(--gd-text); }
.gdDealerPage .card-sub { font-size: 13px; color: var(--gd-text-subtle); margin-top: 2px; }

.gdDealerPage .rating-breakdown { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
.gdDealerPage .rating-summary { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 1rem 0; }
.gdDealerPage .rating-big-number { font-size: 56px; font-weight: 600; letter-spacing: -0.03em; line-height: 1; margin-bottom: 8px; }
.gdDealerPage .rating-total-count { font-size: 13px; color: var(--gd-text-subtle); }
.gdDealerPage .rating-label { font-size: 13px; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.04em; }
.gdDealerPage .rating-bars { display: flex; flex-direction: column; gap: 10px; padding: 1rem 0; }
.gdDealerPage .rating-bar-row { display: grid; grid-template-columns: 70px 1fr 50px; align-items: center; gap: 10px; font-size: 13px; }
.gdDealerPage .rating-bar-label { color: var(--gd-text-muted); font-weight: 500; }
.gdDealerPage .rating-bar-track { height: 8px; background: var(--gd-border-subtle); border-radius: var(--gd-r-pill); overflow: hidden; }
.gdDealerPage .rating-bar-fill { height: 100%; background: var(--gd-star); border-radius: var(--gd-r-pill); }
.gdDealerPage .rating-bar-count { text-align: right; color: var(--gd-text-subtle); font-size: 12px; font-variant-numeric: tabular-nums; }
.gdDealerPage .rating-dimensions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; border-top: 1px solid var(--gd-border-subtle); padding-top: 1.25rem; margin-top: 1.25rem; }
.gdDealerPage .rating-dim { text-align: center; }
.gdDealerPage .rating-dim-label { font-size: 12px; color: var(--gd-text-subtle); font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px; }
.gdDealerPage .rating-dim-value { font-size: 22px; font-weight: 600; margin-bottom: 2px; }

.gdDealerPage .filter-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; background: var(--gd-surface); border: 1px solid var(--gd-border); border-radius: var(--gd-r-lg); margin-bottom: 1rem; flex-wrap: wrap; }
.gdDealerPage .filter-left { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.gdDealerPage .filter-right { display: flex; gap: 8px; align-items: center; }
.gdDealerPage .filter-chip { font-size: 12px; padding: 5px 12px; border-radius: var(--gd-r-pill); background: var(--gd-surface-muted); color: var(--gd-text-muted); cursor: pointer; font-weight: 500; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; }
.gdDealerPage .filter-chip:hover { background: var(--gd-border-subtle); color: var(--gd-text); }
.gdDealerPage .filter-chip.active { background: var(--gd-brand-light); color: var(--gd-brand); border-color: var(--gd-brand-border); }
.gdDealerPage .filter-chip-count { font-size: 11px; opacity: 0.7; font-variant-numeric: tabular-nums; }
.gdDealerPage .select-sm { font-family: inherit; font-size: 12px; padding: 6px 10px; border-radius: var(--gd-r-md); border: 1px solid var(--gd-border); background: var(--gd-surface); cursor: pointer; color: var(--gd-text); }

.gdDealerPage .empty { text-align: center; padding: 3rem 2rem; background: var(--gd-surface); border: 1px dashed var(--gd-border-strong); border-radius: var(--gd-r-lg); }
.gdDealerPage .empty-title { font-size: 15px; font-weight: 600; margin-bottom: 4px; color: var(--gd-text); }
.gdDealerPage .empty-sub { font-size: 13px; color: var(--gd-text-subtle); max-width: 360px; margin: 0 auto; }
.gdDealerPage .review { background: var(--gd-surface); border: 1px solid var(--gd-border); border-radius: var(--gd-r-lg); padding: 1.25rem 1.5rem; margin-bottom: 12px; }
.gdDealerPage .review-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
.gdDealerPage .review-reviewer { display: flex; gap: 10px; align-items: center; }
.gdDealerPage .review-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #818CF8, #6366F1); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; flex-shrink: 0; overflow: hidden; line-height: 1; }
.gdDealerPage .review-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.gdDealerPage .review-name { font-size: 14px; font-weight: 600; margin-bottom: 2px; color: var(--gd-text); }
.gdDealerPage .review-name .verified-tag { font-size: 11px; color: var(--gd-success); margin-left: 6px; font-weight: 500; }
.gdDealerPage .review-date { font-size: 12px; color: var(--gd-text-subtle); }
.gdDealerPage .review-score { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: var(--gd-surface-muted); border-radius: var(--gd-r-pill); font-size: 12px; font-weight: 600; flex-wrap: wrap; }
.gdDealerPage .review-dimensions { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; padding: 10px 12px; background: var(--gd-surface-muted); border-radius: var(--gd-r-md); }
.gdDealerPage .review-dim { display: flex; align-items: center; gap: 6px; font-size: 12px; }
.gdDealerPage .review-dim-label { color: var(--gd-text-subtle); }
.gdDealerPage .review-dim-stars { color: var(--gd-star); font-size: 13px; letter-spacing: 1px; }
.gdDealerPage .review-body { font-size: 14px; line-height: 1.6; color: var(--gd-text); margin-bottom: 12px; word-wrap: break-word; }
.gdDealerPage .review-body p { margin: 0 0 0.5em 0; }
.gdDealerPage .review-body p:last-child { margin-bottom: 0; }
.gdDealerPage .review-response { background: var(--gd-brand-light); border-left: 3px solid var(--gd-brand); border-radius: 0 var(--gd-r-md) var(--gd-r-md) 0; padding: 12px 14px; margin-top: 12px; }
.gdDealerPage .review-response-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; font-size: 12px; flex-wrap: wrap; gap: 6px; }
.gdDealerPage .review-response-label { color: var(--gd-brand); font-weight: 600; }
.gdDealerPage .review-response-date { color: var(--gd-text-subtle); }
.gdDealerPage .review-response-body { font-size: 13px; color: var(--gd-text-muted); line-height: 1.6; word-wrap: break-word; }
.gdDealerPage .review-response-body p { margin: 0 0 0.5em 0; }
.gdDealerPage .review-response-body p:last-child { margin-bottom: 0; }
.gdDealerPage .review-dispute-badge { font-size: 11px; padding: 3px 10px; border-radius: var(--gd-r-pill); background: var(--gd-warn-bg); color: var(--gd-warn); font-weight: 500; }
.gdDealerPage .review-dispute-badge.resolved { background: var(--gd-success-bg); color: var(--gd-success); }
.gdDealerPage .review-dispute-badge.removed { background: var(--gd-danger-bg); color: var(--gd-danger); }
.gdDealerPage .review-own-actions { display: flex; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--gd-border-subtle); }
.gdDealerPage .review-score-num { font-variant-numeric: tabular-nums; }
.gdDealerPage .sidebar-card { background: var(--gd-surface); border: 1px solid var(--gd-border); border-radius: var(--gd-r-lg); padding: 1.25rem; margin-bottom: 1rem; }
.gdDealerPage .sidebar-title { font-size: 14px; font-weight: 600; margin-bottom: 14px; color: var(--gd-text); }
.gdDealerPage .info-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--gd-border-subtle); font-size: 13px; gap: 12px; }
.gdDealerPage .info-row:last-child { border-bottom: none; padding-bottom: 0; }
.gdDealerPage .info-row:first-child { padding-top: 0; }
.gdDealerPage .info-label { color: var(--gd-text-subtle); flex-shrink: 0; display: inline-flex; align-items: center; gap: 8px; }
.gdDealerPage .info-label-icon { width: 16px; height: 16px; color: var(--gd-text-faint); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.gdDealerPage .info-label-icon svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }
.gdDealerPage .info-value { color: var(--gd-text); font-weight: 500; text-align: right; word-break: break-word; min-width: 0; }
.gdDealerPage .info-value.mono { font-family: ui-monospace, 'SF Mono', Menlo, monospace; font-size: 12px; }
.gdDealerPage .info-value a { color: var(--gd-brand); }
.gdDealerPage .info-value a:hover { text-decoration: underline; }

.gdDealerPage .leave-review { background: linear-gradient(180deg, var(--gd-brand-light) 0%, var(--gd-surface) 100%); border: 1px solid var(--gd-brand-border); border-radius: var(--gd-r-lg); padding: 1.25rem; margin-bottom: 1rem; }
.gdDealerPage .leave-review-title { font-size: 14px; font-weight: 600; margin-bottom: 6px; color: var(--gd-text); }
.gdDealerPage .leave-review-sub { font-size: 12px; color: var(--gd-text-muted); line-height: 1.5; margin-bottom: 12px; }
.gdDealerPage .leave-review-btn { width: 100%; justify-content: center; }
.gdDealerPage .guidelines-link { font-size: 12px; color: var(--gd-text-subtle); display: inline-flex; align-items: center; gap: 4px; }
.gdDealerPage .guidelines-link:hover { color: var(--gd-text); text-decoration: underline; }

.gdDealerPage .about-body { font-size: 13px; color: var(--gd-text-muted); line-height: 1.6; word-wrap: break-word; }
.gdDealerPage .about-body p { margin: 0 0 0.5em 0; }
.gdDealerPage .about-body p:last-child { margin-bottom: 0; }

.gdDealerPage .socials-row { display: flex; flex-wrap: wrap; gap: 8px; }
.gdDealerPage .social-btn { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 8px; background: var(--gd-surface-muted); color: var(--gd-text-muted); border: 1px solid var(--gd-border); transition: all 0.15s; }
.gdDealerPage .social-btn:hover { background: var(--gd-brand); color: #fff; border-color: var(--gd-brand); transform: translateY(-1px); }
.gdDealerPage .social-btn svg { width: 18px; height: 18px; }

.gdDealerPage .review-form-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; font-size: 13px; gap: 12px; }
.gdDealerPage .review-form-row > label { color: var(--gd-text-muted); font-weight: 500; }
.gdDealerPage .star-input { display: inline-flex; gap: 2px; direction: rtl; }
.gdDealerPage .star-input input[type="radio"] { display: none; }
.gdDealerPage .star-input label { cursor: pointer; padding: 0; margin: 0; color: #D1D5DB; font-size: 20px; line-height: 1; }
.gdDealerPage .star-input label:hover,
.gdDealerPage .star-input label:hover ~ label,
.gdDealerPage .star-input input[type="radio"]:checked ~ label { color: var(--gd-star); }

@media (max-width: 960px) {
	.gdDealerPage .rating-breakdown { grid-template-columns: 1fr; }
	.gdDealerPage .rating-dimensions { grid-template-columns: 1fr; gap: 0.75rem; }
}
@media (max-width: 960px) {
	.gdDealerPage .gd-grid { grid-template-columns: 1fr; }
	.gdDealerPage .hero-stats { grid-template-columns: 1fr 1fr; gap: 1rem; }
	.gdDealerPage .hero-stat { border-right: none; padding: 0.75rem 0.5rem; text-align: left; }
	.gdDealerPage .hero-stat:first-child, .gdDealerPage .hero-stat:nth-child(2) { border-bottom: 1px solid var(--gd-border-subtle); }
	.gdDealerPage .hero-identity { grid-template-columns: 1fr; }
	.gdDealerPage .hero-name-block { padding-top: 0.75rem; }
	.gdDealerPage .hero-actions { width: 100%; padding-top: 0.75rem; flex-wrap: wrap; }
	.gdDealerPage .hero-actions .btn { flex: 1 1 auto; justify-content: center; min-width: 110px; }
	.gdDealerPage .hero-meta { gap: 0.75rem; }
	.gdDealerPage .hero-meta-sep { display: none; }
	.gdDealerPage .review-head { flex-direction: column; align-items: flex-start; }
	.gdDealerPage .review-score { align-self: flex-start; }
	.gdDealerPage .review-dimensions { gap: 12px; }
	.gdDealerPage .filter-row { flex-direction: column; align-items: stretch; gap: 10px; }
	.gdDealerPage .filter-right { justify-content: flex-end; }
	.gdDealerPage .select-sm { width: 100%; }
}
@media (max-width: 640px) {
	.gdDealerPage { padding: 0.75rem; }
	.gdDealerPage .hero-body { padding: 0 1rem 1rem; }
	.gdDealerPage .hero-cover { height: 120px; }
	.gdDealerPage .hero-stats { grid-template-columns: 1fr; }
	.gdDealerPage .hero-stat { border-bottom: 1px solid var(--gd-border-subtle); }
	.gdDealerPage .hero-stat:last-child { border-bottom: none; }
	.gdDealerPage .hero-name { font-size: 22px; }
	.gdDealerPage .hero-avatar { width: 72px; height: 72px; font-size: 26px; }
	.gdDealerPage .card { padding: 1rem; }
	.gdDealerPage .review { padding: 1rem; }
	.gdDealerPage .rating-big-number { font-size: 44px; }
	.gdDealerPage .info-row { font-size: 12px; }
}
</style>

<div class="gdDealerPage">

	<nav class="gd-breadcrumbs">
		<a href="{expression="(string) \IPS\Http\Url::internal( 'app=gddealer&module=dealers&controller=directory', 'front', 'dealers_directory' )"}">Dealers</a>
		<span class="sep">/</span>
		<span>{$data['dealer']['dealer_name']}</span>
	</nav>

	<div class="hero">
		<div class="hero-cover"{{if $data['dealer']['cover_photo_url']}} style="{expression="'background-image: url(' . $data['dealer']['cover_photo_url'] . ');'"}"{{endif}}></div>
		<div class="hero-body">

			<div class="hero-identity">
				<div class="hero-avatar">
					{{if $data['dealer']['logo_url']}}
						<img src="{$data['dealer']['logo_url']}" alt="{$data['dealer']['dealer_name']}">
					{{elseif $data['dealer']['avatar_url']}}
						<img src="{$data['dealer']['avatar_url']}" alt="{$data['dealer']['dealer_name']}">
					{{else}}
						{expression="mb_strtoupper( mb_substr( $data['dealer']['dealer_name'], 0, 1 ) )"}
					{{endif}}
				</div>
				<div class="hero-name-block">
					<div class="hero-name-row">
						<h1 class="hero-name">{$data['dealer']['dealer_name']}</h1>
						{{if $data['dealer']['verified']}}
						<span title="Verified FFL on file" style="display: inline-flex; align-items: center; color: var(--gd-brand);">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2 4 5v6c0 5.5 3.8 10.7 8 12 4.2-1.3 8-6.5 8-12V5l-8-3zm-1.4 14.2L7 12.6l1.4-1.4 2.2 2.2 4.4-4.4L16.4 10l-5.8 6.2z"/></svg>
						</span>
						{{endif}}
						{{if $data['dealer']['tier_label']}}
						<span class="{expression="'badge badge-' . $data['dealer']['tier']"}">{$data['dealer']['tier_label']}</span>
						{{endif}}
						{{if !$data['dealer']['is_active']}}
						<span class="badge badge-inactive">Inactive</span>
						{{endif}}
						<span class="hero-name-meta">
							{{if $data['dealer']['address_city_state']}}
							<span class="hero-meta-item">
								<span class="hero-meta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 7-8 12-8 12s-8-5-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
								{$data['dealer']['address_city_state']}
							</span>
							{{endif}}
							{{if $data['dealer']['member_since']}}
							<span class="hero-meta-item">
								<span class="hero-meta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
								Since {$data['dealer']['member_since']}
							</span>
							{{endif}}
							{{if $data['dealer']['website_url']}}
							<span class="hero-meta-item">
								<span class="hero-meta-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span>
								<a href="{$data['dealer']['website_url']}" target="_blank" rel="nofollow noopener" style="color: var(--gd-brand);">Website</a>
							</span>
							{{endif}}
						</span>
					</div>
					{{if $data['dealer']['tagline']}}
					<div class="hero-tagline">{$data['dealer']['tagline']}</div>
					{{endif}}
				</div>
				<div class="hero-actions">
					{{if $data['dealer']['contact_email']}}
					<a class="btn btn-secondary" href="{expression="'mailto:' . $data['dealer']['contact_email']"}">Contact</a>
					{{endif}}
					{{if $data['can_rate']}}
					<a class="btn btn-primary" href="#gd-leave-review">Write a review</a>
					{{elseif $data['login_required']}}
					<a class="btn btn-primary" href="{$data['login_url']}">Sign in to review</a>
					{{endif}}
				</div>
			</div>

			<div class="hero-stats">
				<div class="hero-stat">
					<div class="hero-stat-label">Overall rating</div>
					<div class="hero-stat-value" style="{expression="'color: ' . ( $data['stats']['rating_color'] ?? '#16A34A' )"}">
						<span>{$data['stats']['avg_overall']}</span>
						<span class="hero-rating-stars">
							{{for $i=1; $i<=5; $i++}}
								{{if $i <= (int) $data['stats']['avg_overall']}}
									<svg viewBox="0 0 24 24" fill="var(--gd-star)" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
								{{else}}
									<svg viewBox="0 0 24 24" fill="#E5E7EB" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
								{{endif}}
							{{endfor}}
						</span>
					</div>
					<div class="hero-stat-sub">{$data['stats']['total']} reviews · {$data['stats']['rating_label']}</div>
				</div>
				<div class="hero-stat">
					<div class="hero-stat-label">Active listings</div>
					<div class="hero-stat-value">{expression="number_format( (int) $data['dealer']['active_listings'] )"}</div>
					<div class="hero-stat-sub">{{if $data['dealer']['listings_updated']}}Updated {$data['dealer']['listings_updated']}{{else}}&nbsp;{{endif}}</div>
				</div>
				<div class="hero-stat">
					<div class="hero-stat-label">Response rate</div>
					<div class="hero-stat-value">{{if $data['dealer']['response_rate']}}{$data['dealer']['response_rate']}%{{else}}—{{endif}}</div>
					<div class="hero-stat-sub">{{if $data['dealer']['response_window']}}Within {$data['dealer']['response_window']}{{else}}&nbsp;{{endif}}</div>
				</div>
				<div class="hero-stat">
					<div class="hero-stat-label">Tier</div>
					<div class="hero-stat-value" style="font-size: 20px;">{$data['dealer']['tier_label']}</div>
					<div class="hero-stat-sub">{{if $data['dealer']['tier_perk']}}{$data['dealer']['tier_perk']}{{else}}&nbsp;{{endif}}</div>
				</div>
			</div>

		</div>
	</div>

	<div class="gd-grid">
		<div>
			<div class="card">
				<div class="card-header">
					<div>
						<div class="card-title">Ratings &amp; reviews</div>
						<div class="card-sub">{expression="'Based on ' . (int) $data['stats']['total'] . ' verified transaction' . ( (int) $data['stats']['total'] === 1 ? '' : 's' )"}</div>
					</div>
				</div>
				{{if $data['stats']['total'] > 0}}
				<div class="rating-breakdown">
					<div class="rating-summary">
						<div class="rating-big-number" style="{expression="'color: ' . ( $data['stats']['rating_color'] ?? '#16A34A' )"}">
							{$data['stats']['avg_overall']}
							<span class="rating-stars-row">
								{{for $i=1; $i<=5; $i++}}
									{{if $i <= (int) $data['stats']['avg_overall']}}
										<svg viewBox="0 0 24 24" fill="var(--gd-star)" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
									{{else}}
										<svg viewBox="0 0 24 24" fill="#E5E7EB" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
									{{endif}}
								{{endfor}}
							</span>
						</div>
						<div class="rating-total-count">{$data['stats']['total']} reviews</div>
						<div class="rating-label" style="{expression="'color: ' . ( $data['stats']['rating_color'] ?? '#16A34A' )"}">{$data['stats']['rating_label']}</div>
					</div>
					<div class="rating-bars">
						{{foreach array( '5', '4', '3', '2', '1' ) as $stars}}
						<div class="rating-bar-row">
							<span class="rating-bar-label">{$stars} {expression="$stars === '1' ? 'star' : 'stars'"}</span>
							<div class="rating-bar-track"><div class="rating-bar-fill" style="{expression="'width: ' . ( (int) $data['stats']['total'] > 0 ? (int) round( ( (int) ( $data['star_counts'][ $stars ] ?? 0 ) / (int) $data['stats']['total'] ) * 100 ) : 0 ) . '%'"}"></div></div>
							<span class="rating-bar-count">{expression="(int) ( $data['star_counts'][ $stars ] ?? 0 )"}</span>
						</div>
						{{endforeach}}
					</div>
				</div>
				<div class="rating-dimensions">
					<div class="rating-dim">
						<div class="rating-dim-label">Pricing</div>
						<div class="rating-dim-value" style="{expression="'color: ' . ( $data['stats']['color_pricing'] ?? '#16A34A' )"}">{$data['stats']['avg_pricing']}</div>
					</div>
					<div class="rating-dim">
						<div class="rating-dim-label">Shipping</div>
						<div class="rating-dim-value" style="{expression="'color: ' . ( $data['stats']['color_shipping'] ?? '#16A34A' )"}">{$data['stats']['avg_shipping']}</div>
					</div>
					<div class="rating-dim">
						<div class="rating-dim-label">Service</div>
						<div class="rating-dim-value" style="{expression="'color: ' . ( $data['stats']['color_service'] ?? '#16A34A' )"}">{$data['stats']['avg_service']}</div>
					</div>
				</div>
				{{else}}
				<div class="empty">
					<div class="empty-title">No reviews yet</div>
					<div class="empty-sub">Be the first to share your experience with this dealer.</div>
				</div>
				{{endif}}
			</div>

			{{if $data['stats']['total'] > 0}}
			<div class="filter-row">
				<div class="filter-left">
					<a class="{expression="'filter-chip' . ( $data['star_key'] === 'all' ? ' active' : '' )"}" href="{$data['star_options']['all']}">
						All <span class="filter-chip-count">{expression="(int) ( $data['star_counts']['all'] ?? 0 )"}</span>
					</a>
					{{foreach array( '5', '4', '3', '2', '1' ) as $stars}}
					<a class="{expression="'filter-chip' . ( $data['star_key'] === $stars ? ' active' : '' )"}" href="{$data['star_options'][ $stars ]}">
						{$stars}★ <span class="filter-chip-count">{expression="(int) ( $data['star_counts'][ $stars ] ?? 0 )"}</span>
					</a>
					{{endforeach}}
				</div>
				<div class="filter-right">
					<select class="select-sm" onchange="if(this.value){window.location=this.value;}">
						<option value="{$data['sort_options']['newest']}"{{if $data['sort_key'] === 'newest'}} selected{{endif}}>Newest first</option>
						<option value="{$data['sort_options']['oldest']}"{{if $data['sort_key'] === 'oldest'}} selected{{endif}}>Oldest first</option>
						<option value="{$data['sort_options']['highest']}"{{if $data['sort_key'] === 'highest'}} selected{{endif}}>Highest rated</option>
						<option value="{$data['sort_options']['lowest']}"{{if $data['sort_key'] === 'lowest'}} selected{{endif}}>Lowest rated</option>
					</select>
				</div>
			</div>
			{{endif}}

			{{if \count( $data['reviews'] )}}
				{{foreach $data['reviews'] as $review}}
				<div class="review" id="{expression="'review-' . (int) $review['id']"}">
					<div class="review-head">
						<div class="review-reviewer">
							<div class="review-avatar">
								{{if $review['reviewer_avatar']}}
									<img src="{$review['reviewer_avatar']}" alt="{$review['reviewer_name']}">
								{{else}}
									{$review['customer_initials']}
								{{endif}}
							</div>
							<div>
								<div class="review-name">{$review['reviewer_name']}{{if $review['verified_buyer']}}<span class="verified-tag">✓ Verified buyer</span>{{endif}}</div>
								<div class="review-date">{$review['created_at_formatted']}</div>
							</div>
						</div>
						<div class="review-score">
							<span class="review-score-num" style="{expression="'color: ' . ( $review['avg_color'] ?? '#16A34A' )"}">{$review['avg_score']}</span>
							<span style="color: var(--gd-text-subtle);">/ 5</span>
							{{if $review['dispute_status'] === 'pending_admin'}}
							<span class="review-dispute-badge">Under admin review</span>
							{{elseif $review['dispute_status'] === 'pending_customer'}}
							<span class="review-dispute-badge">Awaiting customer reply</span>
							{{elseif $review['dispute_status'] === 'resolved'}}
							<span class="review-dispute-badge resolved">Dispute resolved</span>
							{{endif}}
						</div>
					</div>
					<div class="review-dimensions">
						<span class="review-dim">
							<span class="review-dim-label">Pricing</span>
							<span class="review-dim-stars">{$review['stars_pricing']}</span>
						</span>
						<span class="review-dim">
							<span class="review-dim-label">Shipping</span>
							<span class="review-dim-stars">{$review['stars_shipping']}</span>
						</span>
						<span class="review-dim">
							<span class="review-dim-label">Service</span>
							<span class="review-dim-stars">{$review['stars_service']}</span>
						</span>
					</div>
					{{if $review['review_body']}}
					<div class="review-body">{$review['review_body']|raw}</div>
					{{endif}}
					{{if $review['dealer_response']}}
					<div class="review-response">
						<div class="review-response-head">
							<span class="review-response-label">Response from {$review['dealer_name']}</span>
							{{if $review['response_at']}}
							<span class="review-response-date">{$review['response_at']}</span>
							{{endif}}
						</div>
						<div class="review-response-body">{$review['dealer_response']|raw}</div>
					</div>
					{{endif}}
					{{if $review['is_own_review'] && ( $review['edit_review_url'] || $review['dispute_respond_url'] )}}
					<div class="review-own-actions">
						{{if $review['edit_review_url']}}
						<a class="btn btn-ghost" href="{$review['edit_review_url']}">Edit review</a>
						{{endif}}
						{{if $review['dispute_respond_url']}}
						<a class="btn btn-ghost" href="{$review['dispute_respond_url']}">Respond to dispute</a>
						{{endif}}
					</div>
					{{endif}}
				</div>
				{{endforeach}}
			{{elseif $data['stats']['total'] > 0}}
				<div class="empty">
					<div class="empty-title">No reviews match this filter</div>
					<div class="empty-sub"><a href="{$data['clear_filters_url']}" style="color: var(--gd-brand);">Clear filters</a></div>
				</div>
			{{endif}}
		</div>
		<div>
			{{if $data['can_rate']}}
			<div class="leave-review" id="gd-leave-review">
				<div class="leave-review-title">Had an experience with {$data['dealer']['dealer_name']}?</div>
				<div class="leave-review-sub">Help other buyers by sharing your experience. Only verified customers can leave reviews.</div>
				<form action="{$data['rate_url']}" method="post">
					<input type="hidden" name="csrfKey" value="{$data['csrf_key']}">
					<div class="review-form-row">
						<label>Pricing</label>
						<span class="star-input">
							{{for $i=5; $i>=1; $i--}}
								<input type="radio" name="rating_pricing" id="{expression="'rp' . $i"}" value="{$i}"{{if $i === 5}} checked{{endif}}>
								<label for="{expression="'rp' . $i"}">★</label>
							{{endfor}}
						</span>
					</div>
					<div class="review-form-row">
						<label>Shipping</label>
						<span class="star-input">
							{{for $i=5; $i>=1; $i--}}
								<input type="radio" name="rating_shipping" id="{expression="'rs' . $i"}" value="{$i}"{{if $i === 5}} checked{{endif}}>
								<label for="{expression="'rs' . $i"}">★</label>
							{{endfor}}
						</span>
					</div>
					<div class="review-form-row">
						<label>Service</label>
						<span class="star-input">
							{{for $i=5; $i>=1; $i--}}
								<input type="radio" name="rating_service" id="{expression="'rsv' . $i"}" value="{$i}"{{if $i === 5}} checked{{endif}}>
								<label for="{expression="'rsv' . $i"}">★</label>
							{{endfor}}
						</span>
					</div>
					<div style="margin-top: 12px;">
						{$data['review_body_editor_html']|raw}
					</div>
					<button type="submit" class="btn btn-primary leave-review-btn" style="margin-top: 12px;">Submit review</button>
				</form>
				<div style="margin-top: 10px; text-align: center;">
					<a class="guidelines-link" href="{$data['guidelines_url']}">Read review guidelines</a>
				</div>
			</div>
			{{elseif $data['already_rated']}}
			<div class="leave-review">
				<div class="leave-review-title">You've already reviewed this dealer</div>
				<div class="leave-review-sub">Thanks for your feedback. Each customer can leave one review per dealer.</div>
			</div>
			{{elseif $data['login_required']}}
			<div class="leave-review">
				<div class="leave-review-title">Had an experience with {$data['dealer']['dealer_name']}?</div>
				<div class="leave-review-sub">Sign in to leave a review. Only verified customers can rate dealers.</div>
				<a href="{$data['login_url']}" class="btn btn-primary leave-review-btn">Sign in</a>
			</div>
			{{endif}}

			{{if $data['customer_dispute']}}
			<div class="sidebar-card" style="background: var(--gd-warn-bg); border-color: #FDE68A;">
				<div class="sidebar-title" style="color: var(--gd-warn);">A dealer disputed your review</div>
				<p style="font-size: 12px; color: var(--gd-warn); line-height: 1.5; margin-bottom: 10px;">The dealer has contested your review. Please respond{{if $data['customer_dispute']['deadline_formatted']}} by {$data['customer_dispute']['deadline_formatted']}{{endif}}.</p>
				<a href="{$data['customer_dispute']['respond_url']}" class="btn btn-primary leave-review-btn">Respond now</a>
			</div>
			{{endif}}

			<div class="sidebar-card">
				<div class="sidebar-title">Dealer details</div>
				{{if $data['dealer']['contact_email']}}
				<div class="info-row">
					<span class="info-label">
						<span class="info-label-icon"><svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
						Email
					</span>
					<span class="info-value"><a href="{expression="'mailto:' . $data['dealer']['contact_email']"}" style="color: var(--gd-brand);">Contact dealer</a></span>
				</div>
				{{endif}}
				{{if $data['dealer']['response_window']}}
				<div class="info-row">
					<span class="info-label">
						<span class="info-label-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
						Response time
					</span>
					<span class="info-value">Usually within {$data['dealer']['response_window']}</span>
				</div>
				{{endif}}
				{{if $data['dealer']['public_phone']}}
				<div class="info-row">
					<span class="info-label">
						<span class="info-label-icon"><svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
						Phone
					</span>
					<span class="info-value">{$data['dealer']['public_phone']}</span>
				</div>
				{{endif}}
				<div class="info-row">
					<span class="info-label">
						<span class="info-label-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
						Listings
					</span>
					<span class="info-value">{expression="number_format( (int) $data['dealer']['active_listings'] )"} active</span>
				</div>
				{{if $data['dealer']['address_city_state']}}
				<div class="info-row">
					<span class="info-label">
						<span class="info-label-icon"><svg viewBox="0 0 24 24"><path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"/><circle cx="12" cy="10" r="3"/></svg></span>
						Location
					</span>
					<span class="info-value">{{if $data['dealer']['address_public'] && $data['dealer']['address_line']}}{$data['dealer']['address_line']}{{else}}{$data['dealer']['address_city_state']}{{endif}}</span>
				</div>
				{{endif}}
				{{if $data['dealer']['member_since']}}
				<div class="info-row">
					<span class="info-label">
						<span class="info-label-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
						Member since
					</span>
					<span class="info-value">{$data['dealer']['member_since']}</span>
				</div>
				{{endif}}
				{{if $data['dealer']['has_hours']}}
				<div class="info-row" style="align-items: flex-start;">
					<span class="info-label">
						<span class="info-label-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
						Hours
					</span>
					<span class="info-value" style="font-size: 12px; line-height: 1.6;">
						{{if is_array( $data['dealer']['hours'] )}}
							{{foreach $data['dealer']['hours'] as $hr}}
								<div{{if $hr['is_today']}} style="font-weight: 600;"{{endif}}>
									{$hr['label']}: {{if $hr['closed']}}Closed{{elseif $hr['open'] && $hr['close']}}{$hr['open']} – {$hr['close']}{{else}}—{{endif}}
								</div>
							{{endforeach}}
						{{endif}}
					</span>
				</div>
				{{endif}}
			</div>

			{{if $data['dealer']['about']}}
			<div class="sidebar-card">
				<div class="sidebar-title">About this dealer</div>
				<input type="checkbox" id="gd-about-toggle" style="display: none;">
				<div class="about-body" id="gd-about-body" style="max-height: 120px; overflow: hidden; position: relative;">{$data['dealer']['about']|raw}</div>
				<label for="gd-about-toggle" id="gd-about-label" style="font-size: 12px; color: var(--gd-brand); font-weight: 500; cursor: pointer; display: inline-block; margin-top: 8px;">Read more →</label>
				<style>#gd-about-toggle:checked ~ #gd-about-body { max-height: none; } #gd-about-toggle:checked ~ #gd-about-label { display: none; }</style>
			</div>
			{{endif}}

			{{if is_array( $data['dealer']['socials'] ) && \count( $data['dealer']['socials'] )}}
			<div class="sidebar-card">
				<div class="sidebar-title">Connect</div>
				<div class="socials-row">
					{{foreach $data['dealer']['socials'] as $social}}
					<a class="social-btn" href="{$social['url']}" target="_blank" rel="nofollow noopener" title="{$social['network']}" aria-label="{$social['network']}">
						{{if $social['network'] === 'facebook'}}
							<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.198 21.5h4v-8.01h3.604l.396-3.98h-4V7.5a1 1 0 0 1 1-1h3v-4h-3a5 5 0 0 0-5 5v2.51h-2l-.396 3.98h2.396v8.01z"/></svg>
						{{elseif $social['network'] === 'instagram'}}
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
						{{elseif $social['network'] === 'youtube'}}
							<svg viewBox="0 0 24 24" fill="currentColor"><path d="M23 9.71a8.5 8.5 0 0 0-.91-4.13 2.92 2.92 0 0 0-1.72-1A78.36 78.36 0 0 0 12 4.27a78.45 78.45 0 0 0-8.34.3 2.87 2.87 0 0 0-1.46.74c-.9.83-1 2.25-1.1 3.45a48.29 48.29 0 0 0 0 6.48 9.55 9.55 0 0 0 .3 2 3.14 3.14 0 0 0 .71 1.36 2.86 2.86 0 0 0 1.49.78 45.18 45.18 0 0 0 6.5.33c3.5.05 6.57 0 10.2-.28a2.88 2.88 0 0 0 1.53-.78 2.49 2.49 0 0 0 .61-1 10.58 10.58 0 0 0 .52-3.4c.04-.56.04-3.94.04-4.54zM9.74 14.85V8.66l5.92 3.11c-1.66.92-3.85 1.96-5.92 3.08z"/></svg>
						{{elseif $social['network'] === 'twitter'}}
							<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
						{{elseif $social['network'] === 'tiktok'}}
							<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5.8 20.1a6.34 6.34 0 0 0 10.86-4.43V8.66a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1.84-.09z"/></svg>
						{{else}}
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
						{{endif}}
					</a>
					{{endforeach}}
				</div>
			</div>
			{{endif}}

			{{if $data['dealer']['shipping_policy'] || $data['dealer']['return_policy']}}
			<div class="sidebar-card">
				<div class="sidebar-title">Policies</div>
				{{if $data['dealer']['shipping_policy']}}
				<div style="margin-bottom: 12px;">
					<div style="font-size: 12px; color: var(--gd-text-subtle); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; margin-bottom: 4px;">Shipping</div>
					<div class="about-body">{$data['dealer']['shipping_policy']|raw}</div>
				</div>
				{{endif}}
				{{if $data['dealer']['return_policy']}}
				<div>
					<div style="font-size: 12px; color: var(--gd-text-subtle); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; margin-bottom: 4px;">Returns</div>
					<div class="about-body">{$data['dealer']['return_policy']|raw}</div>
				</div>
				{{endif}}
			</div>
			{{endif}}

			<div class="sidebar-card" style="background: var(--gd-warn-bg); border-color: #FDE68A;">
				<div class="sidebar-title" style="color: var(--gd-warn);">Review policy</div>
				<p style="font-size: 12px; color: var(--gd-warn); line-height: 1.5; margin-bottom: 10px;">Reviews are verified against real transactions. Dealers can contest reviews that violate our guidelines. All contested reviews are reviewed by our team.</p>
				<a class="guidelines-link" href="{$data['guidelines_url']}" style="color: var(--gd-warn);">Full review guidelines</a>
			</div>
		</div>
	</div>

</div>
TEMPLATE_EOT,
	],


	/* ===== FRONT: editReview ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'editReview',
		'template_data' => '$review, $editUrl, $cancelUrl, $csrfKey',
		'template_content' => <<<'TEMPLATE_EOT'
<div class="gdDealerWrapper" style="width:100%;max-width:720px;margin:0 auto;padding:32px 16px;box-sizing:border-box">
	<div style="background:#ffffff;border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden">

		<div style="padding:18px 24px;border-bottom:0.5px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;background:#fafafa">
			<div>
				<h2 style="margin:0;font-size:18px;font-weight:500;color:#111827">Edit your review</h2>
				{{if $review['dealer_name']}}
				<p style="margin:2px 0 0;font-size:13px;color:#6b7280">for {$review['dealer_name']}</p>
				{{endif}}
			</div>
			<a href="{$cancelUrl}" style="font-size:13px;color:#6b7280;text-decoration:none">Cancel</a>
		</div>

		<form method="post" action="{$editUrl}" style="padding:24px">
			<input type="hidden" name="csrfKey" value="{$csrfKey}">

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:20px">
				<div>
					<label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em">Pricing accuracy</label>
					<select name="rating_pricing" required style="width:100%;padding:10px 12px;font-size:14px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#111827">
						<option value="5" {{if $review['rating_pricing'] === 5}}selected{{endif}}>★★★★★ Excellent</option>
						<option value="4" {{if $review['rating_pricing'] === 4}}selected{{endif}}>★★★★☆ Good</option>
						<option value="3" {{if $review['rating_pricing'] === 3}}selected{{endif}}>★★★☆☆ Average</option>
						<option value="2" {{if $review['rating_pricing'] === 2}}selected{{endif}}>★★☆☆☆ Poor</option>
						<option value="1" {{if $review['rating_pricing'] === 1}}selected{{endif}}>★☆☆☆☆ Terrible</option>
					</select>
				</div>
				<div>
					<label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em">Shipping speed</label>
					<select name="rating_shipping" required style="width:100%;padding:10px 12px;font-size:14px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#111827">
						<option value="5" {{if $review['rating_shipping'] === 5}}selected{{endif}}>★★★★★ Excellent</option>
						<option value="4" {{if $review['rating_shipping'] === 4}}selected{{endif}}>★★★★☆ Good</option>
						<option value="3" {{if $review['rating_shipping'] === 3}}selected{{endif}}>★★★☆☆ Average</option>
						<option value="2" {{if $review['rating_shipping'] === 2}}selected{{endif}}>★★☆☆☆ Poor</option>
						<option value="1" {{if $review['rating_shipping'] === 1}}selected{{endif}}>★☆☆☆☆ Terrible</option>
					</select>
				</div>
				<div>
					<label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em">Customer service</label>
					<select name="rating_service" required style="width:100%;padding:10px 12px;font-size:14px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#111827">
						<option value="5" {{if $review['rating_service'] === 5}}selected{{endif}}>★★★★★ Excellent</option>
						<option value="4" {{if $review['rating_service'] === 4}}selected{{endif}}>★★★★☆ Good</option>
						<option value="3" {{if $review['rating_service'] === 3}}selected{{endif}}>★★★☆☆ Average</option>
						<option value="2" {{if $review['rating_service'] === 2}}selected{{endif}}>★★☆☆☆ Poor</option>
						<option value="1" {{if $review['rating_service'] === 1}}selected{{endif}}>★☆☆☆☆ Terrible</option>
					</select>
				</div>
			</div>

			<div style="margin-bottom:24px">
				<label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em">Your review</label>
				{$review['body_editor_html']|raw}
				<p style="margin:6px 0 0;font-size:12px;color:#9ca3af">Be honest and constructive. Dealers may respond publicly.</p>
			</div>

			<div style="display:flex;gap:10px;justify-content:flex-end;border-top:0.5px solid #e5e7eb;padding-top:18px;margin-top:8px">
				<a href="{$cancelUrl}" style="display:inline-flex;align-items:center;padding:10px 18px;border-radius:8px;border:1px solid #d1d5db;color:#374151;text-decoration:none;font-size:14px;font-weight:500;background:#fff">Cancel</a>
				<button type="submit" style="display:inline-flex;align-items:center;padding:10px 20px;border-radius:8px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-size:14px;font-weight:500;cursor:pointer">Save changes</button>
			</div>
		</form>

	</div>
</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: reviewGuidelines ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'reviewGuidelines',
		'template_data' => '$content, $contactEmail',
		'template_content' => <<<'TEMPLATE_EOT'
<div style="max-width:900px;margin:0 auto;padding:0 16px">

	<div style="text-align:center;padding:32px 0 24px">
		<h1 style="font-size:1.8em;font-weight:800;margin:0 0 8px">Review &amp; Dispute Guidelines</h1>
		<p style="color:#666;margin:0">Everything you need to know about leaving reviews and how disputes work.</p>
	</div>

	<div style="display:flex;gap:12px;margin-bottom:32px;flex-wrap:wrap;justify-content:center">
		<a href="#buyers" class="ipsButton ipsButton--normal ipsButton--small">For Buyers</a>
		<a href="#disputes" class="ipsButton ipsButton--normal ipsButton--small">Dispute Process</a>
		<a href="#dealers" class="ipsButton ipsButton--normal ipsButton--small">For Dealers</a>
	</div>

	<div id="buyers" class="ipsBox i-margin-bottom_block" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:20px">
		<h3 class="ipsBox__header" style="margin:0;padding:16px 24px;border-bottom:1px solid var(--i-border-color,#f0f0f0);font-size:1.1em;font-weight:700;display:flex;align-items:center;gap:10px">
			<span style="background:#eff6ff;color:#2563eb;width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:0.9em">&#9733;</span>
			{$content['buyer_title']}
		</h3>
		<div class="i-padding_2" style="padding:24px;color:#444;line-height:1.7;white-space:pre-line">{$content['buyer_body']}</div>
	</div>

	<div id="disputes" class="ipsBox i-margin-bottom_block" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:20px">
		<h3 class="ipsBox__header" style="margin:0;padding:16px 24px;border-bottom:1px solid var(--i-border-color,#f0f0f0);font-size:1.1em;font-weight:700;display:flex;align-items:center;gap:10px">
			<span style="background:#fff8f0;color:#f59e0b;width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:0.9em">&#9878;</span>
			{$content['dispute_title']}
		</h3>
		<div class="i-padding_2" style="padding:24px;color:#444;line-height:1.7;white-space:pre-line">{$content['dispute_body']}</div>
	</div>

	<div id="dealers" class="ipsBox i-margin-bottom_block" style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:32px">
		<h3 class="ipsBox__header" style="margin:0;padding:16px 24px;border-bottom:1px solid var(--i-border-color,#f0f0f0);font-size:1.1em;font-weight:700;display:flex;align-items:center;gap:10px">
			<span style="background:#f0fdf4;color:#16a34a;width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:0.9em">&#127978;</span>
			{$content['dealer_title']}
		</h3>
		<div class="i-padding_2" style="padding:24px;color:#444;line-height:1.7;white-space:pre-line">{$content['dealer_body']}</div>
	</div>

	<div style="text-align:center;padding:16px;color:#999;font-size:0.85em">
		Questions? Contact us at <a href="mailto:{$contactEmail}" style="color:#2563eb">{$contactEmail}</a>
	</div>

</div>
TEMPLATE_EOT,
	],

	/* ===== FRONT: dealerDirectory ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'dealerDirectory',
		'template_data' => '$dealers, $total, $page, $perPage, $pagination, $tier, $sort, $search, $loggedIn, $joinUrl, $directoryUrl',
		'template_content' => <<<'TEMPLATE_EOT'
<div style="max-width:1400px;margin:0 auto;padding:0 24px;box-sizing:border-box">

	<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:24px;padding-bottom:16px;border-bottom:1px solid var(--i-border-color,#e0e0e0)">
		<div>
			<h1 style="margin:0 0 4px;font-size:1.6em;font-weight:800">{lang="gddealer_directory_title"}</h1>
			<p style="margin:0;color:#666;font-size:0.9em">{$total} active dealers on GunRack.deals</p>
		</div>
		<a href="{$joinUrl}" class="ipsButton ipsButton--primary">
			<i class="fa-solid fa-store" aria-hidden="true"></i>
			<span>Become a Dealer</span>
		</a>
	</div>

	<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:16px;margin-bottom:24px">
		<form method="get" action="{$directoryUrl}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
			<div style="flex:1 1 200px">
				<label style="display:block;font-size:0.8em;font-weight:600;color:#666;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em">Search</label>
				<input type="text" name="search" value="{$search}" placeholder="Search dealers..." class="ipsInput ipsInput--text" style="width:100%;box-sizing:border-box">
			</div>
			<div style="flex:0 1 160px">
				<label style="display:block;font-size:0.8em;font-weight:600;color:#666;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em">Tier</label>
				<select name="tier" class="ipsInput ipsInput--select" style="width:100%">
					<option value="">All Tiers</option>
					<option value="founding" {{if $tier === 'founding'}}selected{{endif}}>Founding</option>
					<option value="enterprise" {{if $tier === 'enterprise'}}selected{{endif}}>Enterprise</option>
					<option value="pro" {{if $tier === 'pro'}}selected{{endif}}>Pro</option>
					<option value="basic" {{if $tier === 'basic'}}selected{{endif}}>Basic</option>
				</select>
			</div>
			<div style="flex:0 1 160px">
				<label style="display:block;font-size:0.8em;font-weight:600;color:#666;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em">Sort By</label>
				<select name="sort" class="ipsInput ipsInput--select" style="width:100%">
					<option value="rating" {{if $sort === 'rating'}}selected{{endif}}>Highest Rated</option>
					<option value="listings" {{if $sort === 'listings'}}selected{{endif}}>Most Listings</option>
					<option value="newest" {{if $sort === 'newest'}}selected{{endif}}>Newest</option>
					<option value="alpha" {{if $sort === 'alpha'}}selected{{endif}}>A&ndash;Z</option>
				</select>
			</div>
			<div>
				<button type="submit" class="ipsButton ipsButton--primary">
					<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
					<span>Filter</span>
				</button>
				{{if $search || $tier}}
				<a href="{$directoryUrl}" class="ipsButton ipsButton--normal" style="margin-left:8px">Clear</a>
				{{endif}}
			</div>
		</form>
	</div>

	{{if count($dealers) === 0}}
	<div style="text-align:center;padding:64px 24px;color:#9ca3af">
		<i class="fa-solid fa-store-slash" style="font-size:3em;margin-bottom:16px;display:block" aria-hidden="true"></i>
		<h3 style="margin:0 0 8px;color:#374151">No dealers found</h3>
		<p style="margin:0">Try adjusting your filters or search terms.</p>
	</div>
	{{else}}
	<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-bottom:32px">
		{{foreach $dealers as $d}}
		<div style="background:#fff;border:1px solid var(--i-border-color,#e0e0e0);border-radius:12px;overflow:hidden;display:flex;flex-direction:column;transition:box-shadow 0.2s">

			<div style="padding:20px;display:flex;align-items:center;gap:14px;border-bottom:1px solid var(--i-border-color,#f0f0f0)">
				<a href="{$d['profile_url']}" style="flex-shrink:0">
					<span class="ipsUserPhoto ipsUserPhoto--medium">
						<img src="{$d['avatar']}" alt="" loading="lazy">
					</span>
				</a>
				<div style="flex:1;min-width:0">
					<a href="{$d['profile_url']}" style="text-decoration:none;color:inherit">
						<h3 style="margin:0 0 4px;font-size:1em;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{$d['dealer_name']}</h3>
					</a>
					<span style="background:{$d['tier_color']};color:#fff;padding:2px 8px;border-radius:20px;font-size:0.72em;font-weight:700;text-transform:uppercase;letter-spacing:0.04em">{$d['tier_label']}</span>
				</div>
				<div style="text-align:center;flex-shrink:0">
					<div style="font-size:1.4em;font-weight:800;color:{$d['rating_color']};line-height:1">{$d['avg_overall']}</div>
					<div style="font-size:0.7em;color:#9ca3af">/ 5</div>
				</div>
			</div>

			<div style="display:flex;padding:12px 20px;gap:0;border-bottom:1px solid var(--i-border-color,#f0f0f0)">
				<div style="flex:1;text-align:center">
					<div style="font-size:1.1em;font-weight:700">{$d['listing_count']}</div>
					<div style="font-size:0.72em;color:#9ca3af;text-transform:uppercase;letter-spacing:0.04em">Listings</div>
				</div>
				<div style="flex:1;text-align:center;border-left:1px solid var(--i-border-color,#f0f0f0)">
					<div style="font-size:1.1em;font-weight:700">{$d['total_reviews']}</div>
					<div style="font-size:0.72em;color:#9ca3af;text-transform:uppercase;letter-spacing:0.04em">Reviews</div>
				</div>
				<div style="flex:1;text-align:center;border-left:1px solid var(--i-border-color,#f0f0f0)">
					<div style="font-size:0.85em;font-weight:600">{$d['member_since']}</div>
					<div style="font-size:0.72em;color:#9ca3af;text-transform:uppercase;letter-spacing:0.04em">Member Since</div>
				</div>
			</div>

			<div style="padding:12px 16px;display:flex;gap:8px;margin-top:auto">
				<a href="{$d['profile_url']}" class="ipsButton ipsButton--primary ipsButton--small" style="flex:1;text-align:center;justify-content:center">
					<i class="fa-solid fa-store" aria-hidden="true"></i>
					<span>View Profile</span>
				</a>
				{{if $loggedIn}}
				<a href="{$d['follow_url']}" class="ipsButton ipsButton--small {{if $d['is_following']}}ipsButton--primary{{else}}ipsButton--normal{{endif}}" title="{{if $d['is_following']}}Unfollow{{else}}Follow{{endif}} this dealer">
					<i class="fa-solid {{if $d['is_following']}}fa-bell-slash{{else}}fa-bell{{endif}}" aria-hidden="true"></i>
				</a>
				{{endif}}
			</div>
		</div>
		{{endforeach}}
	</div>

	{$pagination|raw}
	{{endif}}

</div>
TEMPLATE_EOT,
	],
/* ===== ADMIN: supportTickets ===== */
	[
	'set_id'        => 1,
	'app'           => 'gddealer',
	'location'      => 'admin',
	'group'         => 'dealers',
	'template_name' => 'supportTickets',
	'template_data' => '$rows, $status_filter, $priority_filter, $department_filter, $counts, $status_options, $priority_options, $department_options, $departments',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
<div class="ipsBox_body ipsPad">
<h2 style="margin:0 0 16px;font-size:1.4em;font-weight:700">Support Tickets</h2>
<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--i-border-color,#e0e0e0)">
	<div>
		<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Status</label>
		<select onchange="window.location.href=this.value" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:0.85em;background:#fff">
			<option value="{$status_options['active']}" {expression="$status_filter === 'active' ? 'selected' : ''"}>Active ({expression="number_format($counts['active'])"})</option>
			<option value="{$status_options['open']}" {expression="$status_filter === 'open' ? 'selected' : ''"}>Open ({expression="number_format($counts['open'])"})</option>
			<option value="{$status_options['pending_staff']}" {expression="$status_filter === 'pending_staff' ? 'selected' : ''"}>Awaiting Staff ({expression="number_format($counts['pending_staff'])"})</option>
			<option value="{$status_options['pending_customer']}" {expression="$status_filter === 'pending_customer' ? 'selected' : ''"}>Awaiting Customer ({expression="number_format($counts['pending_customer'])"})</option>
			<option value="{$status_options['resolved']}" {expression="$status_filter === 'resolved' ? 'selected' : ''"}>Resolved ({expression="number_format($counts['resolved'])"})</option>
			<option value="{$status_options['closed']}" {expression="$status_filter === 'closed' ? 'selected' : ''"}>Closed ({expression="number_format($counts['closed'])"})</option>
			<option value="{$status_options['all']}" {expression="$status_filter === 'all' ? 'selected' : ''"}>All ({expression="number_format($counts['all'])"})</option>
		</select>
	</div>
	<div>
		<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Priority</label>
		<select onchange="window.location.href=this.value" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:0.85em;background:#fff">
			<option value="{$priority_options['all']}" {expression="$priority_filter === 'all' ? 'selected' : ''"}>All priorities</option>
			<option value="{$priority_options['urgent']}" {expression="$priority_filter === 'urgent' ? 'selected' : ''"}>Urgent</option>
			<option value="{$priority_options['high']}" {expression="$priority_filter === 'high' ? 'selected' : ''"}>High</option>
			<option value="{$priority_options['normal']}" {expression="$priority_filter === 'normal' ? 'selected' : ''"}>Normal</option>
			<option value="{$priority_options['low']}" {expression="$priority_filter === 'low' ? 'selected' : ''"}>Low</option>
		</select>
	</div>
	<div>
		<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Department</label>
		<select onchange="window.location.href=this.value" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:0.85em;background:#fff">
			{{foreach $departments as $did => $dname}}
			<option value="{$department_options[$did]}" {expression="$department_filter === $did ? 'selected' : ''"}>{{if $did === 0}}All departments{{else}}{$dname}{{endif}}</option>
			{{endforeach}}
		</select>
	</div>
</div>
{{if count($rows) > 0}}
<table class="ipsTable ipsTable--responsive" style="width:100%">
	<thead>
		<tr style="background:#f8fafc">
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Ticket</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Dealer</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Dept</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Priority</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Status</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Assignee</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Updated</th>
		</tr>
	</thead>
	<tbody>
		{{foreach $rows as $r}}
		<tr style="border-bottom:1px solid #f0f0f0;{{if $r['needs_attention']}}background:#fffbeb;{{endif}}{{if $r['is_enterprise']}}border-left:3px solid #d97706;{{endif}}">
			<td style="padding:10px 12px">
				{{if $r['needs_attention']}}<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#dc2626;margin-right:6px;vertical-align:middle"></span>{{endif}}
				<a href="{$r['view_url']}" style="font-weight:600;color:#1d4ed8;text-decoration:none">{$r['subject']}</a>
				<div style="font-size:0.78em;color:#6b7280;margin-top:2px">#{$r['id']} &middot; {$r['submitter_name']}</div>
			</td>
			<td style="padding:10px 12px;font-size:0.85em">
				{$r['dealer_name']}
				{{if $r['is_enterprise']}}<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:10px;font-size:0.75em;font-weight:600;margin-left:4px">Enterprise</span>{{endif}}
			</td>
			<td style="padding:10px 12px;font-size:0.85em;color:#6b7280">{$r['department_name']}</td>
			<td style="padding:10px 12px"><span style="background:{$r['priority_bg']};color:{$r['priority_color']};padding:2px 8px;border-radius:12px;font-size:0.78em;font-weight:600">{$r['priority_label']}</span></td>
			<td style="padding:10px 12px"><span style="background:{$r['status_bg']};color:{$r['status_color']};padding:2px 8px;border-radius:12px;font-size:0.78em;font-weight:600;white-space:nowrap">{$r['status_label']}</span></td>
			<td style="padding:10px 12px;font-size:0.85em;color:#6b7280">{{if $r['assignee_name']}}{$r['assignee_name']}{{else}}<span style="color:#d1d5db">&mdash;</span>{{endif}}</td>
			<td style="padding:10px 12px;font-size:0.8em;color:#6b7280;white-space:nowrap">
				{$r['updated_at_short']}
				{{if $r['last_reply_role']}}
				<span style="margin-left:4px">{{if $r['last_reply_role'] === 'admin'}}<i class="fa-solid fa-headset" title="Staff replied" style="color:#2563eb"></i>{{else}}<i class="fa-solid fa-store" title="Dealer replied" style="color:#6b7280"></i>{{endif}}</span>
				{{endif}}
			</td>
		</tr>
		{{endforeach}}
	</tbody>
</table>
{{else}}
<div style="text-align:center;padding:40px;color:#6b7280">
	<p>No tickets match the current filters.</p>
</div>
{{endif}}
</div>
</div>
TEMPLATE_EOT,
	],

/* ===== ADMIN: supportTicketView ===== */
	[
	'set_id'        => 1,
	'app'           => 'gddealer',
	'location'      => 'admin',
	'group'         => 'dealers',
	'template_name' => 'supportTicketView',
	'template_data' => '$ticket, $ticket_body, $ticket_attachments, $replies, $reply_editor_html, $reply_url, $update_status_url, $update_priority_url, $assign_url, $delete_url, $back_url, $events, $note_editor_html, $add_note_url, $stock_replies, $stock_actions',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
<div class="ipsBox_body ipsPad">
<div style="margin-bottom:16px"><a href="{$back_url}" style="color:#2563eb;text-decoration:none;font-size:0.9em">&larr; Back to tickets</a></div>
<div style="display:flex;gap:24px;flex-wrap:wrap">
<div style="flex:1;min-width:400px">
	<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:20px">
		<div style="padding:16px 20px;border-bottom:1px solid #f0f0f0">
			<h2 style="margin:0 0 8px;font-size:1.2em;font-weight:700">{$ticket['subject']}</h2>
			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:0.85em;color:#6b7280">
				<span style="background:{$ticket['status_bg']};color:{$ticket['status_color']};padding:2px 10px;border-radius:12px;font-weight:600;font-size:0.85em">{$ticket['status_label']}</span>
				<span style="background:{$ticket['priority_bg']};color:{$ticket['priority_color']};padding:2px 10px;border-radius:12px;font-weight:600;font-size:0.85em">{$ticket['priority_label']}</span>
				<span>#{$ticket['id']}</span>
				<span>&middot; {$ticket['created_at']}</span>
			</div>
		</div>
		<div style="padding:16px 20px">
			{$ticket_body|raw}
			{{if count($ticket_attachments) > 0}}
			<div style="margin-top:12px;padding-top:12px;border-top:1px solid #f0f0f0">
				<div style="font-size:0.8em;font-weight:600;color:#475569;margin-bottom:6px">Attachments</div>
				{{foreach $ticket_attachments as $att}}
				<div style="margin-bottom:4px"><a href="{$att['url']}" target="_blank" style="font-size:0.85em;color:#2563eb">{$att['filename']}</a></div>
				{{endforeach}}
			</div>
			{{endif}}
		</div>
	</div>
	{{if count($replies) > 0}}
	<h3 id="replies" style="font-size:1em;font-weight:700;margin:0 0 12px">Replies</h3>
	{{foreach $replies as $r}}
	{{if $r['is_hidden_note']}}
	<div style="background:#fffbeb;border:1px solid #fcd34d;border-left:3px solid #f59e0b;border-radius:8px;margin-bottom:10px">
		<div style="padding:8px 14px;background:#fef3c7;border-bottom:1px solid #fde68a;display:flex;align-items:center;gap:8px;font-size:0.85em">
			<span style="background:{$r['role_bg']};color:{$r['role_color']};padding:1px 8px;border-radius:10px;font-weight:600;font-size:0.8em"><i class="fa-solid fa-lock" aria-hidden="true"></i> {$r['role_label']}</span>
			<span style="font-weight:600">{$r['author_name']}</span>
			<span style="color:#6b7280">&middot; {$r['created_at']}</span>
		</div>
		<div style="padding:12px 14px">{$r['body']|raw}</div>
	</div>
	{{else}}
	<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:10px;border-left:3px solid {$r['role_color']}">
		<div style="padding:8px 14px;background:#f8fafc;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:8px;font-size:0.85em">
			<span style="background:{$r['role_bg']};color:{$r['role_color']};padding:1px 8px;border-radius:10px;font-weight:600;font-size:0.8em">{$r['role_label']}</span>
			<span style="font-weight:600">{$r['author_name']}</span>
			<span style="color:#6b7280">&middot; {$r['created_at']}</span>
		</div>
		<div style="padding:12px 14px">{$r['body']|raw}</div>
	</div>
	{{endif}}
	{{endforeach}}
	{{endif}}
	<div style="margin-top:20px">
		{{if $ticket['status'] === 'closed'}}
		<div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:0.88em;color:#92400e"><strong>This ticket is closed.</strong> Replying will reopen it and notify the dealer. Notes don't reopen or notify.</div>
		{{endif}}
		{{if $ticket['status'] === 'resolved'}}
		<div style="background:#eff6ff;border:1px solid #60a5fa;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:0.88em;color:#1e40af"><strong>This ticket is resolved.</strong> Replying will reopen it and notify the dealer. Notes don't reopen or notify.</div>
		{{endif}}
		<div style="display:flex;gap:4px;border-bottom:1px solid #e5e7eb;margin-bottom:-1px">
			<button type="button" id="gd-tab-btn-reply" onclick="document.getElementById('gd-tab-reply').style.display='block';document.getElementById('gd-tab-note').style.display='none';this.style.background='#fff';this.style.borderBottomColor='#fff';var o=document.getElementById('gd-tab-btn-note');o.style.background='transparent';o.style.borderBottomColor='#e5e7eb';" style="padding:10px 18px;background:#fff;border:1px solid #e5e7eb;border-bottom:1px solid #fff;border-radius:8px 8px 0 0;font-size:13px;font-weight:500;color:#111827;cursor:pointer;position:relative;bottom:-1px"><i class="fa-solid fa-reply" aria-hidden="true"></i> Reply to dealer</button>
			<button type="button" id="gd-tab-btn-note" onclick="document.getElementById('gd-tab-reply').style.display='none';document.getElementById('gd-tab-note').style.display='block';this.style.background='#fff';this.style.borderBottomColor='#fff';var o=document.getElementById('gd-tab-btn-reply');o.style.background='transparent';o.style.borderBottomColor='#e5e7eb';" style="padding:10px 18px;background:transparent;border:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;border-radius:8px 8px 0 0;font-size:13px;font-weight:500;color:#854d0e;cursor:pointer;position:relative;bottom:-1px"><i class="fa-solid fa-note-sticky" aria-hidden="true"></i> Internal note</button>
		</div>
		<div id="gd-tab-reply" style="display:block;padding:20px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;background:#fff">
			<p style="font-size:12px;color:#6b7280;margin:0 0 10px">Visible to dealer. Sends email + bell + PM notification.</p>
			{{if count($stock_replies) > 0}}
			<div style="margin-bottom:10px">
				<label style="font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;display:block">Insert stock reply:</label>
				<select id="gd-stock-reply-picker" onchange="if(this.value){var b=this.options[this.selectedIndex].getAttribute('data-body');try{var eds=document.querySelectorAll('[id^=editor_support_admin_reply_]');if(eds.length){var el=eds[0];if(el.ckeditorInstance){el.ckeditorInstance.model.change(function(w){var r=el.ckeditorInstance.model.document.getRoot();w.appendElement('paragraph',r);var vf=el.ckeditorInstance.data.processor.toView(b);var mf=el.ckeditorInstance.data.toModel(vf);w.insert(mf,r,'end');})}else if(typeof CKEDITOR!=='undefined'){for(var k in CKEDITOR.instances){if(k.indexOf('editor_support_admin_reply_')===0){CKEDITOR.instances[k].insertHtml(b);break}}}else{var ta=document.querySelector('textarea[name=gddealer_support_admin_reply]');if(ta)ta.value+=b}}}catch(e){var ta2=document.querySelector('textarea[name=gddealer_support_admin_reply]');if(ta2)ta2.value+=b}this.selectedIndex=0}" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85em;width:100%">
					<option value="">-- Select a stock reply --</option>
					{{foreach $stock_replies as $sr}}
					<option value="{$sr['id']}" data-body="{expression="htmlspecialchars($sr['body'], ENT_QUOTES)"}">{$sr['title']}</option>
					{{endforeach}}
				</select>
			</div>
			{{endif}}
			<form method="post" action="{$reply_url}">
				<div style="margin-bottom:12px">{$reply_editor_html|raw}</div>
				<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Send Reply</button>
			</form>
		</div>
		<div id="gd-tab-note" style="display:none;padding:20px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;background:#fffbeb">
			<p style="font-size:12px;color:#854d0e;margin:0 0 10px"><i class="fa-solid fa-lock" aria-hidden="true"></i> Internal only — dealer never sees this. No notifications sent.</p>
			<form method="post" action="{$add_note_url}">
				<div style="margin-bottom:12px">{$note_editor_html|raw}</div>
				<button type="submit" class="ipsButton ipsButton--small" style="background:#854d0e;color:#fff;border-color:#854d0e">Save Note</button>
			</form>
		</div>
	</div>
</div>
<aside style="flex:0 0 260px;min-width:240px">
	<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:16px">
		<h4 style="margin:0;padding:10px 14px;background:#f8fafc;border-bottom:1px solid #f0f0f0;font-size:0.82em;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569">Details</h4>
		<div style="padding:12px 14px;font-size:0.88em">
			<div style="margin-bottom:10px"><span style="color:#6b7280;display:block;font-size:0.85em;margin-bottom:2px">Submitter</span><strong>{$ticket['submitter_name']}</strong>{{if $ticket['submitter_email']}} <span style="color:#6b7280;font-size:0.85em">({$ticket['submitter_email']})</span>{{endif}}</div>
			<div style="margin-bottom:10px"><span style="color:#6b7280;display:block;font-size:0.85em;margin-bottom:2px">Dealer</span><strong>{$ticket['dealer_name']}</strong>{{if $ticket['dealer_tier']}} <span style="font-size:0.8em;color:#6b7280">({$ticket['dealer_tier']})</span>{{endif}}</div>
			<div style="margin-bottom:10px"><span style="color:#6b7280;display:block;font-size:0.85em;margin-bottom:2px">Department</span>{$ticket['department_name']}</div>
			<div style="margin-bottom:10px"><span style="color:#6b7280;display:block;font-size:0.85em;margin-bottom:2px">Assignee</span>{{if $ticket['assignee_name']}}{$ticket['assignee_name']}{{else}}<span style="color:#d1d5db">Unassigned</span>{{endif}}</div>
		</div>
	</div>
	<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:16px">
		<h4 style="margin:0;padding:10px 14px;background:#f8fafc;border-bottom:1px solid #f0f0f0;font-size:0.82em;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569">Actions</h4>
		<div style="padding:12px 14px">
			<form method="post" action="{$update_status_url}" style="margin-bottom:10px">
				<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Status</label>
				<select name="status" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85em;margin-bottom:6px">
					<option value="open" {expression="$ticket['status'] === 'open' ? 'selected' : ''"}>Open</option>
					<option value="pending_staff" {expression="$ticket['status'] === 'pending_staff' ? 'selected' : ''"}>Awaiting Staff</option>
					<option value="pending_customer" {expression="$ticket['status'] === 'pending_customer' ? 'selected' : ''"}>Awaiting Customer</option>
					<option value="resolved" {expression="$ticket['status'] === 'resolved' ? 'selected' : ''"}>Resolved</option>
					<option value="closed" {expression="$ticket['status'] === 'closed' ? 'selected' : ''"}>Closed</option>
				</select>
				<button type="submit" class="ipsButton ipsButton--inherit ipsButton--verySmall" style="width:100%">Update Status</button>
			</form>
			<form method="post" action="{$update_priority_url}" style="margin-bottom:10px">
				<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Priority</label>
				<select name="priority" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85em;margin-bottom:6px">
					<option value="low" {expression="$ticket['priority'] === 'low' ? 'selected' : ''"}>Low</option>
					<option value="normal" {expression="$ticket['priority'] === 'normal' ? 'selected' : ''"}>Normal</option>
					<option value="high" {expression="$ticket['priority'] === 'high' ? 'selected' : ''"}>High</option>
					<option value="urgent" {expression="$ticket['priority'] === 'urgent' ? 'selected' : ''"}>Urgent</option>
				</select>
				<button type="submit" class="ipsButton ipsButton--inherit ipsButton--verySmall" style="width:100%">Update Priority</button>
			</form>
			<form method="post" action="{$assign_url}" style="margin-bottom:10px">
				<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Assign to (member ID)</label>
				<input type="number" name="assignee" value="{$ticket['assignee_id']}" min="0" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85em;margin-bottom:6px;box-sizing:border-box">
				<button type="submit" class="ipsButton ipsButton--inherit ipsButton--verySmall" style="width:100%">Update Assignee</button>
			</form>
			<hr style="border:none;border-top:1px solid #e0e0e0;margin:12px 0">
			<a href="{$delete_url}" class="ipsButton ipsButton--negative ipsButton--verySmall" style="width:100%;text-align:center" onclick="return confirm('Delete this ticket and all replies? This cannot be undone.')">Delete Ticket</a>
		</div>
	</div>
	{{if count($stock_actions) > 0}}
	<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:16px">
		<h4 style="margin:0;padding:10px 14px;background:#f8fafc;border-bottom:1px solid #f0f0f0;font-size:0.82em;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569">Quick Actions</h4>
		<div style="padding:12px 14px;display:flex;flex-direction:column;gap:6px">
			{{foreach $stock_actions as $sa}}
			<a href="{$sa['url']}" class="ipsButton ipsButton--inherit ipsButton--verySmall" style="width:100%;text-align:center" onclick="return confirm('Apply action: {$sa['title']}?')">{$sa['title']}</a>
			{{endforeach}}
		</div>
	</div>
	{{endif}}
</aside>
</div>
{{if count($events) > 0}}
<div style="margin-top:24px;border-top:1px solid #e5e7eb;padding-top:16px">
	<div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:12px">Ticket history</div>
	{{foreach $events as $e}}
	<div style="display:flex;gap:12px;margin-bottom:10px;font-size:13px;color:#374151;align-items:flex-start">
		<span style="color:#9ca3af;font-size:12px;flex-shrink:0;width:160px">{$e['when']}</span>
		<div style="flex:1">
			<strong>{$e['actor_name']}</strong> {$e['verb']}
			{{if $e['note']}}
			<div style="font-size:12px;color:#4b5563;margin-top:4px;padding:8px 12px;background:#f9fafb;border-radius:6px;border-left:2px solid #d1d5db">{$e['note']}</div>
			{{endif}}
		</div>
	</div>
	{{endforeach}}
</div>
{{endif}}
</div>
</div>
TEMPLATE_EOT,
	],

/* ===== FRONT: supportList ===== */
	[
	'set_id'        => 1,
	'app'           => 'gddealer',
	'location'      => 'front',
	'group'         => 'dealers',
	'template_name' => 'supportList',
	'template_data' => '$tickets, $subNav',
	'template_content' => <<<'TEMPLATE_EOT'
<div>
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;padding-bottom:14px;border-bottom:0.5px solid #e5e7eb;flex-wrap:wrap">
	<div class="gdSubNav" style="display:flex;gap:4px;flex-wrap:wrap">
		<a href="{$subNav['open_url']}" style="padding:8px 14px;font-size:13px;font-weight:500;text-decoration:none;border-radius:8px;{{if $subNav['active'] === 'open'}}background:#1e3a5f;color:#fff{{else}}color:#475569{{endif}}">
			Open tickets
			{{if $subNav['open_count'] > 0}}
			<span style="display:inline-block;margin-left:6px;padding:1px 7px;{{if $subNav['active'] === 'open'}}background:rgba(255,255,255,0.18);color:#fff{{else}}background:#f1f5f9;color:#475569{{endif}};border-radius:10px;font-size:11px;font-weight:500">{$subNav['open_count']}</span>
			{{endif}}
		</a>
		<a href="{$subNav['closed_url']}" style="padding:8px 14px;font-size:13px;font-weight:500;text-decoration:none;border-radius:8px;{{if $subNav['active'] === 'closed'}}background:#1e3a5f;color:#fff{{else}}color:#475569{{endif}}">
			Closed
			{{if $subNav['closed_count'] > 0}}
			<span style="display:inline-block;margin-left:6px;padding:1px 7px;{{if $subNav['active'] === 'closed'}}background:rgba(255,255,255,0.18);color:#fff{{else}}background:#f1f5f9;color:#475569{{endif}};border-radius:10px;font-size:11px;font-weight:500">{$subNav['closed_count']}</span>
			{{endif}}
		</a>
		<a href="{$subNav['all_url']}" style="padding:8px 14px;font-size:13px;font-weight:500;text-decoration:none;border-radius:8px;{{if $subNav['active'] === 'all'}}background:#1e3a5f;color:#fff{{else}}color:#475569{{endif}}">
			All tickets
		</a>
	</div>
	<a href="{$subNav['new_url']}" style="padding:9px 18px;background:#16a34a;color:#fff;font-size:13px;font-weight:500;border-radius:8px;text-decoration:none;white-space:nowrap">
		+ New ticket
	</a>
</div>
{{if count($tickets) === 0}}
<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:60px 40px;text-align:center">
	<div style="font-size:15px;color:#111827;font-weight:500;margin-bottom:8px">
		{{if $subNav['active'] === 'open'}}No open tickets{{elseif $subNav['active'] === 'closed'}}No closed tickets{{else}}No tickets yet{{endif}}
	</div>
	<div style="font-size:13px;color:#64748b;margin-bottom:20px">Need help? Open a ticket and we'll get back to you.</div>
	<a href="{$subNav['new_url']}" style="display:inline-block;padding:10px 20px;background:#16a34a;color:#fff;font-size:13px;font-weight:500;border-radius:8px;text-decoration:none">Open a new ticket</a>
</div>
{{else}}
<div class="gdSupportList__grid" style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden">
	<div class="gdSupportList__header" style="display:grid;grid-template-columns:52px 1fr 110px 110px 140px;gap:14px;align-items:center;padding:12px 18px;background:#1e3a5f;color:#fff">
		<div></div>
		<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600">Subject</div>
		<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600">Status</div>
		<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600">Priority</div>
		<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600">Updated</div>
	</div>
	{{foreach $tickets as $t}}
	<a href="{$t['view_url']}" class="gdSupportList__row" style="display:grid;grid-template-columns:52px 1fr 110px 110px 140px;gap:14px;align-items:center;padding:14px 18px;text-decoration:none;color:inherit;border-bottom:0.5px solid #f1f5f9;{{if $t['needs_attention']}}border-left:3px solid #f59e0b;padding-left:15px{{endif}}">
		<div class="gdSupportList__iconCell" style="width:36px;height:36px;background:{$t['icon_bg']};border-radius:10px;display:flex;align-items:center;justify-content:center;color:{$t['icon_color']};font-weight:600;font-size:15px">{$t['icon_glyph']}</div>
		<div class="gdSupportList__subject" style="min-width:0">
			<div style="font-size:14px;font-weight:500;color:#111827;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{$t['subject']}</div>
			<div style="font-size:12px;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{if $t['department_name']}}{$t['department_name']} &middot; {{endif}}#{$t['id']}</div>
		</div>
		<div class="gdSupportList__meta"><span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:{$t['status_bg']};color:{$t['status_color']};white-space:nowrap">{$t['status_label']}</span></div>
		<div class="gdSupportList__meta">{{if $t['priority'] !== 'normal'}}<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:{$t['priority_bg']};color:{$t['priority_color']};white-space:nowrap">{$t['priority_label']}</span>{{else}}<span style="font-size:12px;color:#94a3b8">Normal</span>{{endif}}</div>
		<div class="gdSupportList__meta" style="font-size:12px;color:#64748b">
			<div>{$t['updated_at_relative']}</div>
			{{if $t['last_reply_role'] === 'admin'}}
			<div style="font-size:11px;color:#1e40af;font-weight:500;margin-top:2px">Staff replied</div>
			{{elseif $t['last_reply_role'] === 'dealer'}}
			<div style="font-size:11px;color:#94a3b8;margin-top:2px">You replied</div>
			{{endif}}
		</div>
	</a>
	{{endforeach}}
</div>
{{endif}}
</div>
TEMPLATE_EOT,
	],

/* ===== FRONT: supportNew ===== */
	[
	'set_id'        => 1,
	'app'           => 'gddealer',
	'location'      => 'front',
	'group'         => 'dealers',
	'template_name' => 'supportNew',
	'template_data' => '$departments, $canSetUrgent, $bodyEditorHtml, $csrfKey, $submitUrl, $cancelUrl',
	'template_content' => <<<'TEMPLATE_EOT'
<div>
<div style="margin-bottom:14px">
	<a href="{$cancelUrl}" style="font-size:13px;color:#64748b;text-decoration:none">&larr; All tickets</a>
</div>
<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:28px;max-width:780px">
	<div style="margin-bottom:24px;padding-bottom:18px;border-bottom:0.5px solid #f1f5f9">
		<h1 style="margin:0 0 6px;font-size:20px;font-weight:500;color:#111827">Open a new ticket</h1>
		<p style="margin:0;font-size:13px;color:#64748b">Tell us what's going on and we'll respond as soon as we can.</p>
	</div>
	<form method="post" action="{$submitUrl}">
		<input type="hidden" name="csrfKey" value="{$csrfKey}">
		<div style="margin-bottom:20px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Subject</label>
			<input type="text" name="support_subject" required maxlength="160" placeholder="Brief summary of your question or issue" style="width:100%;padding:10px 12px;font-size:14px;border:0.5px solid #e5e7eb;border-radius:8px;background:#fff;color:#111827;box-sizing:border-box">
		</div>
		<div class="gdFormGrid2" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px">
			<div>
				<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Department</label>
				<select name="support_department" style="width:100%;padding:10px 12px;font-size:14px;border:0.5px solid #e5e7eb;border-radius:8px;background:#fff;color:#111827;box-sizing:border-box">
					{{foreach $departments as $dept}}
					<option value="{$dept['id']}">{$dept['name']}</option>
					{{endforeach}}
				</select>
			</div>
			<div>
				<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Priority</label>
				<select name="support_priority" style="width:100%;padding:10px 12px;font-size:14px;border:0.5px solid #e5e7eb;border-radius:8px;background:#fff;color:#111827;box-sizing:border-box">
					<option value="low">Low</option>
					<option value="normal" selected>Normal</option>
					<option value="high">High</option>
					{{if $canSetUrgent}}<option value="urgent">Urgent</option>{{endif}}
				</select>
			</div>
		</div>
		<div style="margin-bottom:24px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Message</label>
			<div>{$bodyEditorHtml|raw}</div>
			<p style="margin:6px 0 0;font-size:12px;color:#94a3b8">Include any relevant screenshots, links, or error messages.</p>
		</div>
		<div style="display:flex;gap:10px;align-items:center;padding-top:18px;border-top:0.5px solid #f1f5f9">
			<button type="submit" style="padding:10px 22px;background:#16a34a;color:#fff;font-size:14px;font-weight:500;border:none;border-radius:8px;cursor:pointer">Submit ticket</button>
			<a href="{$cancelUrl}" style="padding:10px 22px;background:transparent;color:#64748b;font-size:14px;font-weight:500;border:0.5px solid #e5e7eb;border-radius:8px;text-decoration:none">Cancel</a>
		</div>
	</form>
</div>
</div>
TEMPLATE_EOT,
	],

/* ===== FRONT: supportView ===== */
	[
	'set_id'        => 1,
	'app'           => 'gddealer',
	'location'      => 'front',
	'group'         => 'dealers',
	'template_name' => 'supportView',
	'template_data' => '$ticket, $ticketBody, $ticketAttachments, $replies, $replyEditorHtml, $csrfKey, $replyUrl, $closeUrl, $backUrl, $canReply, $canClose, $events, $newTicketUrl',
	'template_content' => <<<'TEMPLATE_EOT'
<div>
<div style="margin-bottom:14px">
	<a href="{$backUrl}" style="font-size:13px;color:#64748b;text-decoration:none">&larr; All tickets</a>
</div>
<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:12px">
	<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px">
		<h1 style="margin:0;font-size:20px;font-weight:500;color:#111827;line-height:1.4">{$ticket['subject']}</h1>
		<div style="display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end">
			<span style="padding:4px 12px;border-radius:12px;font-size:11px;font-weight:500;background:{$ticket['status_bg']};color:{$ticket['status_color']};white-space:nowrap">{$ticket['status_label']}</span>
			{{if $ticket['priority'] !== 'normal'}}
			<span style="padding:4px 12px;border-radius:12px;font-size:11px;font-weight:500;background:{$ticket['priority_bg']};color:{$ticket['priority_color']};white-space:nowrap">{$ticket['priority_label']}</span>
			{{endif}}
		</div>
	</div>
	<div style="display:flex;gap:14px;font-size:12px;color:#64748b;flex-wrap:wrap">
		<span>Ticket #{$ticket['id']}</span>
		{{if $ticket['department_name']}}<span>&middot;</span><span>{$ticket['department_name']}</span>{{endif}}
		<span>&middot;</span>
		<span>Opened {$ticket['created_at']}</span>
	</div>
</div>
<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:22px 24px;margin-bottom:20px;border-left:3px solid #16a34a">
	<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
		<span style="padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:#dcfce7;color:#166534">You</span>
		<span style="font-size:12px;color:#94a3b8">{$ticket['created_at']}</span>
	</div>
	<div style="font-size:14px;color:#374151;line-height:1.65">{$ticketBody|raw}</div>
	{{if count($ticketAttachments) > 0}}
	<div style="margin-top:16px;padding-top:12px;border-top:0.5px solid #f1f5f9">
		<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;font-weight:500;margin-bottom:8px">Attachments</div>
		<div style="display:flex;flex-wrap:wrap;gap:10px">
			{{foreach $ticketAttachments as $att}}
			<a href="{$att['url']}" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#f8fafc;border:0.5px solid #e5e7eb;border-radius:8px;font-size:13px;color:#1e3a5f;text-decoration:none">{$att['filename']}</a>
			{{endforeach}}
		</div>
	</div>
	{{endif}}
</div>
{{if count($replies) > 0}}
<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;font-weight:500;margin:0 0 10px 2px">
	{expression="count($replies)"} {expression="count($replies) === 1 ? 'reply' : 'replies'"}
</div>
<div style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px">
	{{foreach $replies as $r}}
	<div class="gdReplyCard" style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:20px 24px;border-left:3px solid {$r['role_border']}">
		<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
			<span style="padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:{$r['role_bg']};color:{$r['role_color']}">{$r['role_label']}</span>
			<strong style="font-size:13px;color:#111827;font-weight:500">{$r['author_name']}</strong>
			<span style="font-size:12px;color:#94a3b8">{$r['created_at']}</span>
		</div>
		<div style="font-size:14px;color:#374151;line-height:1.65">{$r['body']|raw}</div>
	</div>
	{{endforeach}}
</div>
{{endif}}
{{if $canReply}}
<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:20px">
	<h3 style="margin:0 0 14px;font-size:15px;font-weight:500;color:#111827">Your reply</h3>
	<form method="post" action="{$replyUrl}">
		<input type="hidden" name="csrfKey" value="{$csrfKey}">
		<div style="margin-bottom:14px">{$replyEditorHtml|raw}</div>
		<div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
			<button type="submit" style="padding:10px 22px;background:#16a34a;color:#fff;font-size:14px;font-weight:500;border:none;border-radius:8px;cursor:pointer">Post reply</button>
			{{if $canClose}}
			<a href="{$closeUrl}" style="font-size:13px;color:#64748b;text-decoration:none" onclick="return confirm('Close this ticket? You can always open a new one if you need more help.');">Close this ticket</a>
			{{endif}}
		</div>
	</form>
</div>
{{else}}
<div style="background:#f8fafc;border:0.5px solid #e5e7eb;border-radius:12px;padding:32px 24px;text-align:center;margin-bottom:20px">
	<div style="font-size:14px;color:#64748b">This ticket is closed. <a href="{$newTicketUrl}" style="color:#16a34a;font-weight:500;text-decoration:none">Open a new ticket</a> to continue the conversation.</div>
</div>
{{endif}}
{{if count($events) > 0}}
<div class="gdTicketHistory" style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:20px 24px">
	<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;font-weight:500;margin-bottom:14px">Ticket history</div>
	{{foreach $events as $e}}
	<div style="display:flex;gap:16px;margin-bottom:8px;font-size:13px;color:#374151;align-items:baseline">
		<span style="color:#94a3b8;font-size:12px;flex-shrink:0;width:180px">{$e['when']}</span>
		<div style="flex:1;min-width:0"><strong style="font-weight:500">{$e['actor_name']}</strong> {$e['verb']}
			{{if $e['note']}}
			<div style="font-size:12px;color:#4b5563;margin-top:4px;padding:8px 12px;background:#f9fafb;border-radius:6px;border-left:2px solid #d1d5db">{$e['note']}</div>
			{{endif}}
		</div>
	</div>
	{{endforeach}}
</div>
{{endif}}
</div>
TEMPLATE_EOT,
	],

/* ===== ADMIN: supportDepartments ===== */
	[
	'set_id'        => 1,
	'app'           => 'gddealer',
	'location'      => 'admin',
	'group'         => 'dealers',
	'template_name' => 'supportDepartments',
	'template_data' => '$departments, $addUrl',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsPad">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
	<h1 class="ipsType_pageTitle" style="margin:0">Support Departments</h1>
	<a href="{$addUrl}" class="ipsButton ipsButton--primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Department</a>
</div>
{{if count($departments) === 0}}
<div style="padding:40px;text-align:center;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;color:#6b7280">
	<p style="margin:0 0 12px;font-size:14px">No support departments configured.</p>
	<a href="{$addUrl}" class="ipsButton ipsButton--primary ipsButton--small">Create the first department</a>
</div>
{{else}}
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
	<table style="width:100%;border-collapse:collapse">
		<thead>
			<tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb">
				<th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500;width:40px">#</th>
				<th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500">Department</th>
				<th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500">Visibility</th>
				<th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500">Tickets</th>
				<th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500">Status</th>
				<th style="text-align:right;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500">Actions</th>
			</tr>
		</thead>
		<tbody>
			{{foreach $departments as $d}}
			<tr style="border-bottom:1px solid #f3f4f6">
				<td style="padding:14px 16px;color:#9ca3af;font-size:13px">{$d['position']}</td>
				<td style="padding:14px 16px">
					<div style="font-size:14px;font-weight:500;color:#111827">{$d['name']}</div>
					{{if $d['description']}}<div style="font-size:12px;color:#6b7280;margin-top:2px">{$d['description']}</div>{{endif}}
					{{if $d['email']}}<div style="font-size:11px;color:#9ca3af;margin-top:2px"><i class="fa-solid fa-envelope" aria-hidden="true"></i> {$d['email']}</div>{{endif}}
				</td>
				<td style="padding:14px 16px">
					<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:{$d['visibility_bg']};color:{$d['visibility_color']}">{$d['visibility_label']}</span>
				</td>
				<td style="padding:14px 16px;font-size:13px;color:#374151">{$d['ticket_count']}</td>
				<td style="padding:14px 16px">
					<a href="{$d['toggle_url']}" style="font-size:12px;color:{{if $d['enabled']}}#16a34a{{else}}#9ca3af{{endif}};text-decoration:none">
						<i class="fa-solid {{if $d['enabled']}}fa-circle-check{{else}}fa-circle-xmark{{endif}}" aria-hidden="true"></i>
						{{if $d['enabled']}}Enabled{{else}}Disabled{{endif}}
					</a>
				</td>
				<td style="padding:14px 16px;text-align:right;white-space:nowrap">
					<a href="{$d['move_up_url']}" style="color:#6b7280;text-decoration:none;padding:4px 6px" title="Move up"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></a>
					<a href="{$d['move_down_url']}" style="color:#6b7280;text-decoration:none;padding:4px 6px" title="Move down"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></a>
					<a href="{$d['edit_url']}" style="color:#2563eb;text-decoration:none;padding:4px 10px;font-size:13px">Edit</a>
					<a href="{$d['delete_url']}" style="color:#dc2626;text-decoration:none;padding:4px 10px;font-size:13px" onclick="return confirm('Delete this department? Only works if no tickets reference it.');">Delete</a>
				</td>
			</tr>
			{{endforeach}}
		</tbody>
	</table>
</div>
{{endif}}
</div>
TEMPLATE_EOT,
	],

/* ===== ADMIN: supportDepartmentForm ===== */
	[
	'set_id'        => 1,
	'app'           => 'gddealer',
	'location'      => 'admin',
	'group'         => 'dealers',
	'template_name' => 'supportDepartmentForm',
	'template_data' => '$formData, $isEdit, $submitUrl, $backUrl, $csrfKey',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsPad">
<div style="margin-bottom:16px">
	<a href="{$backUrl}" style="font-size:13px;color:#6b7280;text-decoration:none">&larr; Back to departments</a>
</div>
<h1 class="ipsType_pageTitle" style="margin:0 0 20px">{{if $isEdit}}Edit Department{{else}}Add Department{{endif}}</h1>
<form method="post" action="{$submitUrl}" style="max-width:640px">
	<input type="hidden" name="csrfKey" value="{$csrfKey}">
	<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px">
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Name <span style="color:#dc2626">*</span></label>
			<input type="text" name="name" value="{$formData['name']}" required class="ipsInput ipsInput--text" style="width:100%" maxlength="128">
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Short label shown to dealers in the department dropdown.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Description</label>
			<textarea name="description" rows="2" class="ipsInput ipsInput--text" style="width:100%;resize:vertical">{$formData['description']}</textarea>
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Optional helper text shown below the department name when dealers pick it.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Notification email</label>
			<input type="email" name="email" value="{$formData['email']}" class="ipsInput ipsInput--text" style="width:100%" maxlength="255">
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Optional email that receives copies of new tickets in this department. Leave blank to use the default admin recipient list.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Visibility</label>
			<select name="visibility" class="ipsInput ipsInput--select" style="width:100%">
				<option value="public" {expression="$formData['visibility'] === 'public' ? 'selected' : ''"}>Public — all dealers regardless of tier</option>
				<option value="pro" {expression="$formData['visibility'] === 'pro' ? 'selected' : ''"}>Pro+ — Pro, Founding, and Enterprise dealers</option>
				<option value="enterprise" {expression="$formData['visibility'] === 'enterprise' ? 'selected' : ''"}>Enterprise only — Enterprise and Founding dealers</option>
			</select>
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Which dealer tiers can submit tickets to this department.</p>
		</div>
		<div style="margin-bottom:4px">
			<label style="display:inline-flex;align-items:center;gap:10px;font-size:13px;font-weight:500;color:#111827;cursor:pointer">
				<input type="checkbox" name="enabled" value="1" {expression="$formData['enabled'] ? 'checked' : ''"}>
				<span>Enabled</span>
			</label>
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0 26px">Disabled departments are hidden from the new-ticket form but existing tickets stay accessible.</p>
		</div>
	</div>
	<div style="margin-top:20px;display:flex;gap:10px">
		<button type="submit" class="ipsButton ipsButton--primary">{{if $isEdit}}Save Changes{{else}}Create Department{{endif}}</button>
		<a href="{$backUrl}" class="ipsButton ipsButton--light">Cancel</a>
	</div>
</form>
</div>
TEMPLATE_EOT,
	],

/* ===== ADMIN: supportStockReplies ===== */
	[
	'set_id'        => 1,
	'app'           => 'gddealer',
	'location'      => 'admin',
	'group'         => 'dealers',
	'template_name' => 'supportStockReplies',
	'template_data' => '$rows, $addUrl',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
<div class="ipsBox_body ipsPad">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
	<h1 class="ipsType_pageTitle" style="margin:0">Stock Replies</h1>
	<a href="{$addUrl}" class="ipsButton ipsButton--primary ipsButton--small"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Stock Reply</a>
</div>
<p style="font-size:13px;color:#6b7280;margin:0 0 16px">Canned reply templates that staff can insert into ticket replies with one click.</p>
{{if count($rows) > 0}}
<table class="ipsTable ipsTable_zebra" style="width:100%">
	<thead>
		<tr>
			<th style="width:30px"></th>
			<th>Title</th>
			<th>Department</th>
			<th style="width:80px">Status</th>
			<th style="width:180px">Actions</th>
		</tr>
	</thead>
	<tbody>
		{{foreach $rows as $r}}
		<tr>
			<td style="text-align:center;color:#9ca3af">
				<a href="{$r['move_up_url']}" title="Move up" style="color:#6b7280;text-decoration:none">&uarr;</a>
				<a href="{$r['move_down_url']}" title="Move down" style="color:#6b7280;text-decoration:none">&darr;</a>
			</td>
			<td><strong>{$r['title']}</strong></td>
			<td style="font-size:0.9em;color:#6b7280">{$r['department_name']}</td>
			<td>
				{{if $r['enabled']}}
				<span style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:12px;font-size:0.8em;font-weight:600">Enabled</span>
				{{else}}
				<span style="background:#f1f5f9;color:#64748b;padding:2px 10px;border-radius:12px;font-size:0.8em;font-weight:600">Disabled</span>
				{{endif}}
			</td>
			<td style="font-size:0.85em">
				<a href="{$r['edit_url']}" style="color:#2563eb;text-decoration:none;margin-right:8px">Edit</a>
				<a href="{$r['toggle_url']}" style="color:#6b7280;text-decoration:none;margin-right:8px">{{if $r['enabled']}}Disable{{else}}Enable{{endif}}</a>
				<a href="{$r['delete_url']}" style="color:#dc2626;text-decoration:none" onclick="return confirm('Delete this stock reply?')">Delete</a>
			</td>
		</tr>
		{{endforeach}}
	</tbody>
</table>
{{else}}
<div style="text-align:center;padding:40px 20px;color:#6b7280">
	<p>No stock replies yet. Create one to speed up ticket responses.</p>
</div>
{{endif}}
</div>
</div>
TEMPLATE_EOT,
	],

/* ===== ADMIN: supportStockReplyForm ===== */
	[
	'set_id'        => 1,
	'app'           => 'gddealer',
	'location'      => 'admin',
	'group'         => 'dealers',
	'template_name' => 'supportStockReplyForm',
	'template_data' => '$formData, $isEdit, $editorHtml, $departments, $submitUrl, $backUrl, $csrfKey',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsPad">
<div style="margin-bottom:16px">
	<a href="{$backUrl}" style="font-size:13px;color:#6b7280;text-decoration:none">&larr; Back to stock replies</a>
</div>
<h1 class="ipsType_pageTitle" style="margin:0 0 20px">{{if $isEdit}}Edit Stock Reply{{else}}Add Stock Reply{{endif}}</h1>
<form method="post" action="{$submitUrl}" style="max-width:720px">
	<input type="hidden" name="csrfKey" value="{$csrfKey}">
	<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px">
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Title <span style="color:#dc2626">*</span></label>
			<input type="text" name="title" value="{$formData['title']}" required class="ipsInput ipsInput--text" style="width:100%" maxlength="255">
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Label shown in the stock reply picker on the ticket view.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Reply body</label>
			{$editorHtml|raw}
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">This content will be inserted into the reply editor when staff select this stock reply.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Department scope</label>
			<select name="department_id" class="ipsInput ipsInput--select" style="width:100%">
				{{foreach $departments as $dId => $dName}}
				<option value="{$dId}" {expression="(int)$formData['department_id'] === (int)$dId ? 'selected' : ''"}>
					{$dName}
				</option>
				{{endforeach}}
			</select>
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Global replies appear on all tickets. Department-scoped replies only appear on tickets in that department.</p>
		</div>
		<div style="margin-bottom:4px">
			<label style="display:inline-flex;align-items:center;gap:10px;font-size:13px;font-weight:500;color:#111827;cursor:pointer">
				<input type="checkbox" name="enabled" value="1" {expression="$formData['enabled'] ? 'checked' : ''"}>
				<span>Enabled</span>
			</label>
		</div>
	</div>
	<div style="margin-top:20px;display:flex;gap:10px">
		<button type="submit" class="ipsButton ipsButton--primary">{{if $isEdit}}Save Changes{{else}}Create Stock Reply{{endif}}</button>
		<a href="{$backUrl}" class="ipsButton ipsButton--light">Cancel</a>
	</div>
</form>
</div>
TEMPLATE_EOT,
	],

/* ===== ADMIN: supportStockActions ===== */
	[
	'set_id'        => 1,
	'app'           => 'gddealer',
	'location'      => 'admin',
	'group'         => 'dealers',
	'template_name' => 'supportStockActions',
	'template_data' => '$rows, $addUrl',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
<div class="ipsBox_body ipsPad">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
	<h1 class="ipsType_pageTitle" style="margin:0">Stock Actions</h1>
	<a href="{$addUrl}" class="ipsButton ipsButton--primary ipsButton--small"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Stock Action</a>
</div>
<p style="font-size:13px;color:#6b7280;margin:0 0 16px">Multi-step ticket actions: auto-reply + change status/priority/assignee in one click.</p>
{{if count($rows) > 0}}
<table class="ipsTable ipsTable_zebra" style="width:100%">
	<thead>
		<tr>
			<th style="width:30px"></th>
			<th>Title</th>
			<th>Effects</th>
			<th>Department</th>
			<th style="width:80px">Status</th>
			<th style="width:180px">Actions</th>
		</tr>
	</thead>
	<tbody>
		{{foreach $rows as $r}}
		<tr>
			<td style="text-align:center;color:#9ca3af">
				<a href="{$r['move_up_url']}" title="Move up" style="color:#6b7280;text-decoration:none">&uarr;</a>
				<a href="{$r['move_down_url']}" title="Move down" style="color:#6b7280;text-decoration:none">&darr;</a>
			</td>
			<td><strong>{$r['title']}</strong></td>
			<td style="font-size:0.85em;color:#4b5563">{$r['effects']}</td>
			<td style="font-size:0.9em;color:#6b7280">{$r['department_name']}</td>
			<td>
				{{if $r['enabled']}}
				<span style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:12px;font-size:0.8em;font-weight:600">Enabled</span>
				{{else}}
				<span style="background:#f1f5f9;color:#64748b;padding:2px 10px;border-radius:12px;font-size:0.8em;font-weight:600">Disabled</span>
				{{endif}}
			</td>
			<td style="font-size:0.85em">
				<a href="{$r['edit_url']}" style="color:#2563eb;text-decoration:none;margin-right:8px">Edit</a>
				<a href="{$r['toggle_url']}" style="color:#6b7280;text-decoration:none;margin-right:8px">{{if $r['enabled']}}Disable{{else}}Enable{{endif}}</a>
				<a href="{$r['delete_url']}" style="color:#dc2626;text-decoration:none" onclick="return confirm('Delete this stock action?')">Delete</a>
			</td>
		</tr>
		{{endforeach}}
	</tbody>
</table>
{{else}}
<div style="text-align:center;padding:40px 20px;color:#6b7280">
	<p>No stock actions yet. Create one to automate common ticket workflows.</p>
</div>
{{endif}}
</div>
</div>
TEMPLATE_EOT,
	],

/* ===== ADMIN: supportStockActionForm ===== */
	[
	'set_id'        => 1,
	'app'           => 'gddealer',
	'location'      => 'admin',
	'group'         => 'dealers',
	'template_name' => 'supportStockActionForm',
	'template_data' => '$formData, $isEdit, $editorHtml, $departments, $adminMembers, $submitUrl, $backUrl, $csrfKey',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsPad">
<div style="margin-bottom:16px">
	<a href="{$backUrl}" style="font-size:13px;color:#6b7280;text-decoration:none">&larr; Back to stock actions</a>
</div>
<h1 class="ipsType_pageTitle" style="margin:0 0 20px">{{if $isEdit}}Edit Stock Action{{else}}Add Stock Action{{endif}}</h1>
<form method="post" action="{$submitUrl}" style="max-width:720px">
	<input type="hidden" name="csrfKey" value="{$csrfKey}">
	<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px">
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Title <span style="color:#dc2626">*</span></label>
			<input type="text" name="title" value="{$formData['title']}" required class="ipsInput ipsInput--text" style="width:100%" maxlength="255">
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Label shown in the stock action picker on the ticket view.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Auto-reply body (optional)</label>
			{$editorHtml|raw}
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">If provided, this reply will be posted to the ticket when the action runs. Dealer will be notified.</p>
		</div>
		<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px">
			<div>
				<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Set status to</label>
				<select name="new_status" class="ipsInput ipsInput--select" style="width:100%">
					<option value="" {expression="$formData['new_status'] === '' ? 'selected' : ''"}>No change</option>
					<option value="open" {expression="$formData['new_status'] === 'open' ? 'selected' : ''"}>Open</option>
					<option value="pending_staff" {expression="$formData['new_status'] === 'pending_staff' ? 'selected' : ''"}>Awaiting Staff</option>
					<option value="pending_customer" {expression="$formData['new_status'] === 'pending_customer' ? 'selected' : ''"}>Awaiting Customer</option>
					<option value="resolved" {expression="$formData['new_status'] === 'resolved' ? 'selected' : ''"}>Resolved</option>
					<option value="closed" {expression="$formData['new_status'] === 'closed' ? 'selected' : ''"}>Closed</option>
				</select>
			</div>
			<div>
				<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Set priority to</label>
				<select name="new_priority" class="ipsInput ipsInput--select" style="width:100%">
					<option value="" {expression="$formData['new_priority'] === '' ? 'selected' : ''"}>No change</option>
					<option value="low" {expression="$formData['new_priority'] === 'low' ? 'selected' : ''"}>Low</option>
					<option value="normal" {expression="$formData['new_priority'] === 'normal' ? 'selected' : ''"}>Normal</option>
					<option value="high" {expression="$formData['new_priority'] === 'high' ? 'selected' : ''"}>High</option>
					<option value="urgent" {expression="$formData['new_priority'] === 'urgent' ? 'selected' : ''"}>Urgent</option>
				</select>
			</div>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Assign to (member ID)</label>
			<input type="text" name="new_assignee" value="{$formData['new_assignee']}" class="ipsInput ipsInput--text" style="width:100%" placeholder="Leave blank for no change, 0 to unassign">
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Enter a member ID or 0 to unassign. Leave blank to skip assignment changes.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Department scope</label>
			<select name="department_id" class="ipsInput ipsInput--select" style="width:100%">
				{{foreach $departments as $dId => $dName}}
				<option value="{$dId}" {expression="(int)$formData['department_id'] === (int)$dId ? 'selected' : ''"}>
					{$dName}
				</option>
				{{endforeach}}
			</select>
		</div>
		<div style="margin-bottom:4px">
			<label style="display:inline-flex;align-items:center;gap:10px;font-size:13px;font-weight:500;color:#111827;cursor:pointer">
				<input type="checkbox" name="enabled" value="1" {expression="$formData['enabled'] ? 'checked' : ''"}>
				<span>Enabled</span>
			</label>
		</div>
	</div>
	<div style="margin-top:20px;display:flex;gap:10px">
		<button type="submit" class="ipsButton ipsButton--primary">{{if $isEdit}}Save Changes{{else}}Create Stock Action{{endif}}</button>
		<a href="{$backUrl}" class="ipsButton ipsButton--light">Cancel</a>
	</div>
</form>
</div>
TEMPLATE_EOT,
	],

	/* ===== ADMIN: fflVerifications ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'admin',
		'group'         => 'dealers',
		'template_name' => 'fflVerifications',
		'template_data' => '$data',
		'template_content' => <<<'TEMPLATE_EOT'
<style>
.gddealerFflQueue { padding: 0 4px; }
.gddealerFflQueue__tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 16px; }
.gddealerFflQueue__tab { padding: 10px 14px; font-size: 14px; font-weight: 500; color: #475569; text-decoration: none; border-bottom: 2px solid transparent; }
.gddealerFflQueue__tab.is-active { color: #1e40af; border-bottom-color: #1e40af; }
.gddealerFflQueue__tab .count { display: inline-block; background: #e5e7eb; color: #475569; font-size: 11px; padding: 1px 7px; border-radius: 999px; margin-left: 6px; font-weight: 600; }
.gddealerFflQueue__tab.is-active .count { background: #dbeafe; color: #1e40af; }
.gddealerFflTable { width: 100%; border-collapse: collapse; }
.gddealerFflTable th { text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; padding: 10px 12px; border-bottom: 2px solid #e5e7eb; }
.gddealerFflTable td { padding: 14px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 14px; }
.gddealerFflTable__ffl { font-family: ui-monospace, 'SF Mono', Menlo, monospace; font-size: 13px; color: #0f172a; }
.gddealerFflTable__actions { display: flex; gap: 6px; justify-content: flex-end; }
.gddealerFflTable__btn { padding: 6px 12px; font-size: 13px; font-weight: 500; border-radius: 5px; text-decoration: none; border: 1px solid; cursor: pointer; }
.gddealerFflTable__btn--view { background: #f8fafc; border-color: #e5e7eb; color: #334155; }
.gddealerFflTable__btn--verify { background: #10b981; border-color: #059669; color: #fff; }
.gddealerFflTable__btn--reject { background: #fff; border-color: #ef4444; color: #ef4444; }
.gddealerFflTable__status { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.gddealerFflTable__status--pending { background: #fef3c7; color: #92400e; }
.gddealerFflTable__status--verified { background: #d1fae5; color: #065f46; }
.gddealerFflTable__status--rejected { background: #fee2e2; color: #991b1b; }
.gddealerFflTable__empty { padding: 40px; text-align: center; color: #94a3b8; font-size: 14px; }
.gddealerFflTable__rejectionReason { font-size: 12px; color: #991b1b; font-style: italic; margin-top: 4px; }
</style>

<div class="gddealerFflQueue">
	<div class="gddealerFflQueue__tabs">
		<a href="{$data['filter_urls']['pending']}"  class="gddealerFflQueue__tab {expression="$data['filter'] === 'pending'  ? 'is-active' : ''"}">{lang="gddealer_acp_ffl_filter_pending"}<span class="count">{$data['counts']['pending']}</span></a>
		<a href="{$data['filter_urls']['verified']}" class="gddealerFflQueue__tab {expression="$data['filter'] === 'verified' ? 'is-active' : ''"}">{lang="gddealer_acp_ffl_filter_verified"}<span class="count">{$data['counts']['verified']}</span></a>
		<a href="{$data['filter_urls']['rejected']}" class="gddealerFflQueue__tab {expression="$data['filter'] === 'rejected' ? 'is-active' : ''"}">{lang="gddealer_acp_ffl_filter_rejected"}<span class="count">{$data['counts']['rejected']}</span></a>
		<a href="{$data['filter_urls']['all']}"      class="gddealerFflQueue__tab {expression="$data['filter'] === 'all'      ? 'is-active' : ''"}">{lang="gddealer_acp_ffl_filter_all"}<span class="count">{$data['counts']['all']}</span></a>
	</div>

	{{if empty( $data['rows'] )}}
	<div class="gddealerFflTable__empty">{lang="gddealer_acp_ffl_empty_pending"}</div>
	{{else}}
	<table class="gddealerFflTable">
		<thead>
			<tr>
				<th>Dealer</th>
				<th>FFL #</th>
				<th>Submitted</th>
				<th>License</th>
				<th>Status</th>
				<th style="text-align:right;">Actions</th>
			</tr>
		</thead>
		<tbody>
			{{foreach $data['rows'] as $r}}
			<tr>
				<td>
					<div style="font-weight:600;color:#0f172a;">{$r['dealer_name']}</div>
					<div style="font-size:12px;color:#64748b;">ID {$r['dealer_id']} · {$r['dealer_slug']}</div>
					{{if $r['status'] === 'rejected' && $r['ffl_rejection_reason']}}
					<div class="gddealerFflTable__rejectionReason">Last rejection: {$r['ffl_rejection_reason']} (attempt {$r['ffl_rejection_count']} of 3)</div>
					{{endif}}
				</td>
				<td class="gddealerFflTable__ffl">{$r['ffl_number']}</td>
				<td>{$r['ffl_submitted_label']}</td>
				<td>
					{{if $r['ffl_license_url']}}
					<a href="{$r['ffl_license_url']}" target="_blank" rel="nofollow noopener" class="gddealerFflTable__btn gddealerFflTable__btn--view">View PDF</a>
					{{else}}
					<span style="color:#94a3b8;font-size:12px;">No URL</span>
					{{endif}}
				</td>
				<td>
					{{if $r['status'] === 'pending'}}
					<span class="gddealerFflTable__status gddealerFflTable__status--pending">Pending</span>
					{{elseif $r['status'] === 'verified'}}
					<span class="gddealerFflTable__status gddealerFflTable__status--verified">Verified {$r['ffl_verified_label']}</span>
					{{elseif $r['status'] === 'rejected'}}
					<span class="gddealerFflTable__status gddealerFflTable__status--rejected">Rejected</span>
					{{endif}}
				</td>
				<td>
					<div class="gddealerFflTable__actions">
						{{if $r['status'] !== 'verified'}}
						<a href="{$r['verify_url']}" class="gddealerFflTable__btn gddealerFflTable__btn--verify" onclick="return confirm('Mark this FFL as verified?');">Verify</a>
						{{endif}}
						{{if $r['status'] !== 'rejected' || $r['ffl_rejection_count'] < 3}}
						<a href="{$r['reject_url']}" class="gddealerFflTable__btn gddealerFflTable__btn--reject">Reject</a>
						{{endif}}
					</div>
				</td>
			</tr>
			{{endforeach}}
		</tbody>
	</table>
	{{endif}}
</div>
TEMPLATE_EOT,
	],

	/* ===== ADMIN: fflRejectForm ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'admin',
		'group'         => 'dealers',
		'template_name' => 'fflRejectForm',
		'template_data' => '$data',
		'template_content' => <<<'TEMPLATE_EOT'
<style>
.gddealerFflReject { max-width: 560px; margin: 0 auto; padding: 24px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
.gddealerFflReject__title { margin: 0 0 4px; font-size: 18px; font-weight: 600; color: #0f172a; }
.gddealerFflReject__sub { margin: 0 0 20px; font-size: 13px; color: #64748b; }
.gddealerFflReject__reasonOption { display: block; padding: 12px 14px; margin-bottom: 8px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; font-size: 14px; color: #334155; }
.gddealerFflReject__reasonOption:hover { background: #f8fafc; }
.gddealerFflReject__reasonOption input { margin-right: 10px; }
.gddealerFflReject__otherBox { margin-top: 8px; display: none; }
.gddealerFflReject__otherBox.is-visible { display: block; }
.gddealerFflReject__otherTextarea { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit; resize: vertical; min-height: 80px; box-sizing: border-box; }
.gddealerFflReject__actions { margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end; }
.gddealerFflReject__btn { padding: 8px 16px; font-size: 14px; font-weight: 500; border-radius: 6px; cursor: pointer; text-decoration: none; }
.gddealerFflReject__btn--cancel { background: #fff; border: 1px solid #e5e7eb; color: #475569; }
.gddealerFflReject__btn--submit { background: #ef4444; border: 1px solid #dc2626; color: #fff; }
</style>

<div class="gddealerFflReject">
	<h2 class="gddealerFflReject__title">Reject FFL submission</h2>
	<p class="gddealerFflReject__sub">Dealer: <strong>{$data['dealer']['dealer_name']}</strong> · FFL # {$data['dealer']['ffl_number']}</p>

	<form method="post" action="{$data['post_url']}" id="gddealerFflRejectForm">
		<label class="gddealerFflReject__reasonOption"><input type="radio" name="reason_key" value="illegible" required> {lang="gddealer_ffl_rejection_illegible"}</label>
		<label class="gddealerFflReject__reasonOption"><input type="radio" name="reason_key" value="expired"> {lang="gddealer_ffl_rejection_expired"}</label>
		<label class="gddealerFflReject__reasonOption"><input type="radio" name="reason_key" value="mismatch"> {lang="gddealer_ffl_rejection_mismatch"}</label>
		<label class="gddealerFflReject__reasonOption"><input type="radio" name="reason_key" value="other" id="gddealerFflRejectOther"> Other (specify below)</label>

		<div class="gddealerFflReject__otherBox" id="gddealerFflRejectOtherBox">
			<textarea name="reason_other" class="gddealerFflReject__otherTextarea" placeholder="Enter the specific reason the dealer will receive..."></textarea>
		</div>

		<div class="gddealerFflReject__actions">
			<a href="{$data['cancel_url']}" class="gddealerFflReject__btn gddealerFflReject__btn--cancel">Cancel</a>
			<button type="submit" class="gddealerFflReject__btn gddealerFflReject__btn--submit">Reject &amp; notify dealer</button>
		</div>
	</form>
</div>

<script>
(function() {
	var otherRadio = document.getElementById( 'gddealerFflRejectOther' );
	var otherBox   = document.getElementById( 'gddealerFflRejectOtherBox' );
	var form       = document.getElementById( 'gddealerFflRejectForm' );
	if ( !form ) return;
	form.addEventListener( 'change', function() {
		if ( otherRadio && otherRadio.checked ) { otherBox.classList.add( 'is-visible' ); }
		else { otherBox.classList.remove( 'is-visible' ); }
	} );
})();
</script>
TEMPLATE_EOT,
	],

	/* ===== FRONT: feedUploadForm ===== */
	[
		'set_id'        => 1,
		'app'           => 'gddealer',
		'location'      => 'front',
		'group'         => 'dealers',
		'template_name' => 'feedUploadForm',
		'template_data' => '$data',
		'template_content' => <<<'TEMPLATE_EOT'
<div style="max-width:720px;margin:0 auto">
	<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:8px">
		<h2 style="margin:0;font-size:1.4em;font-weight:700;color:#0f172a">Upload Feed File</h2>
		<a href="{$data['tab_urls']['feedSettings']}" class="ipsButton ipsButton--light ipsButton--small">&larr; Back to Feed Settings</a>
	</div>
	<div class="ipsBox" style="padding:24px">
		{$data['form']}
	</div>
</div>
TEMPLATE_EOT,
	],
];

foreach ( $gddealerTemplates as $tpl )
{
	\IPS\Db::i()->insert( 'core_theme_templates', [
		'template_set_id'   => $tpl['set_id'],
		'template_app'      => $tpl['app'],
		'template_location' => $tpl['location'],
		'template_group'    => $tpl['group'],
		'template_name'     => $tpl['template_name'],
		'template_data'     => $tpl['template_data'],
		'template_content'  => $tpl['template_content'],
	]);
}

/* Backfill missing dealer slugs. Existing rows from earlier installs may have
   dealer_slug IS NULL; generate a URL-safe slug from dealer_name using the
   same algorithm as manualOnboard() in modules/admin/dealers/dealers.php.
   Uniqueness is enforced by the uq_dealer_slug index — append -1, -2, ...
   until a free slug is found. */
try
{
	foreach ( \IPS\Db::i()->select( 'dealer_id, dealer_name', 'gd_dealer_feed_config',
		[ 'dealer_slug IS NULL' ] ) as $row )
	{
		$slug = strtolower( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $row['dealer_name'] ) ) );
		$slug = trim( $slug, '-' );
		if ( $slug === '' )
		{
			$slug = 'dealer-' . (int) $row['dealer_id'];
		}
		$base = $slug;
		$i    = 1;
		while ( (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_config',
			[ 'dealer_slug=?', $slug ] )->first() > 0 )
		{
			$slug = $base . '-' . $i++;
		}
		\IPS\Db::i()->update( 'gd_dealer_feed_config',
			[ 'dealer_slug' => $slug ],
			[ 'dealer_id=?', (int) $row['dealer_id'] ] );
	}
}
catch ( \Exception ) {}

/* Schema migration: add dealer_dashboard_prefs column on upgrade installs.
   On fresh installs schema.json creates it; this guards re-installs over
   an older copy of the table. */
try
{
	$cols = \IPS\Db::i()->getTableDefinition( 'gd_dealer_feed_config' );
	if ( !isset( $cols['columns']['dealer_dashboard_prefs'] ) )
	{
		\IPS\Db::i()->addColumn( 'gd_dealer_feed_config', [
			'name'       => 'dealer_dashboard_prefs',
			'type'       => 'TEXT',
			'length'     => null,
			'allow_null' => true,
			'default'    => null,
		] );
	}
}
catch ( \Exception ) {}

/* Seed notification defaults for gddealer notification types so IPS
   knows which methods (inline/email) are enabled by default for every
   notification type the DealerNotifications extension registers. Safe
   to re-run: duplicate notification_key inserts are swallowed. */
$notificationDefaults = [
	'new_dealer_review'    => [ 'default' => 'inline,email', 'disabled' => '' ],
	'updated_dealer_review' => [ 'default' => 'inline,email', 'disabled' => '' ],
	'review_disputed'      => [ 'default' => 'inline,email', 'disabled' => '' ],
	'dealer_responded'     => [ 'default' => 'inline',       'disabled' => '' ],
	'dispute_admin_review' => [ 'default' => 'inline,email', 'disabled' => '' ],
	'dispute_upheld'       => [ 'default' => 'inline,email', 'disabled' => '' ],
	'dispute_dismissed'    => [ 'default' => 'inline,email', 'disabled' => '' ],
	'dispute_customer_responded' => [ 'default' => 'inline,email', 'disabled' => '' ],
	'dispute_outcome_reviewer'   => [ 'default' => 'inline,email', 'disabled' => '' ],
	'dispute_edit_requested'     => [ 'default' => 'inline,email', 'disabled' => '' ],
	'support_ticket_new'         => [ 'default' => 'inline,email', 'disabled' => '' ],
	'support_reply_to_dealer'    => [ 'default' => 'inline,email', 'disabled' => '' ],
	'support_reply_to_admin'     => [ 'default' => 'inline,email', 'disabled' => '' ],
	'gddealer_ffl_verified'      => [ 'default' => 'inline,email', 'disabled' => '' ],
	'gddealer_ffl_rejected'      => [ 'default' => 'inline,email', 'disabled' => '' ],
	'listing_reported'           => [ 'default' => 'inline,email', 'disabled' => '' ],
	'listing_cap_reached'        => [ 'default' => 'inline,email', 'disabled' => '' ],
];

foreach ( $notificationDefaults as $key => $data )
{
	try
	{
		\IPS\Db::i()->insert( 'core_notification_defaults', [
			'notification_key' => $key,
			'default'          => $data['default'],
			'disabled'         => $data['disabled'],
		] );
	}
	catch ( \Exception ) {}
}

/* Safety-net email template seeding — parse data/emails.xml directly and
   insert any template that isn't already present. IPS's own email template
   loader from emails.xml has been observed to silently no-op on some
   installs, so we force the rows ourselves rather than trusting it. Each
   insert is in its own try/catch; a minimal-column fallback handles
   schema variants that don't have template_key/parent/edited/pinned. */
$emailsXmlPath = __DIR__ . '/../data/emails.xml';
if ( file_exists( $emailsXmlPath ) )
{
	$xml  = @simplexml_load_file( $emailsXmlPath, \SimpleXMLElement::class, LIBXML_NONET );

	if ( $xml instanceof \SimpleXMLElement )
	{
		foreach ( $xml->template as $t )
		{
			$templateName = trim( (string) $t->template_name );
			if ( $templateName === '' ) { continue; }

			try
			{
				$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_email_templates',
					[ 'template_app=? AND template_name=?', 'gddealer', $templateName ]
				)->first();

				if ( $exists > 0 ) { continue; }

				\IPS\Db::i()->insert( 'core_email_templates', [
					'template_app'               => 'gddealer',
					'template_name'              => $templateName,
					'template_data'              => (string) $t->template_data,
					'template_content_html'      => (string) $t->template_content_html,
					'template_content_plaintext' => (string) $t->template_content_plaintext,
					'template_key'               => md5( 'gddealer;' . $templateName ),
					'template_parent'            => 0,
					'template_edited'            => 0,
					'template_pinned'            => 0,
				] );
			}
			catch ( \Exception )
			{
				try
				{
					\IPS\Db::i()->insert( 'core_email_templates', [
						'template_app'               => 'gddealer',
						'template_name'              => $templateName,
						'template_content_html'      => (string) $t->template_content_html,
						'template_content_plaintext' => (string) $t->template_content_plaintext,
					] );
				}
				catch ( \Exception ) {}
			}
		}
	}
}


\IPS\Db::i()->replace( 'core_theme_templates', [
	'template_set_id'  => 1,
	'template_app'     => 'gddealer',
	'template_location'=> 'front',
	'template_group'   => 'dealers',
	'template_name'    => 'dashboardCategories',
	'template_data'    => '$data',
	'template_updated' => time(),
	'template_version' => '1.0.196',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="gdDashboard">
    <div class="gdDashboard__shell">

        <div class="gdDashboard__body">

            <h1 class="gdDashboard__title">Category Mappings</h1>

            <p class="gdDashboard__lede">
                Your feed uses categories like "Firearms, Handguns, Pistols". We map them to one of our
                canonical categories so search and filtering work consistently. Edit any mapping below;
                set a category to "skip" to drop those records from your listings.
            </p>

            {{if $data['saved_flash']}}
            <div class="gdAlert gdAlert--success">Mappings saved.</div>
            {{endif}}

            {{if $data['unreviewed'] > 0}}
            <div class="gdAlert gdAlert--info">
                <strong>{$data['unreviewed']}</strong> mapping(s) were auto-classified and haven't been reviewed yet. Confirm or change them below — any edit you save will mark that row as reviewed.
            </div>
            {{endif}}

            {{if $data['by_canonical']}}
            <div class="gdCatSummary">
                {{foreach $data['by_canonical'] as $cat => $info}}
                <div class="gdCatSummary__pill">
                    <span class="gdCatSummary__name">{$cat}</span>
                    <span class="gdCatSummary__count">{$info['count']} records · {$info['rows']} rules</span>
                </div>
                {{endforeach}}
            </div>
            {{endif}}

            {{if count( $data['mappings'] ) === 0}}
                <div class="gdEmpty">
                    <p>No category mappings yet. Run your next import and they'll populate automatically.</p>
                </div>
            {{else}}
                <form method="post" action="{$data['save_url']}" class="gdCatForm">
                    <input type="hidden" name="csrfKey" value="{$data['csrf_key']}">

                    <table class="gdCatTable">
                        <thead>
                            <tr>
                                <th class="gdCatTable__source">Your category</th>
                                <th class="gdCatTable__canonical">Maps to</th>
                                <th class="gdCatTable__count">Records</th>
                                <th class="gdCatTable__status">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{foreach $data['mappings'] as $m}}
                            <tr class="{{if $m['auto_mapped']}}gdCatTable__row--auto{{endif}}">
                                <td class="gdCatTable__source">
                                    <span class="gdCatTable__sourceText">{$m['source_value']}</span>
                                </td>
                                <td class="gdCatTable__canonical">
                                    <select name="canonical[{$m['id']}]" class="gdCatTable__select">
                                        {{foreach $data['valid_options'] as $opt}}
                                        <option value="{$opt}" {{if $m['canonical'] === $opt}}selected{{endif}}>{$opt}</option>
                                        {{endforeach}}
                                    </select>
                                </td>
                                <td class="gdCatTable__count">{$m['occurrence_count']}</td>
                                <td class="gdCatTable__status">
                                    {{if $m['auto_mapped']}}
                                        <span class="gdCatTable__badge gdCatTable__badge--auto">auto</span>
                                    {{else}}
                                        <span class="gdCatTable__badge gdCatTable__badge--confirmed">confirmed</span>
                                    {{endif}}
                                </td>
                            </tr>
                            {{endforeach}}
                        </tbody>
                    </table>

                    <div class="gdCatForm__actions">
                        <button type="submit" class="gdBtn gdBtn--primary">Save Changes</button>
                    </div>
                </form>
            {{endif}}

        </div>
    </div>
</div>

<style>
.gdDashboard__shell { max-width: 1200px; margin: 0 auto; padding: 24px; font-family: 'Inter', system-ui, sans-serif; }
.gdDashboard__title { font-size: 28px; font-weight: 700; margin: 0 0 8px; color: #111827; }
.gdDashboard__lede { color: #6b7280; margin: 0 0 24px; max-width: 760px; line-height: 1.6; }
.gdAlert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
.gdAlert--success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
.gdAlert--info { background: #dbeafe; color: #1e40af; border: 1px solid #60a5fa; }
.gdCatSummary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
.gdCatSummary__pill { background: #f3f4f6; padding: 8px 14px; border-radius: 999px; font-size: 13px; }
.gdCatSummary__name { font-weight: 600; color: #111827; text-transform: capitalize; }
.gdCatSummary__count { color: #6b7280; margin-left: 6px; }
.gdEmpty { padding: 48px; text-align: center; background: #f9fafb; border-radius: 12px; color: #6b7280; }
.gdCatForm { background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; overflow: hidden; }
.gdCatTable { width: 100%; border-collapse: collapse; }
.gdCatTable th { background: #f9fafb; padding: 12px 16px; text-align: left; font-weight: 600; font-size: 13px; color: #374151; border-bottom: 1px solid #e5e7eb; }
.gdCatTable td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; }
.gdCatTable__row--auto { background: #fffbeb; }
.gdCatTable__sourceText { font-family: 'JetBrains Mono', monospace; font-size: 13px; color: #1f2937; }
.gdCatTable__select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
.gdCatTable__count { color: #6b7280; font-variant-numeric: tabular-nums; }
.gdCatTable__badge { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.gdCatTable__badge--auto { background: #fef3c7; color: #92400e; }
.gdCatTable__badge--confirmed { background: #d1fae5; color: #065f46; }
.gdCatForm__actions { padding: 16px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; }
.gdBtn { padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; }
.gdBtn--primary { background: #1e40af; color: #fff; }
.gdBtn--primary:hover { background: #1e3a8a; }
</style>
TEMPLATE_EOT,
] );

/* v1.0.198 — Seed full dashboardCustomize profile editor (replaces basic prefs
   form inserted via the $gddealerTemplates array above). */
/* v1.0.201 - Disabled: templates_10088.php (runtime profile editor seed)
 * and templates_10113.php (runtime FFL injection via regex-extraction)
 * have been replaced by the full nowdoc template body in $gddealerTemplates
 * above. Per rule #28, template seeds embed content literally via nowdoc
 * — no runtime extraction or regex injection. Leaving the files on disk
 * for historical reference but not loading them.
 */
// require_once __DIR__ . '/templates_10088.php';
// require_once __DIR__ . '/templates_10113.php';

/* v1.0.198 — Seed wizard step templates (setupWizardStep1-5).
   Insert skeleton rows first so that update()-only template files
   have a row to write into, then run the full chain. */
$_wizSkeletons = [
	[ 'setupWizardStep1', '$wizardData,$values,$errors=array()' ],
	[ 'setupWizardStep2', '$wizardData,$values' ],
	[ 'setupWizardStep3', '$wizardData,$values' ],
	[ 'setupWizardStep4', '$wizardData,$values' ],
	[ 'setupWizardStep5', '$wizardData,$values' ],
];
foreach ( $_wizSkeletons as [ $_n, $_s ] )
{
	try
	{
		\IPS\Db::i()->replace( 'core_theme_templates', [
			'template_set_id'  => 1,
			'template_app'     => 'gddealer',
			'template_location'=> 'front',
			'template_group'   => 'dealers',
			'template_name'    => $_n,
			'template_data'    => $_s,
			'template_content' => '',
			'template_updated' => time(),
		] );
	}
	catch ( \Throwable ) {}
}

require_once __DIR__ . '/templates_10149_part1.php';
require_once __DIR__ . '/templates_10149_part2.php';
require_once __DIR__ . '/templates_10152_part1.php';
require_once __DIR__ . '/templates_10161_part1.php';
require_once __DIR__ . '/templates_10162_part1.php';
require_once __DIR__ . '/templates_10163_part1.php';
require_once __DIR__ . '/templates_10164_part1.php';
require_once __DIR__ . '/templates_10165_part1.php';

require_once __DIR__ . '/templates_10152_part2.php';
require_once __DIR__ . '/templates_10152_part3.php';

require_once __DIR__ . '/templates_10155_part1.php';
require_once __DIR__ . '/templates_10155_part2.php';

require_once __DIR__ . '/templates_10156_part1.php';
require_once __DIR__ . '/templates_10156_part2.php';

require_once __DIR__ . '/templates_10158_part1.php';
require_once __DIR__ . '/templates_10158_part2.php';
require_once __DIR__ . '/templates_10160_part1.php';

/* v1.0.199 — Seed authoritative template bodies for listings, unmatched,
   feedSettings, analytics, and reviews. These replace() calls overwrite
   the stale placeholder content from the $gddealerTemplates array. */
require_once __DIR__ . '/templates_10072.php';
require_once __DIR__ . '/templates_10074.php';
require_once __DIR__ . '/templates_10077.php';
require_once __DIR__ . '/templates_10078.php';
require_once __DIR__ . '/templates_10158_part3.php';
require_once __DIR__ . '/templates_10158_part4.php';

require_once __DIR__ . '/templates_10071.php';
require_once __DIR__ . '/templates_10088.php';
require_once __DIR__ . '/templates_10090a.php';
require_once __DIR__ . '/templates_10147_help.php';
require_once __DIR__ . '/templates_10149_part3.php';
require_once __DIR__ . '/templates_10213_supportTicketView.php';
require_once __DIR__ . '/templates_10229.php';

/* v1.0.213 — Canonical pass: re-runs all overlay files referenced by
 * CanonicalTemplates::SOURCES to guarantee the 12 managed templates
 * end up at their authoritative bodies, regardless of require order. */
try
{
    require_once __DIR__ . '/../sources/Setup/CanonicalTemplates.php';
    \IPS\gddealer\Setup\CanonicalTemplates::ensure();

    \IPS\gddealer\Setup\CanonicalTemplates::verifyAndForce();

    \IPS\gddealer\Setup\CanonicalTemplates::clearCaches();
}
catch ( \Throwable $e )
{
    try { \IPS\Log::log( 'install.php canonical pass failed: ' . $e->getMessage(), 'gddealer_install' ); }
    catch ( \Throwable ) {}
}

/* Force furl + applications + extensions + email-template cache rebuild
   so new routes, templates, and extension classes appear without a manual
   cache flush. */
unset( \IPS\Data\Store::i()->furl_configuration );
unset( \IPS\Data\Store::i()->applications );
unset( \IPS\Data\Store::i()->extensions );
try { unset( \IPS\Data\Store::i()->emailTemplates ); } catch ( \Exception ) {}
