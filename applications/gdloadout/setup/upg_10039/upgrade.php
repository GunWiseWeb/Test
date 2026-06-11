<?php

namespace IPS\gdloadout\setup\upg_10039;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
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
TEMPLATE_EOT;

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'        => 1,
				'template_app'           => 'gdloadout',
				'template_location'      => 'front',
				'template_group'         => 'loadouts',
				'template_name'          => 'builder',
				'template_data'          => '$initData',
				'template_content'       => $builderBody,
				'template_updated'       => time(),
				'template_version'       => '1.0.39',
				'template_master_key'    => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		$newStrings = [
			'gdloadout_suggest_submitted_title' => 'Suggestion Submitted!',
			'gdloadout_suggest_submitted_desc'  => 'The loadout owner will be notified of your suggested changes.',
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
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
