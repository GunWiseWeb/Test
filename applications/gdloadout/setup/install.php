<?php

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

$templates = [];

// Template: hub
$templates[] = [
	'template_set_id' => 1,
	'template_app' => 'gdloadout',
	'template_location' => 'front',
	'template_group' => 'loadouts',
	'template_name' => 'hub',
	'template_data' => '$sections, $canCreate, $builderUrl, $activeUseCase, $useCases, $myLoadoutsUrl',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="gdlo-hub">
    <div class="gdlo-hub-hero">
        <div class="gdlo-hub-hero-text">
            <h1 class="gdlo-hub-title">{lang="gdloadout_hub_title"}</h1>
            <p class="gdlo-hub-subtitle">{lang="gdloadout_hub_subtitle"}</p>
        </div>
        {{if $canCreate}}
        <a href="{$builderUrl}" class="gdlo-btn gdlo-btn--primary"><i class="fa-solid fa-plus"></i> {lang="gdloadout_new_loadout"}</a>
        {{endif}}
    </div>
    <div class="gdlo-hub-tabs">
        <a href="{expression="\IPS\Http\Url::internal('app=gdloadout&module=loadouts&controller=hub', 'front', 'gdloadout_hub')"}" class="gdlo-hub-tab gdlo-hub-tab--active">{lang="gdloadout_browse_all"}</a>
        <a href="{$myLoadoutsUrl}" class="gdlo-hub-tab">{lang="gdloadout_my_loadouts"}</a>
    </div>
    <div class="gdlo-hub-filters">
        <a href="{expression="\IPS\Http\Url::internal('app=gdloadout&module=loadouts&controller=hub', 'front', 'gdloadout_hub')"}" class="gdlo-hub-pill{{if $activeUseCase === ''}} gdlo-hub-pill--active{{endif}}">All</a>
        {{foreach $useCases as $uc}}
        <a href="{expression="\IPS\Http\Url::internal('app=gdloadout&module=loadouts&controller=hub&use_case=' . urlencode($uc), 'front', 'gdloadout_hub')"}" class="gdlo-hub-pill{{if $activeUseCase === $uc}} gdlo-hub-pill--active{{endif}}">{$uc}</a>
        {{endforeach}}
    </div>
    {{if count($sections['featured']) > 0}}
    <div class="gdlo-hub-section">
        <h2 class="gdlo-hub-section-title"><i class="fa-solid fa-star"></i> {lang="gdloadout_featured"}</h2>
        <div class="gdlo-hub-grid">
            {{foreach $sections['featured'] as $loadout}}
            <a href="{$loadout['view_url']}" class="gdlo-hub-card gdlo-hub-card--featured">
                <div class="gdlo-hub-card-name">{$loadout['name']}</div>
                <div class="gdlo-hub-card-meta"><span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>{{if $loadout['use_case']}}<span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>{{endif}}</div>
                <div class="gdlo-hub-card-stats"><span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span><span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span><span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>{{if $loadout['total_min_price'] > 0}}<span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>{{endif}}</div>
            </a>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
    {{if count($sections['trending']) > 0}}
    <div class="gdlo-hub-section">
        <h2 class="gdlo-hub-section-title"><i class="fa-solid fa-fire"></i> {lang="gdloadout_trending"}</h2>
        <div class="gdlo-hub-grid">
            {{foreach $sections['trending'] as $loadout}}
            <a href="{$loadout['view_url']}" class="gdlo-hub-card">
                <div class="gdlo-hub-card-name">{$loadout['name']}</div>
                <div class="gdlo-hub-card-meta"><span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>{{if $loadout['use_case']}}<span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>{{endif}}</div>
                <div class="gdlo-hub-card-stats"><span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span><span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span><span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>{{if $loadout['total_min_price'] > 0}}<span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>{{endif}}</div>
            </a>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
    {{if count($sections['top_rated']) > 0}}
    <div class="gdlo-hub-section">
        <h2 class="gdlo-hub-section-title"><i class="fa-solid fa-trophy"></i> {lang="gdloadout_top_rated"}</h2>
        <div class="gdlo-hub-grid">
            {{foreach $sections['top_rated'] as $loadout}}
            <a href="{$loadout['view_url']}" class="gdlo-hub-card">
                <div class="gdlo-hub-card-name">{$loadout['name']}</div>
                <div class="gdlo-hub-card-meta"><span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>{{if $loadout['use_case']}}<span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>{{endif}}</div>
                <div class="gdlo-hub-card-stats"><span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span><span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span><span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>{{if $loadout['total_min_price'] > 0}}<span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>{{endif}}</div>
            </a>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
    {{if count($sections['recent']) > 0}}
    <div class="gdlo-hub-section">
        <h2 class="gdlo-hub-section-title"><i class="fa-solid fa-clock"></i> {lang="gdloadout_recent"}</h2>
        <div class="gdlo-hub-grid">
            {{foreach $sections['recent'] as $loadout}}
            <a href="{$loadout['view_url']}" class="gdlo-hub-card">
                <div class="gdlo-hub-card-name">{$loadout['name']}</div>
                <div class="gdlo-hub-card-meta"><span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>{{if $loadout['use_case']}}<span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>{{endif}}</div>
                <div class="gdlo-hub-card-stats"><span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span><span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span><span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>{{if $loadout['total_min_price'] > 0}}<span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>{{endif}}</div>
            </a>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
    {{if count($sections['budget']) > 0}}
    <div class="gdlo-hub-section">
        <h2 class="gdlo-hub-section-title"><i class="fa-solid fa-piggy-bank"></i> {lang="gdloadout_budget"}</h2>
        <div class="gdlo-hub-grid">
            {{foreach $sections['budget'] as $loadout}}
            <a href="{$loadout['view_url']}" class="gdlo-hub-card">
                <div class="gdlo-hub-card-name">{$loadout['name']}</div>
                <div class="gdlo-hub-card-meta"><span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>{{if $loadout['use_case']}}<span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>{{endif}}</div>
                <div class="gdlo-hub-card-stats"><span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span><span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span><span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>{{if $loadout['total_min_price'] > 0}}<span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>{{endif}}</div>
            </a>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
</div>
TEMPLATE_EOT,
	'template_updated' => time(),
	'template_version' => '1.0.16',
	'template_master_key' => '',
	'template_has_hookpoints' => 0,
];

