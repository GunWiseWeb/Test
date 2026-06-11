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
	'template_data' => '$sections, $canCreate, $builderUrl, $activeUseCase, $useCases, $myLoadoutsUrl, $copyUrl, $csrfKey',
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
            <div class="gdlo-hub-card gdlo-hub-card--featured">
                <div class="gdlo-hub-card-img">{{if $loadout['base_image']}}<img src="{$loadout['base_image']}" alt="{$loadout['base_title']}" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" /><div class="gdlo-hub-card-noimg" style="display:none"><i class="fa-solid fa-gun"></i></div>{{else}}<div class="gdlo-hub-card-noimg"><i class="fa-solid fa-gun"></i></div>{{endif}}</div>
                <div class="gdlo-hub-card-body"><a href="{$loadout['view_url']}" class="gdlo-hub-card-name">{$loadout['name']}</a><div class="gdlo-hub-card-meta"><span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>{{if $loadout['mode_label']}}<span class="gdlo-hub-tag">{$loadout['mode_label']}</span>{{endif}}{{if $loadout['platform']}}<span class="gdlo-hub-tag">{$loadout['platform']}</span>{{endif}}</div><div class="gdlo-hub-price">{{if $loadout['total_min_price'] > 0}}<span class="gdlo-hub-price-val">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>{{else}}<span class="gdlo-hub-price-na">{lang="gdloadout_no_price_yet"}</span>{{endif}}<span class="gdlo-hub-price-parts">{$loadout['total_items']} {lang="gdloadout_parts"}</span></div><div class="gdlo-hub-card-stats"><span aria-label="{lang="gdloadout_upvote_loadout"}"><i class="fa-solid fa-heart" aria-hidden="true"></i> {$loadout['upvotes']}</span><span aria-label="{lang="gdloadout_follow_loadout"}"><i class="fa-solid fa-bell" aria-hidden="true"></i> {$loadout['follow_count']}</span></div></div>
                <div class="gdlo-hub-actions"><a href="{$loadout['view_url']}" class="gdlo-hub-btn gdlo-hub-btn--view"><i class="fa-solid fa-eye"></i> {lang="gdloadout_view_build"}</a><form method="post" action="{$copyUrl}" class="gdlo-hub-copy-form"><input type="hidden" name="csrfKey" value="{$csrfKey}" /><input type="hidden" name="loadout_id" value="{$loadout['id']}" /><button type="submit" class="gdlo-hub-btn gdlo-hub-btn--copy"><i class="fa-solid fa-copy"></i> {lang="gdloadout_copy_build"}</button></form></div>
            </div>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
    {{if count($sections['trending']) > 0}}
    <div class="gdlo-hub-section">
        <h2 class="gdlo-hub-section-title"><i class="fa-solid fa-fire"></i> {lang="gdloadout_trending"}</h2>
        <div class="gdlo-hub-grid">
            {{foreach $sections['trending'] as $loadout}}
            <div class="gdlo-hub-card">
                <div class="gdlo-hub-card-img">{{if $loadout['base_image']}}<img src="{$loadout['base_image']}" alt="{$loadout['base_title']}" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" /><div class="gdlo-hub-card-noimg" style="display:none"><i class="fa-solid fa-gun"></i></div>{{else}}<div class="gdlo-hub-card-noimg"><i class="fa-solid fa-gun"></i></div>{{endif}}</div>
                <div class="gdlo-hub-card-body"><a href="{$loadout['view_url']}" class="gdlo-hub-card-name">{$loadout['name']}</a><div class="gdlo-hub-card-meta"><span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>{{if $loadout['mode_label']}}<span class="gdlo-hub-tag">{$loadout['mode_label']}</span>{{endif}}{{if $loadout['platform']}}<span class="gdlo-hub-tag">{$loadout['platform']}</span>{{endif}}</div><div class="gdlo-hub-price">{{if $loadout['total_min_price'] > 0}}<span class="gdlo-hub-price-val">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>{{else}}<span class="gdlo-hub-price-na">{lang="gdloadout_no_price_yet"}</span>{{endif}}<span class="gdlo-hub-price-parts">{$loadout['total_items']} {lang="gdloadout_parts"}</span></div><div class="gdlo-hub-card-stats"><span aria-label="{lang="gdloadout_upvote_loadout"}"><i class="fa-solid fa-heart" aria-hidden="true"></i> {$loadout['upvotes']}</span><span aria-label="{lang="gdloadout_follow_loadout"}"><i class="fa-solid fa-bell" aria-hidden="true"></i> {$loadout['follow_count']}</span></div></div>
                <div class="gdlo-hub-actions"><a href="{$loadout['view_url']}" class="gdlo-hub-btn gdlo-hub-btn--view"><i class="fa-solid fa-eye"></i> {lang="gdloadout_view_build"}</a><form method="post" action="{$copyUrl}" class="gdlo-hub-copy-form"><input type="hidden" name="csrfKey" value="{$csrfKey}" /><input type="hidden" name="loadout_id" value="{$loadout['id']}" /><button type="submit" class="gdlo-hub-btn gdlo-hub-btn--copy"><i class="fa-solid fa-copy"></i> {lang="gdloadout_copy_build"}</button></form></div>
            </div>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
    {{if count($sections['top_rated']) > 0}}
    <div class="gdlo-hub-section">
        <h2 class="gdlo-hub-section-title"><i class="fa-solid fa-trophy"></i> {lang="gdloadout_top_rated"}</h2>
        <div class="gdlo-hub-grid">
            {{foreach $sections['top_rated'] as $loadout}}
            <div class="gdlo-hub-card">
                <div class="gdlo-hub-card-img">{{if $loadout['base_image']}}<img src="{$loadout['base_image']}" alt="{$loadout['base_title']}" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" /><div class="gdlo-hub-card-noimg" style="display:none"><i class="fa-solid fa-gun"></i></div>{{else}}<div class="gdlo-hub-card-noimg"><i class="fa-solid fa-gun"></i></div>{{endif}}</div>
                <div class="gdlo-hub-card-body"><a href="{$loadout['view_url']}" class="gdlo-hub-card-name">{$loadout['name']}</a><div class="gdlo-hub-card-meta"><span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>{{if $loadout['mode_label']}}<span class="gdlo-hub-tag">{$loadout['mode_label']}</span>{{endif}}{{if $loadout['platform']}}<span class="gdlo-hub-tag">{$loadout['platform']}</span>{{endif}}</div><div class="gdlo-hub-price">{{if $loadout['total_min_price'] > 0}}<span class="gdlo-hub-price-val">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>{{else}}<span class="gdlo-hub-price-na">{lang="gdloadout_no_price_yet"}</span>{{endif}}<span class="gdlo-hub-price-parts">{$loadout['total_items']} {lang="gdloadout_parts"}</span></div><div class="gdlo-hub-card-stats"><span aria-label="{lang="gdloadout_upvote_loadout"}"><i class="fa-solid fa-heart" aria-hidden="true"></i> {$loadout['upvotes']}</span><span aria-label="{lang="gdloadout_follow_loadout"}"><i class="fa-solid fa-bell" aria-hidden="true"></i> {$loadout['follow_count']}</span></div></div>
                <div class="gdlo-hub-actions"><a href="{$loadout['view_url']}" class="gdlo-hub-btn gdlo-hub-btn--view"><i class="fa-solid fa-eye"></i> {lang="gdloadout_view_build"}</a><form method="post" action="{$copyUrl}" class="gdlo-hub-copy-form"><input type="hidden" name="csrfKey" value="{$csrfKey}" /><input type="hidden" name="loadout_id" value="{$loadout['id']}" /><button type="submit" class="gdlo-hub-btn gdlo-hub-btn--copy"><i class="fa-solid fa-copy"></i> {lang="gdloadout_copy_build"}</button></form></div>
            </div>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
    {{if count($sections['recent']) > 0}}
    <div class="gdlo-hub-section">
        <h2 class="gdlo-hub-section-title"><i class="fa-solid fa-clock"></i> {lang="gdloadout_recent"}</h2>
        <div class="gdlo-hub-grid">
            {{foreach $sections['recent'] as $loadout}}
            <div class="gdlo-hub-card">
                <div class="gdlo-hub-card-img">{{if $loadout['base_image']}}<img src="{$loadout['base_image']}" alt="{$loadout['base_title']}" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" /><div class="gdlo-hub-card-noimg" style="display:none"><i class="fa-solid fa-gun"></i></div>{{else}}<div class="gdlo-hub-card-noimg"><i class="fa-solid fa-gun"></i></div>{{endif}}</div>
                <div class="gdlo-hub-card-body"><a href="{$loadout['view_url']}" class="gdlo-hub-card-name">{$loadout['name']}</a><div class="gdlo-hub-card-meta"><span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>{{if $loadout['mode_label']}}<span class="gdlo-hub-tag">{$loadout['mode_label']}</span>{{endif}}{{if $loadout['platform']}}<span class="gdlo-hub-tag">{$loadout['platform']}</span>{{endif}}</div><div class="gdlo-hub-price">{{if $loadout['total_min_price'] > 0}}<span class="gdlo-hub-price-val">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>{{else}}<span class="gdlo-hub-price-na">{lang="gdloadout_no_price_yet"}</span>{{endif}}<span class="gdlo-hub-price-parts">{$loadout['total_items']} {lang="gdloadout_parts"}</span></div><div class="gdlo-hub-card-stats"><span aria-label="{lang="gdloadout_upvote_loadout"}"><i class="fa-solid fa-heart" aria-hidden="true"></i> {$loadout['upvotes']}</span><span aria-label="{lang="gdloadout_follow_loadout"}"><i class="fa-solid fa-bell" aria-hidden="true"></i> {$loadout['follow_count']}</span></div></div>
                <div class="gdlo-hub-actions"><a href="{$loadout['view_url']}" class="gdlo-hub-btn gdlo-hub-btn--view"><i class="fa-solid fa-eye"></i> {lang="gdloadout_view_build"}</a><form method="post" action="{$copyUrl}" class="gdlo-hub-copy-form"><input type="hidden" name="csrfKey" value="{$csrfKey}" /><input type="hidden" name="loadout_id" value="{$loadout['id']}" /><button type="submit" class="gdlo-hub-btn gdlo-hub-btn--copy"><i class="fa-solid fa-copy"></i> {lang="gdloadout_copy_build"}</button></form></div>
            </div>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
    {{if count($sections['budget']) > 0}}
    <div class="gdlo-hub-section">
        <h2 class="gdlo-hub-section-title"><i class="fa-solid fa-piggy-bank"></i> {lang="gdloadout_budget"}</h2>
        <div class="gdlo-hub-grid">
            {{foreach $sections['budget'] as $loadout}}
            <div class="gdlo-hub-card">
                <div class="gdlo-hub-card-img">{{if $loadout['base_image']}}<img src="{$loadout['base_image']}" alt="{$loadout['base_title']}" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" /><div class="gdlo-hub-card-noimg" style="display:none"><i class="fa-solid fa-gun"></i></div>{{else}}<div class="gdlo-hub-card-noimg"><i class="fa-solid fa-gun"></i></div>{{endif}}</div>
                <div class="gdlo-hub-card-body"><a href="{$loadout['view_url']}" class="gdlo-hub-card-name">{$loadout['name']}</a><div class="gdlo-hub-card-meta"><span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>{{if $loadout['mode_label']}}<span class="gdlo-hub-tag">{$loadout['mode_label']}</span>{{endif}}{{if $loadout['platform']}}<span class="gdlo-hub-tag">{$loadout['platform']}</span>{{endif}}</div><div class="gdlo-hub-price">{{if $loadout['total_min_price'] > 0}}<span class="gdlo-hub-price-val">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>{{else}}<span class="gdlo-hub-price-na">{lang="gdloadout_no_price_yet"}</span>{{endif}}<span class="gdlo-hub-price-parts">{$loadout['total_items']} {lang="gdloadout_parts"}</span></div><div class="gdlo-hub-card-stats"><span aria-label="{lang="gdloadout_upvote_loadout"}"><i class="fa-solid fa-heart" aria-hidden="true"></i> {$loadout['upvotes']}</span><span aria-label="{lang="gdloadout_follow_loadout"}"><i class="fa-solid fa-bell" aria-hidden="true"></i> {$loadout['follow_count']}</span></div></div>
                <div class="gdlo-hub-actions"><a href="{$loadout['view_url']}" class="gdlo-hub-btn gdlo-hub-btn--view"><i class="fa-solid fa-eye"></i> {lang="gdloadout_view_build"}</a><form method="post" action="{$copyUrl}" class="gdlo-hub-copy-form"><input type="hidden" name="csrfKey" value="{$csrfKey}" /><input type="hidden" name="loadout_id" value="{$loadout['id']}" /><button type="submit" class="gdlo-hub-btn gdlo-hub-btn--copy"><i class="fa-solid fa-copy"></i> {lang="gdloadout_copy_build"}</button></form></div>
            </div>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
