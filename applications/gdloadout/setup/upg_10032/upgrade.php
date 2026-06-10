<?php

namespace IPS\gdloadout\setup\upg_10032;

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
		$viewContent = <<<'TEMPLATE_EOT'
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

                <div class="gdlo-view-summary-actions">
                    <button type="button" class="gdlo-btn gdlo-btn--vote{{if $hasVoted}} gdlo-btn--voted{{endif}}" id="gdloUpvoteBtn" aria-label="{lang="gdloadout_upvote_loadout"}" title="{lang="gdloadout_upvote_loadout"}">
                        <i class="fa-solid fa-heart" aria-hidden="true"></i> <span id="gdloUpvoteCount">{$loadout['upvotes']}</span>
                    </button>
                    <button type="button" class="gdlo-btn gdlo-btn--follow{{if $hasFollowed}} gdlo-btn--followed{{endif}}" id="gdloFollowBtn" aria-label="{lang="gdloadout_follow_loadout"}" title="{lang="gdloadout_follow_loadout"}">
                        <i class="fa-solid fa-bell" aria-hidden="true"></i> <span id="gdloFollowCount">{$loadout['follow_count']}</span>
                    </button>
                </div>

                <div class="gdlo-action-row">
                    <button type="button" id="gdloWishlistBtn" class="gdlo-action-btn gdlo-action-btn--wishlist" aria-label="{lang="gdloadout_add_all_wishlist"}" title="{lang="gdloadout_add_all_wishlist"}">
                        <i class="fa-solid fa-bookmark" aria-hidden="true"></i> {lang="gdloadout_add_all_wishlist"}
                    </button>
                    <button type="button" id="gdloAlertBtn" class="gdlo-action-btn gdlo-action-btn--alert" aria-label="{lang="gdloadout_alert_all"}" title="{lang="gdloadout_alert_all"}">
                        <i class="fa-solid fa-bell" aria-hidden="true"></i> {lang="gdloadout_alert_all"}
                    </button>
                </div>

                {{if $canCopy}}
                <form method="post" action="{$copyUrl}" class="gdlo-copy-form">
                    <input type="hidden" name="csrfKey" value="{$csrfKey}">
                    <button type="submit" class="gdlo-action-btn gdlo-action-btn--copy" aria-label="Copy this build to my account" title="Copy this build to my account">
                        <i class="fa-solid fa-copy" aria-hidden="true"></i> {lang="gdloadout_copy_build"}
                    </button>
                </form>
                {{endif}}

                <div class="gdlo-view-summary-extras">
                    {{if $forumTopicUrl}}
                    <a href="{$forumTopicUrl}" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" target="_blank"><i class="fa-solid fa-comments" aria-hidden="true"></i> {lang="gdloadout_view_discussion"}</a>
                    {{else}}
                        {{if $canShareForum}}
                    <button type="button" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" id="gdloShareForumBtn"><i class="fa-solid fa-share" aria-hidden="true"></i> {lang="gdloadout_share_to_forum"}</button>
                        {{endif}}
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
TEMPLATE_EOT;

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'        => 1,
				'template_app'           => 'gdloadout',
				'template_location'      => 'front',
				'template_group'         => 'loadouts',
				'template_name'          => 'view',
				'template_data'          => '$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance, $hasVoted, $hasFollowed, $comments, $initData, $canShareForum, $forumTopicUrl, $canCopy, $copyUrl, $csrfKey',
				'template_content'       => $viewContent,
				'template_updated'       => time(),
				'template_version'       => '1.0.32',
				'template_master_key'    => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		$myContent = <<<'TEMPLATE_EOT'
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
TEMPLATE_EOT;

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'        => 1,
				'template_app'           => 'gdloadout',
				'template_location'      => 'front',
				'template_group'         => 'loadouts',
				'template_name'          => 'myLoadouts',
				'template_data'          => '$loadouts, $builderUrl, $csrfKey',
				'template_content'       => $myContent,
				'template_updated'       => time(),
				'template_version'       => '1.0.32',
				'template_master_key'    => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
