<?php

namespace IPS\gdloadout\setup\upg_10016;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		// Seed gdloadout_share_forum setting (idempotent)
		try
		{
			$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_sys_conf_settings', [ 'conf_key=?', 'gdloadout_share_forum' ] )->first();
			if ( $exists === 0 )
			{
				\IPS\Db::i()->insert( 'core_sys_conf_settings', [
					'conf_key'     => 'gdloadout_share_forum',
					'conf_value'   => '0',
					'conf_default' => '0',
					'conf_app'     => 'gdloadout',
				] );
			}
		}
		catch ( \Throwable ) {}

		// Seed all lang strings accumulated through v1.0.16
		$newStrings = [
			'menutab__gdloadout'               => 'Loadouts',
			'gdloadout_share_forum'            => 'Loadout Share Forum',
			'gdloadout_share_forum_desc'       => 'Select the forum where loadout shares will be posted. If no forum is selected, the Share to Forum button will be hidden.',
			'gdloadout_share_to_forum'         => 'Share to Forum',
			'gdloadout_view_discussion'        => 'View Discussion',
			'gdloadout_already_shared'         => 'This loadout has already been shared to the forum',
			'gdloadout_forums_not_configured'  => 'Forum sharing is not available',
			'gdloadout_share_rate_limited'     => 'Please wait before sharing another loadout',
			'gdloadout_settings'               => 'Settings',
			'menu__gdloadout_manage_settings'  => 'Settings',
			'gdloadout_share_forum_none'       => 'No forum selected — sharing disabled',
			'gdloadout_shared_success'         => 'Loadout shared to forum',
			'menu__gdloadout'                  => 'Loadouts',
			'menu__gdloadout_manage_limits'    => 'Group Limits',
			'menu__gdloadout_manage_featured'  => 'Featured Loadouts',
			'r__limits_manage'                 => 'Manage group limits',
			'r__featured_manage'               => 'Manage featured loadouts',
			'r__settings_manage'               => 'Manage settings',
			'gdloadout_featured_title'         => 'Featured Loadouts',
			'gdloadout_manage'                 => 'Loadouts',
			'menu__gdloadout_manage'           => 'Loadouts',
			'gdloadout_limits_title'           => 'Loadout Group Limits',
			'gdloadout_limits_unlimited'       => 'Set to 0 for unlimited.',
			'gdloadout_limits_group'           => 'Group',
			'gdloadout_limits_max_loadouts'    => 'Max Loadouts',
			'gdloadout_limits_max_slots'       => 'Max Slots',
			'gdloadout_limits_saved'           => 'Group limits saved.',
			'gdloadout_modal_search'           => 'Search by name, UPC, or MPN...',
			'gdloadout_modal_no_products'      => 'No products in this category yet — try searching by name or UPC/MPN.',
			'gdloadout_modal_all'              => 'All',
			// v1.0.16 — sort toggle, pagination, no-price flag
			'gdloadout_modal_sort_relevance'   => 'Relevance',
			'gdloadout_modal_sort_name'        => 'Name (A–Z)',
			'gdloadout_modal_load_more'        => 'Load more',
			'gdloadout_modal_no_price'         => 'No price yet',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
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

		// Reseed builder template (with sort toggle + load more + no-price)
		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id' => 1,
				'template_app' => 'gdloadout',
				'template_location' => 'front',
				'template_group' => 'loadouts',
				'template_name' => 'builder',
				'template_data' => '$initData',
				'template_content' => <<<'TEMPLATE_EOT'
<script type="application/json" id="gdlo-init">{$initData|raw}</script>
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

		<div id="gdHeroSlot" class="gdlo-hero-card">
			<div class="gdlo-hero-label">{lang="gdloadout_slot_base_firearm"}</div>
			<div class="gdlo-hero-empty">Select your base firearm</div>
		</div>

		<div class="gdlo-section-label">Core Slots <span id="gdSlotCount" style="font-weight:400;color:#64748b"></span></div>
		<div id="gdSlotGrid" class="gdlo-slot-grid"></div>

		<div class="gdlo-section-label">Extras</div>
		<div id="gdExtraSlots" class="gdlo-slot-grid"></div>
		<div id="gdExtraPicker" class="gdlo-picker">
			<div id="gdExtraChips" class="gdlo-chips"></div>
			<div class="gdlo-picker-custom" id="gdCustomWrap" style="display:none">
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

<div id="gdSlotModal" class="gdlo-modal" style="display:none" role="dialog" aria-modal="true">
  <div class="gdlo-modal-backdrop" data-close="1"></div>
  <div class="gdlo-modal-panel">
    <div class="gdlo-modal-head">
      <h3 id="gdModalTitle" class="gdlo-modal-title"></h3>
      <button type="button" class="gdlo-modal-close" data-close="1" aria-label="Close">&times;</button>
    </div>
    <div id="gdModalTypes" class="gdlo-modal-types"></div>
    <div id="gdModalSubtypes" class="gdlo-modal-subtypes"></div>
    <div id="gdModalSort" class="gdlo-modal-sort"></div>
    <div class="gdlo-modal-search">
      <input type="text" id="gdModalSearch" class="gdlo-input" placeholder="{lang="gdloadout_modal_search"}" />
    </div>
    <div id="gdModalResults" class="gdlo-modal-results"></div>
    <button type="button" id="gdModalLoadMore" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" style="display:none;margin:0 20px 16px">{lang="gdloadout_modal_load_more"}</button>
    <div id="gdModalEmpty" class="gdlo-modal-empty" style="display:none">{lang="gdloadout_modal_no_products"}</div>
  </div>
</div>
TEMPLATE_EOT,
				'template_updated' => time(),
				'template_version' => '1.0.16',
				'template_master_key' => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		// Reseed view template
		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id' => 1,
				'template_app' => 'gdloadout',
				'template_location' => 'front',
				'template_group' => 'loadouts',
				'template_name' => 'view',
				'template_data' => '$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance, $hasVoted, $hasFollowed, $comments, $initData, $canShareForum, $forumTopicUrl',
				'template_content' => <<<'TEMPLATE_EOT'
<div class="gdlo-view" id="gdloView" data-init='{$initData}'>
    <div class="gdlo-view-header">
        <div class="gdlo-view-header-info">
            <h1 class="gdlo-view-title">{$loadout['name']}</h1>
            <div class="gdlo-view-meta">
                <span class="gdlo-view-author"><i class="fa-solid fa-user"></i> {$ownerName}</span>
                {{if $loadout['use_case']}}<span class="gdlo-view-uc">{$loadout['use_case']}</span>{{endif}}
                {{if $loadout['visibility'] === 'private'}}<span class="gdlo-view-badge gdlo-view-badge--private"><i class="fa-solid fa-lock"></i> Private</span>{{endif}}
                {{if $loadout['visibility'] === 'unlisted'}}<span class="gdlo-view-badge gdlo-view-badge--unlisted"><i class="fa-solid fa-eye-slash"></i> Unlisted</span>{{endif}}
            </div>
            {{if $loadout['description']}}<p class="gdlo-view-desc">{$loadout['description']}</p>{{endif}}
        </div>
        <div class="gdlo-view-header-actions">
            {{if $isOwner}}<a href="{$editUrl}" class="gdlo-btn gdlo-btn--secondary"><i class="fa-solid fa-pen"></i> Edit</a>{{endif}}
        </div>
    </div>
    <div class="gdlo-view-2col">
        <div class="gdlo-view-main">
            <div class="gdlo-view-items">
                {{if count($items) === 0}}
                <div class="gdlo-view-empty"><i class="fa-solid fa-box-open"></i><p>{lang="gdloadout_no_items"}</p></div>
                {{endif}}
                {{foreach $items as $item}}
                <div class="gdlo-view-item">
                    <div class="gdlo-view-item-icon">{{if $item['image_url']}}<img src="{$item['image_url']}" alt="" class="gdlo-view-item-img" loading="lazy" />{{else}}<i class="fa-solid fa-cube"></i>{{endif}}</div>
                    <div class="gdlo-view-item-info">
                        <div class="gdlo-view-item-title">{{if $item['product_title']}}{$item['product_title']}{{else}}{$item['upc']}{{endif}}</div>
                        <div class="gdlo-view-item-sub"><span class="gdlo-view-item-slot">{$item['slot_type']}</span>{{if $item['brand']}}<span class="gdlo-view-item-brand">{$item['brand']}</span>{{endif}}{{if $item['caliber']}}<span class="gdlo-view-item-caliber">{$item['caliber']}</span>{{endif}}</div>
                        {{if $item['custom_label']}}<div class="gdlo-view-item-label">{$item['custom_label']}</div>{{endif}}
                        {{if $item['notes']}}<div class="gdlo-view-item-notes">{$item['notes']}</div>{{endif}}
                    </div>
                    <div class="gdlo-view-item-price">
                        {{if $item['live_price']}}<span class="gdlo-view-item-price-val">{expression="'$' . number_format((float)$item['live_price'], 2)"}</span><span class="gdlo-view-item-dealers">{expression="(int)$item['active_dealer_count']"} {lang="gdloadout_dealers"}</span>{{else}}<span class="gdlo-view-item-price-na">{lang="gdloadout_no_price"}</span>{{endif}}
                    </div>
                </div>
                {{endforeach}}
            </div>
            <div class="gdlo-view-comments" id="gdloComments">
                <h3 class="gdlo-view-comments-title"><i class="fa-solid fa-comments"></i> {lang="gdloadout_comments"} ({$loadout['comment_count']})</h3>
                <div class="gdlo-view-comments-list" id="gdloCommentList">
                    {{foreach $comments as $c}}
                    <div class="gdlo-view-comment"><strong class="gdlo-view-comment-author">{$c['member_name']}</strong><p class="gdlo-view-comment-body">{$c['comment']}</p></div>
                    {{endforeach}}
                </div>
                <div class="gdlo-view-comment-form" id="gdloCommentForm">
                    <textarea id="gdloCommentText" class="gdlo-input" placeholder="{lang="gdloadout_add_comment"}" rows="2"></textarea>
                    <button type="button" class="gdlo-btn gdlo-btn--primary gdlo-btn--sm" id="gdloCommentSubmit">{lang="gdloadout_post_comment"}</button>
                </div>
            </div>
        </div>
        <div class="gdlo-view-sidebar">
            <div class="gdlo-view-summary">
                <h3 class="gdlo-view-summary-title">{lang="gdloadout_build_summary"}</h3>
                <div class="gdlo-view-summary-stats">
                    <div class="gdlo-view-summary-stat"><span class="gdlo-view-summary-stat-val">{$loadout['total_items']}</span><span class="gdlo-view-summary-stat-lbl">{lang="gdloadout_items"}</span></div>
                    {{if $loadout['total_min_price'] > 0}}<div class="gdlo-view-summary-stat"><span class="gdlo-view-summary-stat-val">{expression="'$' . number_format((float)$loadout['total_min_price'], 2)"}</span><span class="gdlo-view-summary-stat-lbl">{lang="gdloadout_est_cost"}</span></div>{{endif}}
                </div>
                <div class="gdlo-view-summary-actions">
                    <button type="button" class="gdlo-btn gdlo-btn--vote{{if $hasVoted}} gdlo-btn--voted{{endif}}" id="gdloUpvoteBtn"><i class="fa-solid fa-heart"></i> <span id="gdloUpvoteCount">{$loadout['upvotes']}</span></button>
                    <button type="button" class="gdlo-btn gdlo-btn--follow{{if $hasFollowed}} gdlo-btn--followed{{endif}}" id="gdloFollowBtn"><i class="fa-solid fa-bell"></i> <span id="gdloFollowCount">{$loadout['follow_count']}</span></button>
                </div>
                <div class="gdlo-view-summary-extras">
                    <button type="button" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" id="gdloWishlistBtn"><i class="fa-solid fa-bookmark"></i> {lang="gdloadout_add_all_wishlist"}</button>
                    <button type="button" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" id="gdloAlertBtn"><i class="fa-solid fa-bell"></i> {lang="gdloadout_alert_all"}</button>
                    {{if $forumTopicUrl}}
                    <a href="{$forumTopicUrl}" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" target="_blank"><i class="fa-solid fa-comments"></i> {lang="gdloadout_view_discussion"}</a>
                    {{else}}
                        {{if $canShareForum}}
                    <button type="button" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" id="gdloShareForumBtn"><i class="fa-solid fa-share"></i> {lang="gdloadout_share_to_forum"}</button>
                        {{endif}}
                    {{endif}}
                </div>
                {{if $compliance['has_issues']}}
                <div class="gdlo-view-compliance">
                    <h4><i class="fa-solid fa-triangle-exclamation"></i> {lang="gdloadout_compliance"}</h4>
                    {{if $compliance['nfa_count'] > 0}}<p>{expression="(int)$compliance['nfa_count']"} NFA item(s) — requires tax stamp</p>{{endif}}
                    {{if $compliance['ffl_count'] > 0}}<p>{expression="(int)$compliance['ffl_count']"} item(s) require FFL transfer</p>{{endif}}
                </div>
                {{endif}}
                <div class="gdlo-view-summary-share"><span class="gdlo-view-views"><i class="fa-solid fa-eye"></i> {$loadout['view_count']} views</span></div>
            </div>
        </div>
    </div>
</div>
TEMPLATE_EOT,
				'template_updated' => time(),
				'template_version' => '1.0.16',
				'template_master_key' => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		// Seed loadoutEmbed template
		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id' => 1,
				'template_app' => 'gdloadout',
				'template_location' => 'front',
				'template_group' => 'loadouts',
				'template_name' => 'loadoutEmbed',
				'template_data' => '$loadout, $ownerName, $viewUrl',
				'template_content' => <<<'TEMPLATE_EOT'
<div class="gdlo-embed" style="border:1px solid #e2e8f0;border-radius:8px;padding:16px;max-width:480px;font-family:system-ui,sans-serif;background:#fff;">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
        <i class="fa-solid fa-layer-group" style="color:#2563eb;font-size:18px;"></i>
        <a href="{$viewUrl}" style="font-weight:700;font-size:16px;color:#1e293b;text-decoration:none;">{$loadout['name']}</a>
    </div>
    {{if $loadout['description']}}
    <p style="color:#64748b;font-size:13px;margin:0 0 8px 0;">{$loadout['description']}</p>
    {{endif}}
    <div style="display:flex;gap:12px;font-size:13px;color:#475569;">
        <span><i class="fa-solid fa-user"></i> {$ownerName}</span>
        <span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']} items</span>
        {{if $loadout['total_min_price'] > 0}}
        <span><i class="fa-solid fa-tag"></i> {expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>
        {{endif}}
        <span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span>
    </div>
    {{if $loadout['use_case']}}
    <div style="margin-top:8px;"><span style="background:#eff6ff;color:#2563eb;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;">{$loadout['use_case']}</span></div>
    {{endif}}
</div>
TEMPLATE_EOT,
				'template_updated' => time(),
				'template_version' => '1.0.16',
				'template_master_key' => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		// Reseed limits template (editable form)
		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id' => 1,
				'template_app' => 'gdloadout',
				'template_location' => 'admin',
				'template_group' => 'manage',
				'template_name' => 'limits',
				'template_data' => '$groups, $limits, $csrfKey, $saveUrl',
				'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">
		<h2 style="margin-bottom:16px">{lang="gdloadout_limits_title"}</h2>
		<p style="color:#666;margin-bottom:20px">{lang="gdloadout_limits_unlimited"}</p>
		<form method="post" action="{$saveUrl}">
			<input type="hidden" name="csrfKey" value="{$csrfKey}" />
			<table class="ipsTable ipsTable_zebra" style="width:100%">
				<thead><tr>
					<th>{lang="gdloadout_limits_group"}</th>
					<th style="width:160px">{lang="gdloadout_limits_max_loadouts"}</th>
					<th style="width:160px">{lang="gdloadout_limits_max_slots"}</th>
				</tr></thead>
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
			<div style="margin-top:16px"><button type="submit" class="ipsButton ipsButton--primary">{lang="gdloadout_builder_save"}</button></div>
		</form>
	</div>
</div>
TEMPLATE_EOT,
				'template_updated' => time(),
				'template_version' => '1.0.16',
				'template_master_key' => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		// Clear caches
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }     catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