</div>
TEMPLATE_EOT,
	'template_updated' => time(),
	'template_version' => '1.0.30',
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
	'template_data' => '$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance, $hasVoted, $hasFollowed, $initData, $forumTopicUrl, $canCopy, $copyUrl, $csrfKey, $canSuggest, $suggestions, $pendingSuggestionCount, $acceptSugUrl, $rejectSugUrl, $startDiscussionUrl, $suggestBuilderUrl',
	'template_content' => <<<'TEMPLATE_EOT'
<div class="gdlo-view" id="gdloView" data-init='{$initData}'>

    <div class="gdlo-view-header">
        <div class="gdlo-view-header-info">
            <h1 class="gdlo-view-title">{$loadout['name']}</h1>
            <div class="gdlo-view-meta">
                <span class="gdlo-view-author"><i class="fa-solid fa-user"></i> {$ownerName}</span>
                {{if $loadout['use_case']}}
                <span class="gdlo-view-uc">{$loadout['use_case']}</span>
                {{endif}}
                {{if $loadout['visibility'] === 'private'}}
                <span class="gdlo-view-badge gdlo-view-badge--private"><i class="fa-solid fa-lock"></i> Private</span>
                {{endif}}
                {{if $loadout['visibility'] === 'unlisted'}}
                <span class="gdlo-view-badge gdlo-view-badge--unlisted"><i class="fa-solid fa-eye-slash"></i> Unlisted</span>
                {{endif}}
                <span class="gdlo-view-views"><i class="fa-solid fa-eye"></i> {$loadout['view_count']} views</span>
            </div>
            {{if $loadout['description']}}
            <p class="gdlo-view-desc">{$loadout['description']}</p>
            {{endif}}
        </div>
        <div class="gdlo-view-header-actions">
            {{if $isOwner}}
            <a href="{$editUrl}" class="gdlo-btn gdlo-btn--secondary"><i class="fa-solid fa-pen"></i> Edit</a>
            {{endif}}
        </div>
    </div>

    <div class="gdlo-view-2col">
        <div class="gdlo-view-main">

            <div class="gdlo-view-item-grid">
                {{if count($items) === 0}}
                <div class="gdlo-view-empty">
                    <i class="fa-solid fa-box-open"></i>
                    <p>{lang="gdloadout_no_items"}</p>
                </div>
                {{endif}}
                {{foreach $items as $item}}
                <div class="gdlo-view-card">
                    <div class="gdlo-view-item-thumb">
                        {{if $item['image_url']}}
                        <img src="{$item['image_url']}" alt="{$item['product_title']}" class="gdlo-view-item-img" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" />
                        <div class="gdlo-view-item-img-ph" style="display:none"><i class="fa-solid fa-cube"></i></div>
                        {{else}}
                        <div class="gdlo-view-item-img-ph"><i class="fa-solid fa-cube"></i></div>
                        {{endif}}
                    </div>
                    <div class="gdlo-view-card-body">
                        <span class="gdlo-view-card-slot">{$item['slot_type']}</span>
                        <div class="gdlo-view-card-title">
                            {{if $item['product_title']}}
                            {$item['product_title']}
                            {{else}}
                            {$item['upc']}
                            {{endif}}
                        </div>
                        {{if $item['brand']}}
                        <div class="gdlo-view-card-brand">{$item['brand']}</div>
                        {{endif}}
                        {{if $item['custom_label']}}
                        <div class="gdlo-view-card-label">{$item['custom_label']}</div>
                        {{endif}}
                        {{if $item['notes']}}
                        <div class="gdlo-view-card-notes">{$item['notes']}</div>
                        {{endif}}
                    </div>
                    <div class="gdlo-view-card-footer">
                        {{if $item['live_price']}}
                        <span class="gdlo-view-card-price">{expression="'$' . number_format((float)$item['live_price'], 2)"}</span>
                        <span class="gdlo-view-card-dealers">{expression="(int)$item['active_dealer_count']"} {lang="gdloadout_dealers"}</span>
                        {{else}}
                        <span class="gdlo-view-card-noprice">{lang="gdloadout_no_price"}</span>
                        {{endif}}
                    </div>
                </div>
                {{endforeach}}
            </div>

            {{if $isOwner}}
            {{if $pendingSuggestionCount > 0}}
            <div class="gdlo-suggest-panel" id="gdloSuggestionsPanel">
                <h3 class="gdlo-suggest-panel-title"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i> {lang="gdloadout_suggestions_pending"} ({$pendingSuggestionCount})</h3>
                {{foreach $suggestions as $sug}}
                <div class="gdlo-suggestion-card">
                    <div class="gdlo-suggestion-from"><i class="fa-solid fa-user" aria-hidden="true"></i> {$sug['from_name']} &mdash; {{if isset($sug['is_multi']) && $sug['is_multi']}}{expression="count($sug['enriched_changes'])"} slot(s){{else}}{$sug['slot_type']}{{endif}}</div>
                    {{if isset($sug['is_multi']) && $sug['is_multi']}}
                    <div class="gdlo-suggestion-changes">
                        {{foreach $sug['enriched_changes'] as $ch}}
                        <div class="gdlo-suggestion-change">
                            <span class="gdlo-suggestion-change-slot">{$ch['slot']}</span>
                            <div class="gdlo-suggestion-part">
                                {{if $ch['current_image']}}<img src="{$ch['current_image']}" alt="" class="gdlo-suggestion-img" />{{else}}<div class="gdlo-suggestion-img-ph"><i class="fa-solid fa-cube"></i></div>{{endif}}
                                <span class="gdlo-suggestion-part-title">{$ch['current_title']}</span>
                            </div>
                            <span class="gdlo-suggestion-arrow"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                            <div class="gdlo-suggestion-part">
                                {{if $ch['new_image']}}<img src="{$ch['new_image']}" alt="" class="gdlo-suggestion-img" />{{else}}<div class="gdlo-suggestion-img-ph"><i class="fa-solid fa-cube"></i></div>{{endif}}
                                <span class="gdlo-suggestion-part-title">{$ch['new_title']}</span>
                                {{if $ch['new_price']}}<span class="gdlo-suggestion-price">{expression="'$' . number_format((float)$ch['new_price'], 2)"}</span>{{endif}}
                            </div>
                        </div>
                        {{endforeach}}
                    </div>
                    {{else}}
                    <div class="gdlo-suggestion-swap">
                        <div class="gdlo-suggestion-part">
                            {{if $sug['current_image']}}<img src="{$sug['current_image']}" alt="" class="gdlo-suggestion-img" />{{else}}<div class="gdlo-suggestion-img-ph"><i class="fa-solid fa-cube"></i></div>{{endif}}
                            <span class="gdlo-suggestion-part-title">{$sug['current_title']}</span>
                        </div>
                        <span class="gdlo-suggestion-arrow"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                        <div class="gdlo-suggestion-part">
                            {{if $sug['sug_image']}}<img src="{$sug['sug_image']}" alt="" class="gdlo-suggestion-img" />{{else}}<div class="gdlo-suggestion-img-ph"><i class="fa-solid fa-cube"></i></div>{{endif}}
                            <span class="gdlo-suggestion-part-title">{$sug['sug_title']}</span>
                            {{if $sug['sug_price']}}<span class="gdlo-suggestion-price">{expression="'$' . number_format((float)$sug['sug_price'], 2)"}</span>{{endif}}
                        </div>
                    </div>
                    {{endif}}
                    {{if $sug['message']}}<div class="gdlo-suggestion-msg">{$sug['message']}</div>{{endif}}
                    <div class="gdlo-suggestion-actions">
                        <form method="post" action="{$acceptSugUrl}" style="display:inline"><input type="hidden" name="csrfKey" value="{$csrfKey}" /><input type="hidden" name="suggestion_id" value="{$sug['id']}" /><button type="submit" class="gdlo-btn gdlo-btn--primary gdlo-btn--sm"><i class="fa-solid fa-check"></i> {lang="gdloadout_suggestion_accept"}</button></form>
                        <form method="post" action="{$rejectSugUrl}" style="display:inline"><input type="hidden" name="csrfKey" value="{$csrfKey}" /><input type="hidden" name="suggestion_id" value="{$sug['id']}" /><button type="submit" class="gdlo-btn gdlo-btn--sm"><i class="fa-solid fa-xmark"></i> {lang="gdloadout_suggestion_reject"}</button></form>
                    </div>
                </div>
                {{endforeach}}
            </div>
            {{endif}}
            {{endif}}

        </div>

        <div class="gdlo-view-sidebar">
            <div class="gdlo-view-summary">
                <h3 class="gdlo-view-summary-title">{lang="gdloadout_build_summary"}</h3>
                <div class="gdlo-view-summary-stats">
                    <div class="gdlo-view-summary-stat">
                        <span class="gdlo-view-summary-stat-lbl">{lang="gdloadout_items"}</span>
                        <span class="gdlo-view-summary-stat-val">{$loadout['total_items']}</span>
                    </div>
                    {{if $loadout['total_min_price'] > 0}}
                    <div class="gdlo-view-summary-stat gdlo-view-summary-stat--total">
                        <span class="gdlo-view-summary-stat-lbl">{lang="gdloadout_est_cost"}</span>
                        <span class="gdlo-view-summary-stat-val gdlo-total">{expression="'$' . number_format((float)$loadout['total_min_price'], 2)"}</span>
                    </div>
                    {{endif}}
                </div>

                <div class="gdlo-vote-row">
                    <button type="button" class="gdlo-btn gdlo-btn--vote{{if $hasVoted}} gdlo-btn--voted{{endif}}" id="gdloUpvoteBtn" aria-label="{lang="gdloadout_upvote_loadout"}" title="{lang="gdloadout_upvote_loadout"}">
                        <i class="fa-solid fa-heart" aria-hidden="true"></i> <span id="gdloUpvoteCount">{$loadout['upvotes']}</span>
                    </button>
                    <button type="button" class="gdlo-btn gdlo-btn--follow{{if $hasFollowed}} gdlo-btn--followed{{endif}}" id="gdloFollowBtn" aria-label="{lang="gdloadout_follow_loadout"}" title="{lang="gdloadout_follow_loadout"}">
                        <i class="fa-solid fa-bell" aria-hidden="true"></i> <span id="gdloFollowCount">{$loadout['follow_count']}</span>
                    </button>
                    {{if $canCopy}}
                    <form method="post" action="{$copyUrl}" class="gdlo-copy-form gdlo-copy-form--inline">
                        <input type="hidden" name="csrfKey" value="{$csrfKey}">
                        <button type="submit" class="gdlo-action-btn gdlo-action-btn--copy gdlo-copy-fill" aria-label="Copy this build to my account" title="Copy this build to my account">
                            <i class="fa-solid fa-copy" aria-hidden="true"></i> {lang="gdloadout_copy_build"}
                        </button>
                    </form>
                    {{endif}}
                </div>

                <div class="gdlo-action-stack">
                    <button type="button" id="gdloWishlistBtn" class="gdlo-action-btn gdlo-action-btn--wishlist gdlo-action-btn--block" aria-label="{lang="gdloadout_add_all_wishlist"}" title="{lang="gdloadout_add_all_wishlist"}">
                        <i class="fa-solid fa-bookmark" aria-hidden="true"></i> {lang="gdloadout_add_all_wishlist"}
                    </button>
                    <button type="button" id="gdloAlertBtn" class="gdlo-action-btn gdlo-action-btn--alert gdlo-action-btn--block" aria-label="{lang="gdloadout_alert_all"}" title="{lang="gdloadout_alert_all"}">
                        <i class="fa-solid fa-bell" aria-hidden="true"></i> {lang="gdloadout_alert_all"}
                    </button>
                    {{if $canSuggest}}
                    <a href="{$suggestBuilderUrl}" class="gdlo-action-btn gdlo-action-btn--suggest gdlo-action-btn--block">
                        <i class="fa-solid fa-lightbulb" aria-hidden="true"></i> {lang="gdloadout_suggest_an_edit"}
                    </a>
                    {{endif}}
                    {{if $forumTopicUrl}}
                    <a href="{$forumTopicUrl}" class="gdlo-action-btn gdlo-action-btn--block gdlo-btn--secondary" target="_blank" rel="noopener">
                        <i class="fa-solid fa-comments" aria-hidden="true"></i> {lang="gdloadout_join_discussion"}{{if $loadout['comment_count'] > 0}} ({$loadout['comment_count']}){{endif}}
                    </a>
                    {{elseif $isOwner}}
                    <form method="post" action="{$startDiscussionUrl}" style="margin:0;">
                        <input type="hidden" name="csrfKey" value="{$csrfKey}">
                        <button type="submit" class="gdlo-action-btn gdlo-action-btn--block gdlo-btn--secondary" style="width:100%;">
                            <i class="fa-solid fa-comments" aria-hidden="true"></i> {lang="gdloadout_start_discussion"}
                        </button>
                    </form>
                    {{endif}}
                </div>

                {{if $compliance['has_issues']}}
                <div class="gdlo-view-compliance gdlo-compliance--warn">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <div>
                        <strong>{lang="gdloadout_compliance"}</strong>
                        {{if $compliance['nfa_count'] > 0}}
                        <p>{expression="(int)$compliance['nfa_count']"} NFA item(s) — requires tax stamp</p>
                        {{endif}}
                        {{if $compliance['ffl_count'] > 0}}
                        <p>{expression="(int)$compliance['ffl_count']"} item(s) require FFL transfer</p>
                        {{endif}}
                    </div>
                </div>
                {{endif}}
            </div>
        </div>
    </div>
