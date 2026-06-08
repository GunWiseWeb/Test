<?php

namespace IPS\gdloadout\setup\upg_10001;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$templates = [];
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
                <div class="gdlo-hub-card-meta">
                    <span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>
                    {{if $loadout['use_case']}}
                    <span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>
                    {{endif}}
                </div>
                <div class="gdlo-hub-card-stats">
                    <span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span>
                    <span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span>
                    <span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>
                    {{if $loadout['total_min_price'] > 0}}
                    <span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>
                    {{endif}}
                </div>
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
                <div class="gdlo-hub-card-meta">
                    <span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>
                    {{if $loadout['use_case']}}
                    <span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>
                    {{endif}}
                </div>
                <div class="gdlo-hub-card-stats">
                    <span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span>
                    <span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span>
                    <span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>
                    {{if $loadout['total_min_price'] > 0}}
                    <span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>
                    {{endif}}
                </div>
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
                <div class="gdlo-hub-card-meta">
                    <span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>
                    {{if $loadout['use_case']}}
                    <span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>
                    {{endif}}
                </div>
                <div class="gdlo-hub-card-stats">
                    <span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span>
                    <span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span>
                    <span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>
                    {{if $loadout['total_min_price'] > 0}}
                    <span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>
                    {{endif}}
                </div>
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
                <div class="gdlo-hub-card-meta">
                    <span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>
                    {{if $loadout['use_case']}}
                    <span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>
                    {{endif}}
                </div>
                <div class="gdlo-hub-card-stats">
                    <span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span>
                    <span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span>
                    <span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>
                    {{if $loadout['total_min_price'] > 0}}
                    <span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>
                    {{endif}}
                </div>
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
                <div class="gdlo-hub-card-meta">
                    <span class="gdlo-hub-card-author"><i class="fa-solid fa-user"></i> {$loadout['owner_name']}</span>
                    {{if $loadout['use_case']}}
                    <span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>
                    {{endif}}
                </div>
                <div class="gdlo-hub-card-stats">
                    <span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span>
                    <span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span>
                    <span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>
                    {{if $loadout['total_min_price'] > 0}}
                    <span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>
                    {{endif}}
                </div>
            </a>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
