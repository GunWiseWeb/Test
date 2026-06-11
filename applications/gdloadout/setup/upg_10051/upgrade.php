<?php

namespace IPS\gdloadout\setup\upg_10051;

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

    {{if $isOwner}}
    {{if $pendingSuggestionCount > 0}}
    <div class="gdlo-sug-banner" id="gdloSugBanner" data-count="{$pendingSuggestionCount}">
        <button type="button" class="gdlo-sug-banner-head" id="gdloSugBannerToggle" aria-expanded="false">
            <span class="gdlo-sug-banner-icon"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i></span>
            <span class="gdlo-sug-banner-text">
                {lang="gdloadout_sug_banner_lead"} <strong>{$pendingSuggestionCount}</strong> {lang="gdloadout_sug_banner_pending"}
            </span>
            <span class="gdlo-sug-banner-chevron"><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></span>
        </button>
        <div class="gdlo-sug-banner-body" id="gdloSugBannerBody" hidden>
            {{foreach $suggestions as $sug}}
            <div class="gdlo-sug-card" data-sug-id="{$sug['id']}">
                <div class="gdlo-sug-card-head">
                    <span class="gdlo-sug-from"><i class="fa-solid fa-user" aria-hidden="true"></i> {$sug['from_name']}</span>
                    {{if $sug['message']}}<span class="gdlo-sug-msg">{$sug['message']}</span>{{endif}}
                </div>
                <div class="gdlo-sug-changes">
                    {{if isset($sug['is_multi']) && $sug['is_multi']}}
                        {{foreach $sug['enriched_changes'] as $ch}}
                        <div class="gdlo-sug-change">
                            <span class="gdlo-sug-slot">{$ch['slot']}</span>
                            <div class="gdlo-sug-diff">
                                <div class="gdlo-sug-from-part">{{if $ch['current_image']}}<img src="{$ch['current_image']}" alt="" loading="lazy">{{endif}}<span>{$ch['current_title']}</span></div>
                                <i class="fa-solid fa-arrow-right gdlo-sug-arrow" aria-hidden="true"></i>
                                <div class="gdlo-sug-to-part">{{if $ch['new_image']}}<img src="{$ch['new_image']}" alt="" loading="lazy">{{endif}}<span>{$ch['new_title']}</span>{{if $ch['new_price']}}<span class="gdlo-sug-price">{expression="'$' . number_format((float)$ch['new_price'], 2)"}</span>{{endif}}</div>
                            </div>
                        </div>
                        {{endforeach}}
                    {{else}}
                        <div class="gdlo-sug-change">
                            <span class="gdlo-sug-slot">{$sug['slot_type']}</span>
                            <div class="gdlo-sug-diff">
                                <div class="gdlo-sug-from-part">{{if $sug['current_image']}}<img src="{$sug['current_image']}" alt="" loading="lazy">{{endif}}<span>{$sug['current_title']}</span></div>
                                <i class="fa-solid fa-arrow-right gdlo-sug-arrow" aria-hidden="true"></i>
                                <div class="gdlo-sug-to-part">{{if $sug['sug_image']}}<img src="{$sug['sug_image']}" alt="" loading="lazy">{{endif}}<span>{$sug['sug_title']}</span>{{if $sug['sug_price']}}<span class="gdlo-sug-price">{expression="'$' . number_format((float)$sug['sug_price'], 2)"}</span>{{endif}}</div>
                            </div>
                        </div>
                    {{endif}}
                </div>
                <div class="gdlo-sug-actions">
                    <form method="post" action="{$acceptSugUrl}" style="display:inline"><input type="hidden" name="csrfKey" value="{$csrfKey}" /><input type="hidden" name="suggestion_id" value="{$sug['id']}" /><button type="submit" class="gdlo-btn gdlo-btn--positive gdlo-sug-accept">{lang="gdloadout_sug_accept"}</button></form>
                    <form method="post" action="{$rejectSugUrl}" style="display:inline"><input type="hidden" name="csrfKey" value="{$csrfKey}" /><input type="hidden" name="suggestion_id" value="{$sug['id']}" /><button type="submit" class="gdlo-btn gdlo-btn--negative gdlo-sug-reject">{lang="gdloadout_sug_reject"}</button></form>
                </div>
            </div>
            {{endforeach}}
        </div>
    </div>
    {{endif}}
    {{endif}}

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
TEMPLATE_EOT;

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location'=> 'front',
				'template_group'   => 'loadouts',
				'template_name'    => 'view',
				'template_data'    => '$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance, $hasVoted, $hasFollowed, $initData, $forumTopicUrl, $canCopy, $copyUrl, $csrfKey, $canSuggest, $suggestions, $pendingSuggestionCount, $acceptSugUrl, $rejectSugUrl, $startDiscussionUrl, $suggestBuilderUrl',
				'template_content' => $viewContent,
				'template_updated' => time(),
				'template_version' => '1.0.51',
			] );
		}
		catch ( \Throwable ) {}

		$newStrings = [
			'gdloadout_sug_banner_lead'    => 'You have',
			'gdloadout_sug_banner_pending' => 'pending suggestion(s)',
			'gdloadout_sug_accept'         => 'Accept',
			'gdloadout_sug_reject'         => 'Reject',
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

		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