</div>
TEMPLATE_EOT,
	'template_updated' => time(),
	'template_version' => '1.0.40',
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
	'template_data' => '$loadouts, $builderUrl, $csrfKey',
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
    <div class="gdlo-empty">
        <i class="fa-solid fa-layer-group"></i>
        <h3>{lang="gdloadout_no_loadouts_yet"}</h3>
        <p>{lang="gdloadout_no_loadouts_desc"}</p>
        <a href="{$builderUrl}" class="gdlo-btn gdlo-btn--primary">{lang="gdloadout_create_first"}</a>
    </div>
    {{else}}
    <div class="gdlo-hub-grid">
        {{foreach $loadouts as $loadout}}
        <div class="gdlo-hub-card">
            <a href="{$loadout['view_url']}" class="gdlo-hub-card-imglink">
                <div class="gdlo-hub-card-img">
                    {{if $loadout['base_image']}}<img src="{$loadout['base_image']}" alt="{$loadout['name']}" loading="lazy" onerror="this.style.display='none';this.parentNode.querySelector('.gdlo-hub-card-noimg').style.display='flex'">{{endif}}
                    <i class="fa-solid fa-crosshairs gdlo-hub-card-noimg" {{if $loadout['base_image']}}style="display:none"{{endif}} aria-hidden="true"></i>
                </div>
            </a>
            <div class="gdlo-hub-card-body">
                <a href="{$loadout['view_url']}" class="gdlo-hub-card-name">{$loadout['name']}</a>
                <div class="gdlo-hub-card-meta">
                    {{if $loadout['use_case']}}<span class="gdlo-hub-tag">{$loadout['use_case']}</span>{{endif}}
                    {{if $loadout['visibility'] === 'private'}}<span class="gdlo-hub-card-badge" title="Private"><i class="fa-solid fa-lock"></i></span>{{endif}}
                    {{if $loadout['visibility'] === 'unlisted'}}<span class="gdlo-hub-card-badge" title="Unlisted"><i class="fa-solid fa-eye-slash"></i></span>{{endif}}
                </div>
                {{if $loadout['total_min_price'] > 0}}
                <div class="gdlo-hub-price"><span class="gdlo-hub-price-val">{expression="'$' . number_format((float)$loadout['total_min_price'], 2)"}</span><span class="gdlo-hub-price-parts">{$loadout['total_items']} {lang="gdloadout_parts"}</span></div>
                {{else}}
                <div class="gdlo-hub-price"><span class="gdlo-hub-price-na">{lang="gdloadout_no_price_yet"}</span><span class="gdlo-hub-price-parts">{$loadout['total_items']} {lang="gdloadout_parts"}</span></div>
                {{endif}}
                <div class="gdlo-hub-card-stats">
                    <span aria-label="Upvotes" title="Upvotes"><i class="fa-solid fa-heart" aria-hidden="true"></i> {$loadout['upvotes']}</span>
                    <span aria-label="Views" title="Views"><i class="fa-solid fa-eye" aria-hidden="true"></i> {$loadout['view_count']}</span>
                </div>
            </div>
            <div class="gdlo-hub-actions">
                <a href="{$loadout['edit_url']}" class="gdlo-hub-btn gdlo-hub-btn--view"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</a>
                <form method="post" action="{$loadout['delete_url']}" onsubmit="return confirm('Delete this loadout?');" style="flex:0 0 auto;margin:0;">
                    <input type="hidden" name="csrfKey" value="{$csrfKey}">
                    <button type="submit" class="gdlo-hub-btn gdlo-hub-btn--danger" aria-label="Delete loadout" title="Delete loadout"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                </form>
            </div>
        </div>
        {{endforeach}}
    </div>
    {{endif}}
