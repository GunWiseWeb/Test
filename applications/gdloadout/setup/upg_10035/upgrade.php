<?php

namespace IPS\gdloadout\setup\upg_10035;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		// 1. Reseed view template — discussion moved to sidebar, bottom block removed
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

            {{if $isOwner}}
            {{if $pendingSuggestionCount > 0}}
            <div class="gdlo-suggest-panel" id="gdloSuggestionsPanel">
                <h3 class="gdlo-suggest-panel-title"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i> {lang="gdloadout_suggestions_pending"} ({$pendingSuggestionCount})</h3>
                {{foreach $suggestions as $sug}}
                <div class="gdlo-suggestion-card">
                    <div class="gdlo-suggestion-from"><i class="fa-solid fa-user" aria-hidden="true"></i> {$sug['from_name']} &mdash; {$sug['slot_type']}</div>
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

            {{if $canSuggest}}
            <div class="gdlo-suggest-panel" id="gdloSuggestForm">
                <h3 class="gdlo-suggest-panel-title"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i> {lang="gdloadout_suggest_swap"}</h3>
                <div class="gdlo-suggest-form">
                    <div class="gdlo-suggest-field">
                        <label class="gdlo-suggest-label">{lang="gdloadout_suggest_pick_slot"}</label>
                        <select id="gdloSuggestSlot" class="gdlo-select"></select>
                    </div>
                    <div class="gdlo-suggest-field">
                        <label class="gdlo-suggest-label">{lang="gdloadout_suggest_pick_product"}</label>
                        <input type="text" id="gdloSuggestSearch" class="gdlo-input" placeholder="{lang="gdloadout_modal_search"}" autocomplete="off" />
                        <div id="gdloSuggestResults" class="gdlo-suggest-results" style="display:none"></div>
                        <div id="gdloSuggestSelected" class="gdlo-suggest-selected" style="display:none"></div>
                    </div>
                    <div class="gdlo-suggest-field">
                        <label class="gdlo-suggest-label">{lang="gdloadout_suggest_message"}</label>
                        <textarea id="gdloSuggestMessage" class="gdlo-input" rows="2" maxlength="500"></textarea>
                    </div>
                    <button type="button" id="gdloSuggestSubmit" class="gdlo-btn gdlo-btn--primary"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> {lang="gdloadout_suggest_submit"}</button>
                    <div id="gdloSuggestStatus" class="gdlo-suggest-status" style="display:none"></div>
                </div>
            </div>
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

                {{if $forumTopicUrl}}
                <a href="{$forumTopicUrl}" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" target="_blank" rel="noopener">
                    <i class="fa-solid fa-comments" aria-hidden="true"></i> {lang="gdloadout_join_discussion"}{{if $loadout['comment_count'] > 0}} ({$loadout['comment_count']}){{endif}}
                </a>
                {{elseif $isOwner}}
                <form method="post" action="{$startDiscussionUrl}" class="gdlo-startdisc-form" style="margin:0;">
                    <input type="hidden" name="csrfKey" value="{$csrfKey}">
                    <button type="submit" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full">
                        <i class="fa-solid fa-comments" aria-hidden="true"></i> {lang="gdloadout_start_discussion"}
                    </button>
                </form>
                {{endif}}

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
				'template_set_id'       => 1,
				'template_app'          => 'gdloadout',
				'template_location'     => 'front',
				'template_group'        => 'loadouts',
				'template_name'         => 'view',
				'template_data'         => '$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance, $hasVoted, $hasFollowed, $initData, $forumTopicUrl, $canCopy, $copyUrl, $csrfKey, $canSuggest, $suggestions, $pendingSuggestionCount, $acceptSugUrl, $rejectSugUrl, $startDiscussionUrl',
				'template_content'      => $viewContent,
				'template_updated'      => time(),
				'template_version'      => '1.0.35',
				'template_master_key'   => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		// 2. Seed new lang strings (rule #43 6-col schema, rule #44 per-row try/catch)
		$newStrings = [
			'gdloadout_start_discussion'    => 'Start Discussion',
			'gdloadout_discussion_no_forum' => 'Set a target forum in ACP → Loadouts → Settings to enable discussions.',
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

		// 3. Clear caches
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
