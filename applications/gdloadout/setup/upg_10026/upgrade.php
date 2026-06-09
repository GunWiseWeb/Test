<?php

namespace IPS\gdloadout\setup\upg_10026;

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

		// Re-seed builder template
		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'    => 1,
				'template_app'       => 'gdloadout',
				'template_location'  => 'front',
				'template_group'     => 'loadouts',
				'template_name'      => 'builder',
				'template_data'      => '$initData',
				'template_content'   => <<<'TEMPLATE_EOT'
<ips:template parameters="$initData" />
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
    <div id="gdModalFacets" class="gdlo-modal-facets"></div>
    <div id="gdModalFacetBar" class="gdlo-modal-facet-bar"></div>
    <button type="button" id="gdModalFacetClear" class="gdlo-modal-facet-clear" style="display:none">{lang="gdloadout_modal_clear_filters"}</button>
    <div id="gdModalResults" class="gdlo-modal-results"></div>
    <button type="button" id="gdModalLoadMore" class="gdlo-btn gdlo-btn--secondary gdlo-btn--full" style="display:none;margin:0 20px 16px">{lang="gdloadout_modal_load_more"}</button>
    <div id="gdModalEmpty" class="gdlo-modal-empty" style="display:none">{lang="gdloadout_modal_no_products"}</div>
  </div>
</div>
TEMPLATE_EOT,
				'template_updated'       => time(),
				'template_version'       => '1.0.26',
				'template_master_key'    => '',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		// Seed all lang strings accumulated through v1.0.26
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
			'gdloadout_modal_sort_relevance'   => 'Relevance',
			'gdloadout_modal_sort_name'        => 'Name (A–Z)',
			'gdloadout_modal_load_more'        => 'Load more',
			'gdloadout_modal_no_price'         => 'No price yet',
			'gdloadout_modal_filters'          => 'Filters',
			'gdloadout_modal_in_stock'         => 'In stock only',
			'gdloadout_modal_min_price'        => 'Min $',
			'gdloadout_modal_max_price'        => 'Max $',
			'gdloadout_modal_clear_filters'    => 'Clear filters',
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

		// Clear caches
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }     catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