</div>
TEMPLATE_EOT,
	'template_updated' => time(),
	'template_version' => '1.0.32',
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
<ips:template parameters="$initData" />
<script type="application/json" id="gdlo-init">{$initData|raw}</script>
<div id="gdLoadoutBuilder" class="gdlo-wiz">

<div class="gdlo-stepper" id="gdStepper">
  <button type="button" class="gdlo-step gdlo-step--active" data-step="1"><span class="gdlo-step-num">1</span> {lang="gdloadout_step_setup"}</button>
  <button type="button" class="gdlo-step" data-step="2"><span class="gdlo-step-num">2</span> {lang="gdloadout_step_core"}</button>
  <button type="button" class="gdlo-step" data-step="3"><span class="gdlo-step-num">3</span> {lang="gdloadout_step_accessories"}</button>
  <button type="button" class="gdlo-step" data-step="4"><span class="gdlo-step-num">4</span> {lang="gdloadout_step_review"}</button>
</div>

<div id="gdStep1" class="gdlo-wiz-step">
  <div class="gdlo-wiz-main">
    <h2 class="gdlo-wiz-heading">{lang="gdloadout_step_setup"}</h2>
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

    <div class="gdlo-section-label">{lang="gdloadout_build_mode"}</div>
    <div id="gdModeGrid" class="gdlo-mode-grid">
      <div class="gdlo-mode-card gdlo-mode-card--active" data-mode="complete_firearm">
        <i class="fa-solid fa-gun gdlo-mode-icon"></i>
        <div class="gdlo-mode-name">{lang="gdloadout_mode_complete"}</div>
        <div class="gdlo-mode-desc">{lang="gdloadout_mode_complete_desc"}</div>
      </div>
      <div class="gdlo-mode-card" data-mode="component_build">
        <i class="fa-solid fa-gears gdlo-mode-icon"></i>
        <div class="gdlo-mode-name">{lang="gdloadout_mode_component"}</div>
        <div class="gdlo-mode-desc">{lang="gdloadout_mode_component_desc"}</div>
      </div>
    </div>

    <div id="gdPlatformRow" style="display:none">
      <div class="gdlo-section-label">{lang="gdloadout_platform"}</div>
      <div id="gdPlatformChips" class="gdlo-platform-grid"></div>
    </div>
  </div>