// Template: view
$templates[] = [
	'template_set_id' => 1,
	'template_app' => 'gdloadout',
	'template_location' => 'front',
	'template_group' => 'loadouts',
	'template_name' => 'view',
	'template_data' => '$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance, $hasVoted, $hasFollowed, $comments, $initData',
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
];

// Template: myLoadouts
$templates[] = [
	'template_set_id' => 1,
	'template_app' => 'gdloadout',
	'template_location' => 'front',
	'template_group' => 'loadouts',
	'template_name' => 'myLoadouts',
	'template_data' => '$loadouts, $builderUrl',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="gdlo-hub">
    <div class="gdlo-hub-hero">
        <div class="gdlo-hub-hero-text">
            <h1 class="gdlo-hub-title">{lang="gdloadout_my_loadouts_title"}</h1>
            <p class="gdlo-hub-subtitle">{lang="gdloadout_my_loadouts_subtitle"}</p>
        </div>
        <a href="{$builderUrl}" class="gdlo-btn gdlo-btn--primary"><i class="fa-solid fa-plus"></i> {lang="gdloadout_new_loadout"}</a>
    </div>
    {{if count($loadouts) === 0}}
    <div class="gdlo-empty"><i class="fa-solid fa-layer-group"></i><h3>{lang="gdloadout_no_loadouts_yet"}</h3><p>{lang="gdloadout_no_loadouts_desc"}</p><a href="{$builderUrl}" class="gdlo-btn gdlo-btn--primary">{lang="gdloadout_create_first"}</a></div>
    {{else}}
    <div class="gdlo-hub-grid">
        {{foreach $loadouts as $loadout}}
        <div class="gdlo-hub-card">
            <a href="{$loadout['view_url']}" class="gdlo-hub-card-link">
                <div class="gdlo-hub-card-name">{$loadout['name']}</div>
                <div class="gdlo-hub-card-meta">{{if $loadout['use_case']}}<span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>{{endif}}{{if $loadout['visibility'] === 'private'}}<span class="gdlo-hub-card-badge"><i class="fa-solid fa-lock"></i></span>{{endif}}{{if $loadout['visibility'] === 'unlisted'}}<span class="gdlo-hub-card-badge"><i class="fa-solid fa-eye-slash"></i></span>{{endif}}</div>
                <div class="gdlo-hub-card-stats"><span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span><span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span><span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>{{if $loadout['total_min_price'] > 0}}<span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>{{endif}}</div>
            </a>
            <div class="gdlo-hub-card-actions"><a href="{$loadout['edit_url']}" class="gdlo-btn gdlo-btn--sm gdlo-btn--secondary"><i class="fa-solid fa-pen"></i> Edit</a></div>
        </div>
        {{endforeach}}
    </div>
    {{endif}}
</div>
TEMPLATE_EOT,
	'template_updated' => time(),
	'template_version' => '1.0.16',
	'template_master_key' => '',
	'template_has_hookpoints' => 0,
];

// Template: builder
$templates[] = [
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
TEMPLATE_EOT,
	'template_updated' => time(),
	'template_version' => '1.0.16',
	'template_master_key' => '',
	'template_has_hookpoints' => 0,
];

// Template: trendingLoadouts (widget)
$templates[] = [
	'template_set_id' => 1,
	'template_app' => 'gdloadout',
	'template_location' => 'front',
	'template_group' => 'widgets',
	'template_name' => 'trendingLoadouts',
	'template_data' => '$loadouts',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="gdlo-widget">
    <h3 class="gdlo-widget-title"><i class="fa-solid fa-fire"></i> {lang="gdloadout_trending"}</h3>
    {{if count($loadouts) > 0}}
    <div class="gdlo-widget-list">
        {{foreach $loadouts as $loadout}}
        <a href="{$loadout['view_url']}" class="gdlo-widget-item"><span class="gdlo-widget-item-name">{$loadout['name']}</span><span class="gdlo-widget-item-meta"><span class="gdlo-widget-item-author">{$loadout['owner_name']}</span><span class="gdlo-widget-item-votes"><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span></span></a>
        {{endforeach}}
    </div>
    {{else}}
    <p class="gdlo-widget-empty">{lang="gdloadout_no_trending"}</p>
    {{endif}}
</div>
TEMPLATE_EOT,
	'template_updated' => time(),
	'template_version' => '1.0.16',
	'template_master_key' => '',
	'template_has_hookpoints' => 0,
];

