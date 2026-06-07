<?php
/**
 * Consolidated upgrade v1.0.4 — replaces upg_10001/10002/10003.
 * Fixes partially-upgraded installs where only upg_10001 ran.
 * Fully idempotent — safe to re-run.
 */

namespace IPS\gdloadout\setup\upg_10004;

class _upgrade
{
	public function step1(): bool
	{
		/* ----------------------------------------------------------------
		 * 1. Belt-and-suspenders: ensure gd_loadout_group_limits table exists
		 * ---------------------------------------------------------------- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_loadout_group_limits' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_loadout_group_limits',
					'columns' => [
						[ 'name' => 'id', 'type' => 'INT', 'length' => 10, 'unsigned' => TRUE, 'auto_increment' => TRUE ],
						[ 'name' => 'group_id', 'type' => 'INT', 'length' => 10, 'unsigned' => TRUE, 'default' => 0 ],
						[ 'name' => 'max_loadouts', 'type' => 'INT', 'length' => 10, 'unsigned' => TRUE, 'default' => 0 ],
						[ 'name' => 'max_slots', 'type' => 'INT', 'length' => 10, 'unsigned' => TRUE, 'default' => 15 ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'columns' => [ 'id' ] ],
						[ 'type' => 'unique', 'name' => 'uq_group', 'columns' => [ 'group_id' ] ],
					],
				] );
			}
		}
		catch ( \Throwable ) {}

		/* ----------------------------------------------------------------
		 * 2. Fix max_slots default (from upg_10002)
		 * ---------------------------------------------------------------- */
		try
		{
			\IPS\Db::i()->query(
				"ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_loadout_group_limits` CHANGE `max_slots` `max_slots` INT(10) UNSIGNED NOT NULL DEFAULT 15"
			);
		}
		catch ( \Throwable ) {}

		/* ----------------------------------------------------------------
		 * 3. Seed per-group limit rows (critical piece that never ran)
		 * ---------------------------------------------------------------- */
		try
		{
			foreach ( \IPS\Db::i()->select( 'g_id', 'core_groups' ) as $gid )
			{
				try
				{
					$has = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_loadout_group_limits', [ 'group_id=?', (int) $gid ] )->first();
					if ( !$has )
					{
						\IPS\Db::i()->insert( 'gd_loadout_group_limits', [
							'group_id'     => (int) $gid,
							'max_loadouts' => 0,
							'max_slots'    => 15,
						] );
					}
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		/* ----------------------------------------------------------------
		 * 4. Re-seed ALL 5 templates
		 * ---------------------------------------------------------------- */
		$templateVersion = '1.0.4';
		$now = time();

		/* -- Template 1: hub (front/loadouts) -- */
		try
		{
			$hubContent = <<<'TEMPLATE_EOT'
<div class="ipsBox">
	<div class="ipsBox_container">
		<div class="gdlo-hub-header">
			<h1 class="ipsType_pageTitle">{lang="gdloadout_hub_title"}</h1>
			{{if $canCreate}}
			<a href="{$builderUrl}" class="ipsButton ipsButton--primary ipsButton--small"><i class="fa-solid fa-plus"></i> New Loadout</a>
			{{endif}}
		</div>

		<div class="gdlo-hub-filters">
			<span class="gdlo-filter-label">{lang="gdloadout_filter_use_case"}:</span>
			<a href="?use_case=" class="gdlo-filter-chip {{if $activeUseCase === ''}}gdlo-filter-chip--active{{endif}}">{lang="gdloadout_filter_all"}</a>
			{{foreach $useCases as $uc}}
			<a href="?use_case={expression="urlencode($uc)"}" class="gdlo-filter-chip {{if $activeUseCase === $uc}}gdlo-filter-chip--active{{endif}}">{expression="htmlspecialchars($uc)"}</a>
			{{endforeach}}
		</div>

		{{if count($sections['featured']) > 0}}
		<div class="gdlo-hub-section">
			<h2 class="gdlo-section-label"><i class="fa-solid fa-star"></i> {lang="gdloadout_sec_featured"}</h2>
			<div class="gdlo-hub-grid">
				{{foreach $sections['featured'] as $lo}}
				<a href="{expression="htmlspecialchars($lo['view_url'])"}" class="gdlo-hub-card gdlo-hub-card--featured">
					<div class="gdlo-hub-card-name">{expression="htmlspecialchars($lo['name'])"}</div>
					{{if $lo['use_case']}}
					<div class="gdlo-hub-card-uc">{expression="htmlspecialchars($lo['use_case'])"}</div>
					{{endif}}
					<div class="gdlo-hub-card-meta">
						<span>{expression="(int)$lo['total_items']"} {lang="gdloadout_view_items"}</span>
						{{if $lo['total_min_price']}}
						<span>${expression="number_format((float)$lo['total_min_price'],2)"}</span>
						{{endif}}
						<span><i class="fa-solid fa-arrow-up"></i> {expression="(int)$lo['upvotes']"}</span>
					</div>
					<div class="gdlo-hub-card-author">{lang="gdloadout_view_by"} {expression="htmlspecialchars($lo['owner_name'])"}</div>
				</a>
				{{endforeach}}
			</div>
		</div>
		{{endif}}

		{{if count($sections['trending']) > 0}}
		<div class="gdlo-hub-section">
			<h2 class="gdlo-section-label"><i class="fa-solid fa-fire"></i> {lang="gdloadout_sec_trending"}</h2>
			<div class="gdlo-hub-grid">
				{{foreach $sections['trending'] as $lo}}
				<a href="{expression="htmlspecialchars($lo['view_url'])"}" class="gdlo-hub-card">
					<div class="gdlo-hub-card-name">{expression="htmlspecialchars($lo['name'])"}</div>
					{{if $lo['use_case']}}
					<div class="gdlo-hub-card-uc">{expression="htmlspecialchars($lo['use_case'])"}</div>
					{{endif}}
					<div class="gdlo-hub-card-meta">
						<span>{expression="(int)$lo['total_items']"} {lang="gdloadout_view_items"}</span>
						{{if $lo['total_min_price']}}
						<span>${expression="number_format((float)$lo['total_min_price'],2)"}</span>
						{{endif}}
						<span><i class="fa-solid fa-arrow-up"></i> {expression="(int)$lo['upvotes']"}</span>
					</div>
					<div class="gdlo-hub-card-author">{lang="gdloadout_view_by"} {expression="htmlspecialchars($lo['owner_name'])"}</div>
				</a>
				{{endforeach}}
			</div>
		</div>
		{{endif}}

		{{if count($sections['top_rated']) > 0}}
		<div class="gdlo-hub-section">
			<h2 class="gdlo-section-label"><i class="fa-solid fa-trophy"></i> {lang="gdloadout_sec_toprated"}</h2>
			<div class="gdlo-hub-grid">
				{{foreach $sections['top_rated'] as $lo}}
				<a href="{expression="htmlspecialchars($lo['view_url'])"}" class="gdlo-hub-card">
					<div class="gdlo-hub-card-name">{expression="htmlspecialchars($lo['name'])"}</div>
					{{if $lo['use_case']}}
					<div class="gdlo-hub-card-uc">{expression="htmlspecialchars($lo['use_case'])"}</div>
					{{endif}}
					<div class="gdlo-hub-card-meta">
						<span>{expression="(int)$lo['total_items']"} {lang="gdloadout_view_items"}</span>
						{{if $lo['total_min_price']}}
						<span>${expression="number_format((float)$lo['total_min_price'],2)"}</span>
						{{endif}}
						<span><i class="fa-solid fa-arrow-up"></i> {expression="(int)$lo['upvotes']"}</span>
					</div>
					<div class="gdlo-hub-card-author">{lang="gdloadout_view_by"} {expression="htmlspecialchars($lo['owner_name'])"}</div>
				</a>
				{{endforeach}}
			</div>
		</div>
		{{endif}}

		{{if count($sections['recent']) > 0}}
		<div class="gdlo-hub-section">
			<h2 class="gdlo-section-label"><i class="fa-solid fa-clock"></i> {lang="gdloadout_sec_recent"}</h2>
			<div class="gdlo-hub-grid">
				{{foreach $sections['recent'] as $lo}}
				<a href="{expression="htmlspecialchars($lo['view_url'])"}" class="gdlo-hub-card">
					<div class="gdlo-hub-card-name">{expression="htmlspecialchars($lo['name'])"}</div>
					{{if $lo['use_case']}}
					<div class="gdlo-hub-card-uc">{expression="htmlspecialchars($lo['use_case'])"}</div>
					{{endif}}
					<div class="gdlo-hub-card-meta">
						<span>{expression="(int)$lo['total_items']"} {lang="gdloadout_view_items"}</span>
						{{if $lo['total_min_price']}}
						<span>${expression="number_format((float)$lo['total_min_price'],2)"}</span>
						{{endif}}
						<span><i class="fa-solid fa-arrow-up"></i> {expression="(int)$lo['upvotes']"}</span>
					</div>
					<div class="gdlo-hub-card-author">{lang="gdloadout_view_by"} {expression="htmlspecialchars($lo['owner_name'])"}</div>
				</a>
				{{endforeach}}
			</div>
		</div>
		{{endif}}

		{{if count($sections['budget']) > 0}}
		<div class="gdlo-hub-section">
			<h2 class="gdlo-section-label"><i class="fa-solid fa-piggy-bank"></i> {lang="gdloadout_sec_budget"}</h2>
			<div class="gdlo-hub-grid">
				{{foreach $sections['budget'] as $lo}}
				<a href="{expression="htmlspecialchars($lo['view_url'])"}" class="gdlo-hub-card">
					<div class="gdlo-hub-card-name">{expression="htmlspecialchars($lo['name'])"}</div>
					{{if $lo['use_case']}}
					<div class="gdlo-hub-card-uc">{expression="htmlspecialchars($lo['use_case'])"}</div>
					{{endif}}
					<div class="gdlo-hub-card-meta">
						<span>{expression="(int)$lo['total_items']"} {lang="gdloadout_view_items"}</span>
						{{if $lo['total_min_price']}}
						<span>${expression="number_format((float)$lo['total_min_price'],2)"}</span>
						{{endif}}
						<span><i class="fa-solid fa-arrow-up"></i> {expression="(int)$lo['upvotes']"}</span>
					</div>
					<div class="gdlo-hub-card-author">{lang="gdloadout_view_by"} {expression="htmlspecialchars($lo['owner_name'])"}</div>
				</a>
				{{endforeach}}
			</div>
		</div>
		{{endif}}

		{{if count($sections['featured']) === 0 && count($sections['trending']) === 0 && count($sections['top_rated']) === 0 && count($sections['recent']) === 0 && count($sections['budget']) === 0}}
		<div class="gdlo-hub-empty">
			<i class="fa-solid fa-layer-group"></i>
			<p>{lang="gdloadout_hub_empty"}</p>
			{{if $canCreate}}
			<a href="{$builderUrl}" class="ipsButton ipsButton--primary"><i class="fa-solid fa-plus"></i> Create Your First Build</a>
			{{endif}}
		</div>
		{{endif}}
	</div>
</div>
TEMPLATE_EOT;

			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location'=> 'front',
				'template_group'   => 'loadouts',
				'template_name'    => 'hub',
				'template_data'    => '$sections, $canCreate, $builderUrl, $activeUseCase, $useCases',
				'template_content' => $hubContent,
				'template_updated' => $now,
				'template_version' => $templateVersion,
			] );
		}
		catch ( \Throwable ) {}

		/* -- Template 2: builder (front/loadouts) -- */
		try
		{
			$builderContent = <<<'TEMPLATE_EOT'
<script type="application/json" id="gdlo-init">{$initData}</script>
<div id="gdLoadoutBuilder">

<div class="gdlo-canvas">

	<div class="gdlo-left">
		<div class="gdlo-field">
			<input type="text" id="gdLoadoutName" placeholder="{lang="gdloadout_builder_name"}" class="gdlo-input gdlo-input--title" />
		</div>
		<div class="gdlo-field">
			<textarea id="gdLoadoutDesc" placeholder="{lang="gdloadout_builder_description"}" rows="2" class="gdlo-input"></textarea>
		</div>
		<div class="gdlo-meta-row">
			<select id="gdLoadoutUseCase" class="gdlo-select">
				<option value="">-- {lang="gdloadout_builder_use_case"} --</option>
				<option value="Home Defense">{lang="gdloadout_use_case_home_defense"}</option>
				<option value="Concealed Carry">{lang="gdloadout_use_case_concealed_carry"}</option>
				<option value="Hunting">{lang="gdloadout_use_case_hunting"}</option>
				<option value="Competition">{lang="gdloadout_use_case_competition"}</option>
				<option value="Range">{lang="gdloadout_use_case_range"}</option>
				<option value="Tactical">{lang="gdloadout_use_case_tactical"}</option>
				<option value="Collection">{lang="gdloadout_use_case_collection"}</option>
			</select>
			<select id="gdLoadoutVisibility" class="gdlo-select">
				<option value="public">{lang="gdloadout_vis_public"}</option>
				<option value="unlisted" selected>{lang="gdloadout_vis_unlisted"}</option>
			</select>
		</div>

		<div class="gdlo-section-label">Core Slots</div>
		<div id="gdSlotGrid" class="gdlo-slot-grid"></div>

		<div class="gdlo-section-label">Extras</div>
		<div id="gdExtraSlots" class="gdlo-extra-slots"></div>
		<button type="button" id="gdAddExtra" class="ipsButton ipsButton--small ipsButton--light gdlo-add-extra">{lang="gdloadout_builder_add_extra"}</button>

		<div id="gdExtraPicker" class="gdlo-picker" style="display:none">
			<div class="gdlo-picker-title">Add Slot</div>
			<div id="gdExtraChips" class="gdlo-chips"></div>
			<div class="gdlo-picker-custom">
				<input type="text" id="gdCustomSlotName" placeholder="Custom slot name..." class="gdlo-input gdlo-input--sm" />
				<button type="button" id="gdAddCustomSlot" class="ipsButton ipsButton--small ipsButton--primary">Add</button>
			</div>
		</div>
	</div>

	<div class="gdlo-right">
		<div class="gdlo-sticky">
			<div class="gdlo-panel">
				<div class="gdlo-panel-head">Product Search</div>
				<div class="gdlo-panel-body">
					<input type="text" id="gdSearchInput" placeholder="{lang="gdloadout_builder_search"}" class="gdlo-input" />
				</div>
				<div id="gdSearchResults" class="gdlo-search-results"></div>
			</div>

			<div class="gdlo-panel">
				<div class="gdlo-panel-head">{lang="gdloadout_builder_total_cost"}</div>
				<div class="gdlo-summary-body">
					<div id="gdTotalCost" class="gdlo-total-cost">$0.00</div>
					<div id="gdTotalItems" class="gdlo-total-items">0 items</div>
				</div>
				<div id="gdItemBreakdown" class="gdlo-breakdown"></div>
			</div>

			<div id="gdVipNotes" class="gdlo-panel" style="display:none">
				<div class="gdlo-panel-head">Item Notes (VIP)</div>
				<div id="gdNotesBody" class="gdlo-panel-body"></div>
			</div>

			<div class="gdlo-actions">
				<button type="button" id="gdSaveBtn" class="ipsButton ipsButton--primary gdlo-save-btn">{lang="gdloadout_builder_save"}</button>
				<button type="button" id="gdDeleteBtn" class="ipsButton ipsButton--negative" style="display:none">{lang="gdloadout_builder_delete"}</button>
			</div>
		</div>
	</div>

</div>
</div>
TEMPLATE_EOT;

			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location'=> 'front',
				'template_group'   => 'loadouts',
				'template_name'    => 'builder',
				'template_data'    => '$initData',
				'template_content' => $builderContent,
				'template_updated' => $now,
				'template_version' => $templateVersion,
			] );
		}
		catch ( \Throwable ) {}