</div>

<div id="gdStep2" class="gdlo-wiz-step" style="display:none">
  <div class="gdlo-wiz-body">
    <div class="gdlo-wiz-main">
      <div class="gdlo-section-label" id="gdCoreLabel">{lang="gdloadout_core_slots"}</div>
      <div id="gdCoreGrid" class="gdlo-card-grid"></div>
    </div>
    <div class="gdlo-wiz-side" id="gdCoreSide">
      <div class="gdlo-panel">
        <div class="gdlo-panel-head">{lang="gdloadout_progress"}</div>
        <div class="gdlo-panel-body">
          <div class="gdlo-progress-wrap"><div id="gdProgressFill2" class="gdlo-progress-fill"></div></div>
          <div id="gdProgressText2" class="gdlo-progress-text">0 / 0 slots filled</div>
        </div>
      </div>
      <div class="gdlo-panel">
        <div class="gdlo-panel-head">{lang="gdloadout_builder_total_cost"}</div>
        <div class="gdlo-summary-body">
          <div id="gdSideCost2" class="gdlo-total-cost">$0.00</div>
          <div id="gdSideItems2" class="gdlo-total-items">0 items</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="gdStep3" class="gdlo-wiz-step" style="display:none">
  <div class="gdlo-wiz-body">
    <div class="gdlo-wiz-main">
      <div class="gdlo-section-label">{lang="gdloadout_accessories"}</div>
      <div id="gdAccGrid" class="gdlo-card-grid"></div>
      <div class="gdlo-section-label">{lang="gdloadout_custom_extras"}</div>
      <div id="gdExtraGrid" class="gdlo-card-grid"></div>
      <div class="gdlo-extra-add">
        <input type="text" id="gdExtraName" class="gdlo-input gdlo-input--sm" placeholder="{lang="gdloadout_extra_placeholder"}" />
        <button type="button" id="gdAddExtra" class="gdlo-btn gdlo-btn--sm gdlo-btn--secondary">{lang="gdloadout_builder_add_extra"}</button>
      </div>
    </div>
    <div class="gdlo-wiz-side" id="gdAccSide">
      <div class="gdlo-panel">
        <div class="gdlo-panel-head">{lang="gdloadout_progress"}</div>
        <div class="gdlo-panel-body">
          <div class="gdlo-progress-wrap"><div id="gdProgressFill3" class="gdlo-progress-fill"></div></div>
          <div id="gdProgressText3" class="gdlo-progress-text">0 / 0 slots filled</div>
        </div>
      </div>
      <div class="gdlo-panel">
        <div class="gdlo-panel-head">{lang="gdloadout_builder_total_cost"}</div>
        <div class="gdlo-summary-body">
          <div id="gdSideCost3" class="gdlo-total-cost">$0.00</div>
          <div id="gdSideItems3" class="gdlo-total-items">0 items</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="gdlo-pick-backdrop" id="gdPickBackdrop"></div>