</div>
TEMPLATE_EOT,
			'template_updated' => time(),
			'template_version' => '1.0.1',
		];

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
                {{if $loadout['use_case']}}
                <span class="gdlo-view-uc">{$loadout['use_case']}</span>
                {{endif}}
                {{if $loadout['visibility'] === 'private'}}
                <span class="gdlo-view-badge gdlo-view-badge--private"><i class="fa-solid fa-lock"></i> Private</span>
                {{endif}}
                {{if $loadout['visibility'] === 'unlisted'}}
                <span class="gdlo-view-badge gdlo-view-badge--unlisted"><i class="fa-solid fa-eye-slash"></i> Unlisted</span>
                {{endif}}
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
            <div class="gdlo-view-items">
                {{if count($items) === 0}}
                <div class="gdlo-view-empty">
                    <i class="fa-solid fa-box-open"></i>
                    <p>{lang="gdloadout_no_items"}</p>
                </div>
                {{endif}}
                {{foreach $items as $item}}
                <div class="gdlo-view-item">
                    <div class="gdlo-view-item-icon">
                        {{if $item['image_url']}}
                        <img src="{$item['image_url']}" alt="" class="gdlo-view-item-img" loading="lazy" />
                        {{else}}
                        <i class="fa-solid fa-cube"></i>
                        {{endif}}
                    </div>
                    <div class="gdlo-view-item-info">
                        <div class="gdlo-view-item-title">
                            {{if $item['product_title']}}
                            {$item['product_title']}
                            {{else}}
                            {$item['upc']}
                            {{endif}}
                        </div>
                        <div class="gdlo-view-item-sub">
                            <span class="gdlo-view-item-slot">{$item['slot_type']}</span>
                            {{if $item['brand']}}
                            <span class="gdlo-view-item-brand">{$item['brand']}</span>
                            {{endif}}
                            {{if $item['caliber']}}
                            <span class="gdlo-view-item-caliber">{$item['caliber']}</span>
                            {{endif}}
                        </div>
                        {{if $item['custom_label']}}
                        <div class="gdlo-view-item-label">{$item['custom_label']}</div>
                        {{endif}}
                        {{if $item['notes']}}
                        <div class="gdlo-view-item-notes">{$item['notes']}</div>
                        {{endif}}
                    </div>
                    <div class="gdlo-view-item-price">
                        {{if $item['live_price']}}
                        <span class="gdlo-view-item-price-val">{expression="'$' . number_format((float)$item['live_price'], 2)"}</span>
                        <span class="gdlo-view-item-dealers">{expression="(int)$item['active_dealer_count']"} {lang="gdloadout_dealers"}</span>
                        {{else}}
                        <span class="gdlo-view-item-price-na">{lang="gdloadout_no_price"}</span>
                        {{endif}}
                    </div>
                </div>
                {{endforeach}}
            </div>

            <div class="gdlo-view-comments" id="gdloComments">
                <h3 class="gdlo-view-comments-title"><i class="fa-solid fa-comments"></i> {lang="gdloadout_comments"} ({$loadout['comment_count']})</h3>
                <div class="gdlo-view-comments-list" id="gdloCommentList">
                    {{foreach $comments as $c}}
                    <div class="gdlo-view-comment">
                        <strong class="gdlo-view-comment-author">{$c['member_name']}</strong>
                        <p class="gdlo-view-comment-body">{$c['comment']}</p>
                    </div>
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
                    <div class="gdlo-view-summary-stat">
                        <span class="gdlo-view-summary-stat-val">{$loadout['total_items']}</span>
                        <span class="gdlo-view-summary-stat-lbl">{lang="gdloadout_items"}</span>
                    </div>
                    {{if $loadout['total_min_price'] > 0}}
                    <div class="gdlo-view-summary-stat">
                        <span class="gdlo-view-summary-stat-val">{expression="'$' . number_format((float)$loadout['total_min_price'], 2)"}</span>
                        <span class="gdlo-view-summary-stat-lbl">{lang="gdloadout_est_cost"}</span>
                    </div>
                    {{endif}}
                </div>

                <div class="gdlo-view-summary-actions">
                    <button type="button" class="gdlo-btn gdlo-btn--vote{{if $hasVoted}} gdlo-btn--voted{{endif}}" id="gdloUpvoteBtn">
                        <i class="fa-solid fa-heart"></i> <span id="gdloUpvoteCount">{$loadout['upvotes']}</span>
                    </button>
                    <button type="button" class="gdlo-btn gdlo-btn--follow{{if $hasFollowed}} gdlo-btn--followed{{endif}}" id="gdloFollowBtn">
                        <i class="fa-solid fa-bell"></i> <span id="gdloFollowCount">{$loadout['follow_count']}</span>
                    </button>
                </div>

                <div class="gdlo-view-summary-extras">
                    <button type="button" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" id="gdloWishlistBtn"><i class="fa-solid fa-bookmark"></i> {lang="gdloadout_add_all_wishlist"}</button>
                    <button type="button" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" id="gdloAlertBtn"><i class="fa-solid fa-bell"></i> {lang="gdloadout_alert_all"}</button>
                </div>

                {{if $compliance['has_issues']}}
                <div class="gdlo-view-compliance">
                    <h4><i class="fa-solid fa-triangle-exclamation"></i> {lang="gdloadout_compliance"}</h4>
                    {{if $compliance['nfa_count'] > 0}}
                    <p>{expression="(int)$compliance['nfa_count']"} NFA item(s) — requires tax stamp</p>
                    {{endif}}
                    {{if $compliance['ffl_count'] > 0}}
                    <p>{expression="(int)$compliance['ffl_count']"} item(s) require FFL transfer</p>
                    {{endif}}
                </div>
                {{endif}}

                <div class="gdlo-view-summary-share">
                    <span class="gdlo-view-views"><i class="fa-solid fa-eye"></i> {$loadout['view_count']} views</span>
                </div>
            </div>
        </div>
    </div>
</div>
TEMPLATE_EOT,
			'template_updated' => time(),
			'template_version' => '1.0.1',
		];

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
            <a href="{$loadout['view_url']}" class="gdlo-hub-card-link">
                <div class="gdlo-hub-card-name">{$loadout['name']}</div>
                <div class="gdlo-hub-card-meta">
                    {{if $loadout['use_case']}}
                    <span class="gdlo-hub-card-uc">{$loadout['use_case']}</span>
                    {{endif}}
                    {{if $loadout['visibility'] === 'private'}}
                    <span class="gdlo-hub-card-badge"><i class="fa-solid fa-lock"></i></span>
                    {{endif}}
                    {{if $loadout['visibility'] === 'unlisted'}}
                    <span class="gdlo-hub-card-badge"><i class="fa-solid fa-eye-slash"></i></span>
                    {{endif}}
                </div>
                <div class="gdlo-hub-card-stats">
                    <span><i class="fa-solid fa-heart"></i> {$loadout['upvotes']}</span>
                    <span><i class="fa-solid fa-eye"></i> {$loadout['view_count']}</span>
                    <span><i class="fa-solid fa-cubes"></i> {$loadout['total_items']}</span>
                    {{if $loadout['total_min_price'] > 0}}
                    <span class="gdlo-hub-card-price">{expression="'$' . number_format((float)$loadout['total_min_price'], 0)"}</span>
                    {{endif}}
                </div>
            </a>
            <div class="gdlo-hub-card-actions">
                <a href="{$loadout['edit_url']}" class="gdlo-btn gdlo-btn--sm gdlo-btn--secondary"><i class="fa-solid fa-pen"></i> Edit</a>
            </div>
        </div>
        {{endforeach}}
    </div>
    {{endif}}
</div>
TEMPLATE_EOT,
			'template_updated' => time(),
			'template_version' => '1.0.1',
		];

		foreach ( $templates as $tpl )
		{
			try
			{
				\IPS\Db::i()->replace( 'core_theme_templates', $tpl );
			}
			catch ( \Throwable ) {}
		}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