		/* -- Template 3: limits (admin/manage) -- */
		try
		{
			$limitsContent = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">
		<h2 style="margin-bottom:16px">{lang="gdloadout_limits_title"}</h2>
		<p style="color:#666;margin-bottom:20px">{lang="gdloadout_limits_unlimited"}</p>
		<form method="post" action="{$saveUrl}">
			<input type="hidden" name="csrfKey" value="{$csrfKey}" />
			<table class="ipsTable ipsTable_zebra" style="width:100%">
				<thead>
					<tr>
						<th>{lang="gdloadout_limits_group"}</th>
						<th style="width:160px">{lang="gdloadout_limits_max_loadouts"}</th>
						<th style="width:160px">{lang="gdloadout_limits_max_slots"}</th>
					</tr>
				</thead>
				<tbody>
					{{foreach $groups as $gid => $gname}}
					<tr>
						<td>{expression="htmlspecialchars($gname)"}</td>
						<td><input type="number" name="group_limits[{expression="(int)$gid"}][max_loadouts]" value="{expression="isset($limits[$gid]) ? (int)$limits[$gid]['max_loadouts'] : 0"}" min="0" style="width:100%;padding:6px 10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" /></td>
						<td><input type="number" name="group_limits[{expression="(int)$gid"}][max_slots]" value="{expression="isset($limits[$gid]) ? (int)$limits[$gid]['max_slots'] : 15"}" min="0" style="width:100%;padding:6px 10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" /></td>
					</tr>
					{{endforeach}}
				</tbody>
			</table>
			<div style="margin-top:16px">
				<button type="submit" class="ipsButton ipsButton--primary">{lang="gdloadout_builder_save"}</button>
			</div>
		</form>
	</div>
</div>
TEMPLATE_EOT;

			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location'=> 'admin',
				'template_group'   => 'manage',
				'template_name'    => 'limits',
				'template_data'    => '$groups, $limits, $csrfKey, $saveUrl',
				'template_content' => $limitsContent,
				'template_updated' => $now,
				'template_version' => $templateVersion,
			] );
		}
		catch ( \Throwable ) {}

		/* -- Template 4: view (front/loadouts) -- */
		try
		{
			$viewContent = <<<'TEMPLATE_EOT'
<script type="application/json" id="gdlo-view-init">{$initData}</script>
<div class="gdlo-view">

	<div class="gdlo-view-header">
		<h1 class="gdlo-view-title">{expression="htmlspecialchars($loadout['name'])"}</h1>
		{{if $loadout['use_case']}}
		<span class="gdlo-view-badge">{expression="htmlspecialchars($loadout['use_case'])"}</span>
		{{endif}}
		<div class="gdlo-view-meta">
			<span>by {expression="htmlspecialchars($ownerName)"}</span>
			<span>{expression="(int)$loadout['view_count']"} views</span>
			<span>{expression="(int)$loadout['total_items']"} items</span>
		</div>
	</div>

	{{if $compliance['has_issues']}}
	<div class="gdlo-compliance gdlo-compliance--warn">
		{{if $compliance['nfa_count'] > 0}}
		<span class="gdlo-compliance-tag"><i class="fa-solid fa-shield"></i> {expression="(int)$compliance['nfa_count']"} NFA Items</span>
		{{endif}}
		{{if $compliance['ffl_count'] > 0}}
		<span class="gdlo-compliance-tag"><i class="fa-solid fa-id-card"></i> {expression="(int)$compliance['ffl_count']"} FFL Required</span>
		{{endif}}
	</div>
	{{else}}
	<div class="gdlo-compliance gdlo-compliance--ok">
		<i class="fa-solid fa-check-circle"></i> {lang="gdloadout_compliance_none"}
	</div>
	{{endif}}

	<div class="gdlo-view-actions">
		<button type="button" id="gdUpvoteBtn" class="ipsButton ipsButton--small" data-voted="{expression="$hasVoted ? '1' : '0'}">
			<i class="fa-solid fa-arrow-up"></i> <span id="gdUpvoteCount">{expression="(int)$loadout['upvotes']"}</span>
		</button>
		<button type="button" id="gdFollowBtn" class="ipsButton ipsButton--small" data-followed="{expression="$hasFollowed ? '1' : '0'}">
			<i class="fa-solid fa-bell"></i> <span id="gdFollowLabel">{expression="$hasFollowed ? 'Following' : 'Follow'"}</span>
		</button>
		{{if $isOwner}}
		<a href="{$editUrl}" class="ipsButton ipsButton--small ipsButton--light"><i class="fa-solid fa-pen"></i> Edit</a>
		{{endif}}
	</div>

	<div class="gdlo-view-items">
		{{foreach $items as $item}}
		<div class="gdlo-view-item">
			<div class="gdlo-view-item-img">
				{{if $item['image_url']}}
				<img src="{expression="htmlspecialchars($item['image_url'])"}" alt="" loading="lazy" />
				{{else}}
				<i class="fa-solid fa-box" style="font-size:2em;color:#ccc"></i>
				{{endif}}
			</div>
			<div class="gdlo-view-item-info">
				<div class="gdlo-view-item-slot">{expression="htmlspecialchars($item['custom_label'] ?? ucwords(str_replace('_',' ',$item['slot_type'])))"}</div>
				<div class="gdlo-view-item-name">{expression="htmlspecialchars($item['product_title'] ?? $item['upc'])"}</div>
				{{if $item['brand']}}
				<div class="gdlo-view-item-brand">{expression="htmlspecialchars($item['brand'])"}</div>
				{{endif}}
				{{if $item['notes']}}
				<div class="gdlo-view-item-notes"><i class="fa-solid fa-sticky-note"></i> {expression="htmlspecialchars($item['notes'])"}</div>
				{{endif}}
			</div>
			<div class="gdlo-view-item-price">
				{{if $item['live_price']}}
				<div class="gdlo-slot-price">${expression="number_format((float)$item['live_price'],2)"}</div>
				{{if $item['active_dealer_count']}}
				<div class="gdlo-search-meta">{expression="(int)$item['active_dealer_count']"} dealers</div>
				{{endif}}
				{{else}}
				<div class="gdlo-search-meta">&mdash;</div>
				{{endif}}
			</div>
			<div class="gdlo-view-item-badges">
				{{if $item['nfa_item']}}
				<span class="gdlo-badge gdlo-badge--nfa">NFA</span>
				{{endif}}
				{{if $item['requires_ffl']}}
				<span class="gdlo-badge gdlo-badge--ffl">FFL</span>
				{{endif}}
			</div>
		</div>
		{{endforeach}}
	</div>

	{{if $loadout['total_min_price']}}
	<div class="gdlo-view-total">
		<span>Est. Total:</span>
		<span class="gdlo-total-cost">${expression="number_format((float)$loadout['total_min_price'],2)"}</span>
	</div>
	{{endif}}

	{{if $loadout['description']}}
	<div class="gdlo-view-desc">
		{expression="nl2br(htmlspecialchars($loadout['description']))"}
	</div>
	{{endif}}

	<div class="gdlo-view-comments">
		<h3 class="gdlo-section-label">Comments ({expression="count($comments)"})</h3>
		<div id="gdCommentsList">
			{{foreach $comments as $c}}
			<div class="gdlo-comment">
				<div class="gdlo-comment-meta">
					<strong>{expression="htmlspecialchars($c['member_name'])"}</strong>
					<span class="gdlo-search-meta">{expression="date('M j, Y', (int)$c['created_at'])"}</span>
				</div>
				<div class="gdlo-comment-text">{expression="nl2br(htmlspecialchars($c['comment']))"}</div>
			</div>
			{{endforeach}}
		</div>

		<div id="gdCommentForm" class="gdlo-comment-form">
			<textarea id="gdCommentInput" class="gdlo-input" rows="2" placeholder="Add a comment..."></textarea>
			<button type="button" id="gdCommentBtn" class="ipsButton ipsButton--primary ipsButton--small" style="margin-top:8px">Post Comment</button>
		</div>
	</div>