<div id="gdPickerPanel" class="gdlo-pick">
  <div class="gdlo-pick-head">
    <h3 id="gdPickTitle" class="gdlo-pick-title"></h3>
    <button type="button" class="gdlo-pick-close" id="gdPickClose" aria-label="Close">&times;</button>
  </div>
  <div id="gdPickTypes" class="gdlo-pick-types"></div>
  <div id="gdPickSort" class="gdlo-pick-sort"></div>
  <div class="gdlo-pick-search">
    <input type="text" id="gdPickSearch" class="gdlo-input" placeholder="{lang="gdloadout_modal_search"}" />
  </div>
  <div id="gdPickFacets" class="gdlo-pick-facets"></div>
  <div id="gdPickFacetBar" class="gdlo-pick-facet-bar"></div>
  <button type="button" id="gdPickFacetClear" class="gdlo-pick-facet-clear" style="display:none">{lang="gdloadout_modal_clear_filters"}</button>
  <div id="gdPickResults" class="gdlo-pick-results"></div>
  <button type="button" id="gdPickLoadMore" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" style="display:none;margin:0 20px 16px">{lang="gdloadout_modal_load_more"}</button>
  <div id="gdPickEmpty" class="gdlo-pick-empty" style="display:none">{lang="gdloadout_modal_no_products"}</div>