// Template: limits (admin)
$templates[] = [
	'template_set_id' => 1,
	'template_app' => 'gdloadout',
	'template_location' => 'admin',
	'template_group' => 'manage',
	'template_name' => 'limits',
	'template_data' => '$limits',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox">
    <h2 class="ipsBox_title">Loadout Group Limits</h2>
    <table class="ipsTable ipsTable_zebra"><thead><tr><th>Group</th><th>Max Loadouts</th><th>Max Slots</th></tr></thead><tbody>{{foreach $limits as $limit}}<tr><td>{$limit['group_name']}</td><td>{$limit['max_loadouts']}</td><td>{$limit['max_slots']}</td></tr>{{endforeach}}</tbody></table>
</div>
TEMPLATE_EOT,
	'template_updated' => time(),
	'template_version' => '1.0.16',
	'template_master_key' => '',
	'template_has_hookpoints' => 0,
];

// Template: featured (admin)
$templates[] = [
	'template_set_id' => 1,
	'template_app' => 'gdloadout',
	'template_location' => 'admin',
	'template_group' => 'manage',
	'template_name' => 'featured',
	'template_data' => '$featured',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="ipsBox">
    <h2 class="ipsBox_title">Featured Loadouts</h2>
    {{if count($featured) === 0}}<p class="ipsMessage ipsMessage_info">No featured loadouts configured.</p>{{else}}
    <table class="ipsTable ipsTable_zebra"><thead><tr><th>Position</th><th>Loadout</th><th>Owner</th></tr></thead><tbody>{{foreach $featured as $f}}<tr><td>{$f['featured_position']}</td><td>{$f['name']}</td><td>{$f['owner_name']}</td></tr>{{endforeach}}</tbody></table>
    {{endif}}
</div>
TEMPLATE_EOT,
	'template_updated' => time(),
	'template_version' => '1.0.16',
	'template_master_key' => '',
	'template_has_hookpoints' => 0,
];

// Seed templates
foreach ( $templates as $tpl )
{
	try
	{
		\IPS\Db::i()->replace( 'core_theme_templates', $tpl );
	}
	catch ( \Throwable ) {}
}

// Notification defaults
$notifDefaults = [
	[ 'notification_app' => 'gdloadout', 'notification_key' => 'loadout_updated', 'default' => '["inline"]' ],
	[ 'notification_app' => 'gdloadout', 'notification_key' => 'loadout_upvoted', 'default' => '["inline"]' ],
	[ 'notification_app' => 'gdloadout', 'notification_key' => 'loadout_followed', 'default' => '["inline"]' ],
];

foreach ( $notifDefaults as $nd )
{
	try
	{
		\IPS\Db::i()->replace( 'core_notification_defaults', $nd );
	}
	catch ( \Throwable ) {}
}

// Seed lang strings from data/lang.xml into core_sys_lang_words (6-col schema #43)
$langStrings = [];
try
{
	$langXmlPath = \IPS\ROOT_PATH . '/applications/gdloadout/data/lang.xml';
	if ( file_exists( $langXmlPath ) )
	{
		$xml = new \XMLReader();
		$xml->open( $langXmlPath );
		while ( $xml->read() )
		{
			if ( $xml->nodeType === \XMLReader::ELEMENT && $xml->name === 'word' )
			{
				$key = $xml->getAttribute( 'key' );
				$xml->read();
				$val = $xml->value;
				if ( $key !== null && $key !== '' )
				{
					$langStrings[ $key ] = $val;
				}
			}
		}
		$xml->close();
	}
}
catch ( \Throwable ) {}

if ( $langStrings )
{
	try
	{
		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $langStrings as $wKey => $wVal )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'      => (int) $langId,
						'word_app'     => 'gdloadout',
						'word_key'     => $wKey,
						'word_default' => $wVal,
						'word_js'      => 0,
						'word_export'  => 1,
					] );
				}
				catch ( \Throwable ) {}
			}
		}
	}
	catch ( \Throwable ) {}
}

// Seed default group limits for every group that doesn't already have one
try
{
	foreach ( \IPS\Member\Group::groups( TRUE, FALSE ) as $group )
	{
		$gid = (int) $group->g_id;
		try
		{
			$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_loadout_group_limits', [ 'group_id=?', $gid ] )->first();
			if ( $exists === 0 )
			{
				\IPS\Db::i()->insert( 'gd_loadout_group_limits', [
					'group_id'     => $gid,
					'max_loadouts' => 0,
					'max_slots'    => 15,
				] );
			}
		}
		catch ( \Throwable ) {}
	}
}
catch ( \Throwable ) {}