</div>
TEMPLATE_EOT;

			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location'=> 'front',
				'template_group'   => 'loadouts',
				'template_name'    => 'view',
				'template_data'    => '$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance, $hasVoted, $hasFollowed, $comments, $initData',
				'template_content' => $viewContent,
				'template_updated' => $now,
				'template_version' => $templateVersion,
			] );
		}
		catch ( \Throwable ) {}

		/* -- Template 5: featured (admin/manage) -- */
		try
		{
			$featuredContent = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">
		<h2 style="margin-bottom:16px">{lang="gdloadout_featured_title"}</h2>
		{{if count($loadouts) > 0}}
		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr>
					<th>ID</th>
					<th>Name</th>
					<th>Owner</th>
					<th>Upvotes</th>
					<th style="width:120px">Featured</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $loadouts as $lo}}
				<tr>
					<td>{expression="(int)$lo['id']"}</td>
					<td>{expression="htmlspecialchars($lo['name'])"}</td>
					<td>{expression="htmlspecialchars($lo['owner_name'])"}</td>
					<td>{expression="(int)$lo['upvotes']"}</td>
					<td>
						<form method="post" action="{$lo['toggle_url']}" style="display:inline">
							<input type="hidden" name="csrfKey" value="{$csrfKey}" />
							{{if $lo['featured']}}
							<button type="submit" class="ipsButton ipsButton--negative ipsButton--verySmall">{lang="gdloadout_featured_unfeature"}</button>
							{{else}}
							<button type="submit" class="ipsButton ipsButton--primary ipsButton--verySmall">{lang="gdloadout_featured_feature"}</button>
							{{endif}}
						</form>
					</td>
				</tr>
				{{endforeach}}
			</tbody>
		</table>
		{{else}}
		<p style="color:#888">{lang="gdloadout_featured_none"}</p>
		{{endif}}
	</div>