</div>

<div id="gdStep4" class="gdlo-wiz-step" style="display:none">
  <div class="gdlo-wiz-body">
    <div class="gdlo-wiz-main">
      <h2 class="gdlo-wiz-heading">{lang="gdloadout_step_review"}</h2>
      <div id="gdReviewList" class="gdlo-review-list"></div>
      <div id="gdVipNotes" class="gdlo-panel" style="display:none">
        <div class="gdlo-panel-head">{lang="gdloadout_vip_notes"}</div>
        <div id="gdNotesBody" class="gdlo-panel-body"></div>
      </div>
      <div id="gdSuggestNoteWrap" class="gdlo-suggest-note-field" style="display:none">
        <label for="gdSuggestNote">{lang="gdloadout_suggest_note"}</label>
        <textarea id="gdSuggestNote" rows="2" maxlength="500"></textarea>
      </div>
    </div>
    <div class="gdlo-wiz-side">
      <div class="gdlo-panel">
        <div class="gdlo-panel-head">{lang="gdloadout_builder_total_cost"}</div>
        <div class="gdlo-summary-body">
          <div id="gdTotalCost" class="gdlo-total-cost">$0.00</div>
          <div id="gdTotalItems" class="gdlo-total-items">0 items</div>
        </div>
        <div id="gdItemBreakdown" class="gdlo-breakdown"></div>
      </div>
      <div class="gdlo-actions">
        <button type="button" id="gdSaveBtn" class="ipsButton ipsButton--primary gdlo-save-btn">{lang="gdloadout_builder_save"}</button>
        <button type="button" id="gdSubmitSugBtn" class="ipsButton ipsButton--primary gdlo-save-btn" style="display:none">{lang="gdloadout_submit_suggestion"}</button>
        <button type="button" id="gdDeleteBtn" class="ipsButton ipsButton--negative" style="display:none">{lang="gdloadout_builder_delete"}</button>
      </div>
    </div>
  </div>
