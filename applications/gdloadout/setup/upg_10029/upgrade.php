<?php

namespace IPS\gdloadout\setup\upg_10029;

class _upgrade
{
	public function step1(): bool
	{
		/* ---- Schema: ensure build_mode + platform columns exist (from v1.0.28, for installs that skipped it) ---- */
		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_loadouts', 'build_mode' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_loadouts', [
					'name'    => 'build_mode',
					'type'    => 'VARCHAR',
					'length'  => 30,
					'null'    => false,
					'default' => 'complete_firearm',
				] );
			}
		}
		catch ( \Throwable ) {}

		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_loadouts', 'platform' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_loadouts', [
					'name'    => 'platform',
					'type'    => 'VARCHAR',
					'length'  => 50,
					'null'    => true,
					'default' => null,
				] );
			}
		}
		catch ( \Throwable ) {}

		/* ---- Reseed view template (revamped card grid + action row) ---- */
		$viewBody = <<<'TEMPLATE_EOT'
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
				'template_set_id'       => 1,
				'template_app'          => 'gdloadout',
				'template_location'     => 'front',
				'template_group'        => 'loadouts',
				'template_name'         => 'view',
				'template_data'         => '$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance, $hasVoted, $hasFollowed, $comments, $initData, $canShareForum, $forumTopicUrl',
				'template_content'      => $viewBody,
				'template_updated'      => time(),
				'template_version'      => '1.0.29',
				'template_master_key'   => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		/* ---- Reseed builder template (from v1.0.28, for installs that skipped it) ---- */
		$builderBody = <<<'TEMPLATE_EOT'
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

<div id="gdPickerPanel" class="gdlo-pick" style="display:none">
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
TEMPLATE_EOT;

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'       => 1,
				'template_app'          => 'gdloadout',
				'template_location'     => 'front',
				'template_group'        => 'loadouts',
				'template_name'         => 'builder',
				'template_data'         => '$initData',
				'template_content'      => $builderBody,
				'template_updated'      => time(),
				'template_version'      => '1.0.29',
				'template_master_key'   => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		/* ---- Seed new lang strings ---- */
		$newStrings = [
			'gdloadout_upvote_loadout' => 'Upvote this loadout',
			'gdloadout_follow_loadout' => 'Follow for updates',
			'gdloadout_step_setup'          => 'Setup',
			'gdloadout_step_core'           => 'Core Build',
			'gdloadout_step_accessories'    => 'Accessories',
			'gdloadout_step_review'         => 'Review & Save',
			'gdloadout_build_mode'          => 'Build Mode',
			'gdloadout_mode_complete'       => 'Complete Firearm',
			'gdloadout_mode_complete_desc'  => 'Start with a factory firearm, add accessories',
			'gdloadout_mode_component'      => 'Build from Components',
			'gdloadout_mode_component_desc' => 'Assemble from individual parts (AR, AK, etc.)',
			'gdloadout_platform'            => 'Platform',
			'gdloadout_core_slots'          => 'Core Slots',
			'gdloadout_accessories'         => 'Accessories',
			'gdloadout_custom_extras'       => 'Custom Extras',
			'gdloadout_progress'            => 'Build Progress',
			'gdloadout_vip_notes'           => 'Item Notes (VIP)',
			'gdloadout_builder_prev'        => 'Previous',
			'gdloadout_builder_next'        => 'Next',
			'gdloadout_extra_placeholder'   => 'Custom slot name...',
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

		/* ---- Clear caches ---- */
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		try
		{
			\IPS\Db::i()->delete( 'core_cache' );
		}
		catch ( \Throwable ) {}

		try
		{
			\IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] );
		}
		catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
