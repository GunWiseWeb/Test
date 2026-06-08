<?php

namespace IPS\gdloadout\setup\upg_10004;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$tpl = [
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
			'template_version' => '1.0.4',
		];

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', $tpl );
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