</div>

<div class="gdlo-wiz-nav" id="gdWizNav">
  <button type="button" id="gdPrevBtn" class="gdlo-btn gdlo-btn--secondary" style="display:none"><i class="fa-solid fa-arrow-left"></i> {lang="gdloadout_builder_prev"}</button>
  <div style="flex:1"></div>
  <button type="button" id="gdNextBtn" class="gdlo-btn gdlo-btn--primary">{lang="gdloadout_builder_next"} <i class="fa-solid fa-arrow-right"></i></button>
</div>

</div>
TEMPLATE_EOT,
	'template_updated' => time(),
	'template_version' => '1.0.39',
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
	'template_version' => '1.0.20',
	'template_master_key' => '',
	'template_has_hookpoints' => 0,
];

// Template: limits (admin — editable form)
$templates[] = [
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
	'template_version' => '1.0.13',
	'template_master_key' => '',
	'template_has_hookpoints' => 0,
];

// Template: loadoutEmbed
$templates[] = [
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
	'template_version' => '1.0.10',
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
	'template_version' => '1.0.20',
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
	[ 'notification_app' => 'gdloadout', 'notification_key' => 'suggestion_received', 'default' => '["inline"]' ],
	[ 'notification_app' => 'gdloadout', 'notification_key' => 'suggestion_resolved', 'default' => '["inline"]' ],
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

// Seed gdloadout_share_forum setting
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

// Seed suggestion settings
$suggestSettings = [
	[ 'conf_key' => 'gdloadout_suggest_mode',      'conf_default' => 'anyone', 'conf_value' => 'anyone' ],
	[ 'conf_key' => 'gdloadout_suggest_groups',     'conf_default' => '',       'conf_value' => ''       ],
	[ 'conf_key' => 'gdloadout_suggest_min_posts',  'conf_default' => '0',      'conf_value' => '0'      ],
	[ 'conf_key' => 'gdloadout_suggest_min_rep',    'conf_default' => '0',      'conf_value' => '0'      ],
];
foreach ( $suggestSettings as $ss )
{
	try
	{
		$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_sys_conf_settings', [ 'conf_key=?', $ss['conf_key'] ] )->first();
		if ( $exists === 0 )
		{
			\IPS\Db::i()->insert( 'core_sys_conf_settings', [
				'conf_key'     => $ss['conf_key'],
				'conf_value'   => $ss['conf_value'],
				'conf_default' => $ss['conf_default'],
				'conf_app'     => 'gdloadout',
			] );
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