</div>
TEMPLATE_EOT;

			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location'=> 'admin',
				'template_group'   => 'manage',
				'template_name'    => 'featured',
				'template_data'    => '$loadouts, $csrfKey',
				'template_content' => $featuredContent,
				'template_updated' => $now,
				'template_version' => $templateVersion,
			] );
		}
		catch ( \Throwable ) {}

		/* ----------------------------------------------------------------
		 * 5. Seed ALL lang strings (combined from all phases)
		 * ---------------------------------------------------------------- */
		$allStrings = [
			/* Original app/nav strings (from upg_10001) */
			'__app_gdloadout'                    => 'Loadouts',
			'frontnavigation_loadouts'           => 'Loadouts',
			'menutab__gdloadout'                 => 'Loadouts',
			'menutab__gdloadout_icon'            => 'layer-group',
			'gdloadout_hub_title'                => 'Loadouts',
			'gdloadout_hub_empty'                => 'No community builds yet — be the first to create a loadout!',
			'menu__gdloadout_loadouts_hub'       => 'Loadouts',
			'menu__gdloadout_manage'             => 'Loadout Settings',
			'menu__gdloadout_manage_limits'      => 'Group Limits',
			'gdloadout_builder_title'            => 'Loadout Builder',
			'gdloadout_builder_name'             => 'Build Name',
			'gdloadout_builder_description'      => 'Description (optional)',
			'gdloadout_builder_use_case'         => 'Use Case',
			'gdloadout_builder_visibility'       => 'Visibility',
			'gdloadout_builder_save'             => 'Save Loadout',
			'gdloadout_builder_delete'           => 'Delete Loadout',
			'gdloadout_builder_search'           => 'Search products...',
			'gdloadout_builder_slot_empty'       => '+ Add',
			'gdloadout_builder_total_cost'       => 'Est. Build Cost',
			'gdloadout_builder_total_items'      => 'Total Items',
			'gdloadout_builder_add_extra'        => '+ Add Extra Slot',
			'gdloadout_builder_remove'           => 'Remove',
			'gdloadout_slot_base_firearm'        => 'Base Firearm',
			'gdloadout_slot_optic'               => 'Optic',
			'gdloadout_slot_weapon_light'        => 'Weapon Light',
			'gdloadout_slot_laser'               => 'Laser',
			'gdloadout_slot_suppressor'          => 'Suppressor',
			'gdloadout_slot_foregrip'            => 'Foregrip',
			'gdloadout_slot_sling'               => 'Sling',
			'gdloadout_slot_holster'             => 'Holster',
			'gdloadout_slot_ammo'                => 'Ammo',
			'gdloadout_slot_cleaning'            => 'Cleaning',
			'gdloadout_slot_extra'               => 'Extra',
			'gdloadout_use_case_home_defense'    => 'Home Defense',
			'gdloadout_use_case_concealed_carry' => 'Concealed Carry',
			'gdloadout_use_case_hunting'         => 'Hunting',
			'gdloadout_use_case_competition'     => 'Competition',
			'gdloadout_use_case_range'           => 'Range',
			'gdloadout_use_case_tactical'        => 'Tactical',
			'gdloadout_use_case_collection'      => 'Collection',
			'gdloadout_vis_public'               => 'Public',
			'gdloadout_vis_unlisted'             => 'Unlisted',
			'gdloadout_vis_private'              => 'Private',
			'gdloadout_limits_title'             => 'Loadout Group Limits',
			'gdloadout_limits_group'             => 'Member Group',
			'gdloadout_limits_max_loadouts'      => 'Max Loadouts',
			'gdloadout_limits_max_slots'         => 'Max Slots per Loadout',
			'gdloadout_limits_unlimited'         => '0 = unlimited',
			'gdloadout_limits_saved'             => 'Group limits saved.',
			'gdloadout_err_limit_loadouts'       => 'You have reached the maximum number of loadouts for your account.',
			'gdloadout_err_limit_slots'          => 'You have reached the maximum number of slots for this loadout.',
			'gdloadout_err_name_required'        => 'Please enter a name for your loadout.',
			'gdloadout_err_not_found'            => 'Loadout not found.',
			'gdloadout_err_no_permission'        => 'You do not have permission to edit this loadout.',
			'gdloadout_saved'                    => 'Loadout saved successfully.',
			'gdloadout_deleted'                  => 'Loadout deleted.',
			'gdloadout_confirm_delete'           => 'Are you sure you want to delete this loadout? This cannot be undone.',
			'r__limits_manage'                   => 'Can manage loadout limits',
			/* Phase 3 strings (from upg_10003) */
			'gdloadout_sec_featured'             => 'Featured Builds',
			'gdloadout_sec_trending'             => 'Trending',
			'gdloadout_sec_toprated'             => 'Top Rated',
			'gdloadout_sec_recent'               => 'Recently Updated',
			'gdloadout_sec_budget'               => 'Budget Builds',
			'gdloadout_upvote'                   => 'Upvote',
			'gdloadout_follow'                   => 'Follow',
			'gdloadout_following'                => 'Following',
			'gdloadout_share'                    => 'Share',
			'gdloadout_compliance_none'          => 'No compliance issues detected for your state',
			'gdloadout_nfa'                      => 'NFA Item',
			'gdloadout_ffl'                      => 'FFL Required',
			'gdloadout_state_restricted'         => 'State Restricted',
			'gdloadout_comments'                 => 'Comments',
			'gdloadout_comment_placeholder'      => 'Add a comment...',
			'gdloadout_comment_post'             => 'Post Comment',
			'gdloadout_login_required'           => 'Please log in to perform this action.',
			'gdloadout_view_by'                  => 'by',
			'gdloadout_view_views'               => 'views',
			'gdloadout_view_items'               => 'items',
			'gdloadout_view_dealers'             => 'dealers',
			'gdloadout_view_est_total'           => 'Est. Total',
			'gdloadout_filter_use_case'          => 'Use Case',
			'gdloadout_filter_all'               => 'All',
			'menu__gdloadout_manage_featured'    => 'Featured Builds',
			'r__featured_manage'                 => 'Manage featured builds',
			'gdloadout_featured_title'           => 'Featured Builds',
			'gdloadout_featured_none'            => 'No loadouts to display.',
			'gdloadout_featured_feature'         => 'Feature',
			'gdloadout_featured_unfeature'       => 'Unfeature',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $allStrings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdloadout',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		/* ----------------------------------------------------------------
		 * 6. Rebuild front nav
		 * ---------------------------------------------------------------- */
		try { \IPS\core\FrontNavigation::buildDefaultFrontNavigation(); } catch ( \Throwable ) {}

		/* ----------------------------------------------------------------
		 * 7. Self-heal extensions.json
		 * ---------------------------------------------------------------- */
		try
		{
			$extPath = \IPS\ROOT_PATH . '/applications/gdloadout/data/extensions.json';
			$extData = [];
			if ( file_exists( $extPath ) )
			{
				$extData = json_decode( file_get_contents( $extPath ), TRUE ) ?: [];
			}

			$dirty = FALSE;

			if ( !isset( $extData['FrontNavigation']['Loadouts'] ) || $extData['FrontNavigation']['Loadouts'] !== 'IPS\\gdloadout\\extensions\\core\\FrontNavigation\\Loadouts' )
			{
				$extData['FrontNavigation']['Loadouts'] = 'IPS\\gdloadout\\extensions\\core\\FrontNavigation\\Loadouts';
				$dirty = TRUE;
			}

			if ( !isset( $extData['Sitemap']['Loadouts'] ) || $extData['Sitemap']['Loadouts'] !== 'IPS\\gdloadout\\extensions\\core\\Sitemap\\Loadouts' )
			{
				$extData['Sitemap']['Loadouts'] = 'IPS\\gdloadout\\extensions\\core\\Sitemap\\Loadouts';
				$dirty = TRUE;
			}

			if ( $dirty )
			{
				file_put_contents( $extPath, json_encode( $extData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			}
		}
		catch ( \Throwable ) {}

		/* ----------------------------------------------------------------
		 * 8. Cache busting
		 * ---------------------------------------------------------------- */
		try { unset( \IPS\Data\Store::i()->extensions ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }    catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->frontNavigation ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                catch ( \Throwable ) {}

		/* ----------------------------------------------------------------
		 * 9. Done
		 * ---------------------------------------------------------------- */
		return TRUE;
	}
}

class upgrade extends _upgrade {}
