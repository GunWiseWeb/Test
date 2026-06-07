<?php

namespace IPS\gdloadout\setup\upg_10006;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$now = time();
		$ver = '1.0.6';

		$builderContent = <<<'TEMPLATE_EOT'
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

		<div class="gdlo-section-label">Core Slots <span id="gdSlotCount" class="gdlo-slot-count"></span></div>
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
TEMPLATE_EOT;

		$viewContent = <<<'TEMPLATE_EOT'
<script type="application/json" id="gdlo-view-init">{$initData|raw}</script>
<div class="gdlo-view">

	<div class="gdlo-view-header">
		<h1 class="gdlo-view-title">{expression="htmlspecialchars($loadout['name'])"}</h1>
		{{if $loadout['use_case']}}
		<span class="gdlo-view-badge">{expression="htmlspecialchars($loadout['use_case'])"}</span>
		{{endif}}
		<div class="gdlo-view-meta">
			<span>by {expression="htmlspecialchars($ownerName)"}</span>
			<span>{expression="(int)$loadout['view_count']"} views</span>
			<span>{expression="(int)$loadout['total_items']"} items</span>
		</div>
	</div>

	{{if $compliance['has_issues']}}
	<div class="gdlo-compliance gdlo-compliance--warn">
		{{if $compliance['nfa_count'] > 0}}
		<span class="gdlo-compliance-tag"><i class="fa-solid fa-shield"></i> {expression="(int)$compliance['nfa_count']"} NFA Items</span>
		{{endif}}
		{{if $compliance['ffl_count'] > 0}}
		<span class="gdlo-compliance-tag"><i class="fa-solid fa-id-card"></i> {expression="(int)$compliance['ffl_count']"} FFL Required</span>
		{{endif}}
	</div>
	{{else}}
	<div class="gdlo-compliance gdlo-compliance--ok">
		<i class="fa-solid fa-check-circle"></i> {lang="gdloadout_compliance_none"}
	</div>
	{{endif}}

	<div class="gdlo-view-actions">
		<button type="button" id="gdUpvoteBtn" class="ipsButton ipsButton--small" data-voted="{expression="$hasVoted ? '1' : '0'}">
			<i class="fa-solid fa-arrow-up"></i> <span id="gdUpvoteCount">{expression="(int)$loadout['upvotes']"}</span>
		</button>
		<button type="button" id="gdFollowBtn" class="ipsButton ipsButton--small" data-followed="{expression="$hasFollowed ? '1' : '0'}">
			<i class="fa-solid fa-bell"></i> <span id="gdFollowLabel">{expression="$hasFollowed ? 'Following' : 'Follow'"}</span>
		</button>
		{{if $isOwner}}
		<a href="{$editUrl}" class="ipsButton ipsButton--small ipsButton--light"><i class="fa-solid fa-pen"></i> Edit</a>
		{{endif}}
	</div>

	<div class="gdlo-view-items">
		{{foreach $items as $item}}
		<div class="gdlo-view-item">
			<div class="gdlo-view-item-img">
				{{if $item['image_url']}}
				<img src="{expression="htmlspecialchars($item['image_url'])"}" alt="" loading="lazy" />
				{{else}}
				<i class="fa-solid fa-box" style="font-size:2em;color:#ccc"></i>
				{{endif}}
			</div>
			<div class="gdlo-view-item-info">
				<div class="gdlo-view-item-slot">{expression="htmlspecialchars($item['custom_label'] ?? ucwords(str_replace('_',' ',$item['slot_type'])))"}</div>
				<div class="gdlo-view-item-name">{expression="htmlspecialchars($item['product_title'] ?? $item['upc'])"}</div>
				{{if $item['brand']}}
				<div class="gdlo-view-item-brand">{expression="htmlspecialchars($item['brand'])"}</div>
				{{endif}}
				{{if $item['notes']}}
				<div class="gdlo-view-item-notes"><i class="fa-solid fa-sticky-note"></i> {expression="htmlspecialchars($item['notes'])"}</div>
				{{endif}}
			</div>
			<div class="gdlo-view-item-price">
				{{if $item['live_price']}}
				<div class="gdlo-slot-price">${expression="number_format((float)$item['live_price'],2)"}</div>
				{{if $item['active_dealer_count']}}
				<div class="gdlo-search-meta">{expression="(int)$item['active_dealer_count']"} dealers</div>
				{{endif}}
				{{else}}
				<div class="gdlo-search-meta">&mdash;</div>
				{{endif}}
			</div>
			<div class="gdlo-view-item-badges">
				{{if $item['nfa_item']}}
				<span class="gdlo-badge gdlo-badge--nfa">NFA</span>
				{{endif}}
				{{if $item['requires_ffl']}}
				<span class="gdlo-badge gdlo-badge--ffl">FFL</span>
				{{endif}}
			</div>
		</div>
		{{endforeach}}
	</div>

	{{if $loadout['total_min_price']}}
	<div class="gdlo-view-total">
		<span>Est. Total:</span>
		<span class="gdlo-total-cost">${expression="number_format((float)$loadout['total_min_price'],2)"}</span>
	</div>
	{{endif}}

	{{if $loadout['description']}}
	<div class="gdlo-view-desc">
		{expression="nl2br(htmlspecialchars($loadout['description']))"}
	</div>
	{{endif}}

	<div class="gdlo-view-comments">
		<h3 class="gdlo-section-label">Comments ({expression="count($comments)"})</h3>
		<div id="gdCommentsList">
			{{foreach $comments as $c}}
			<div class="gdlo-comment">
				<div class="gdlo-comment-meta">
					<strong>{expression="htmlspecialchars($c['member_name'])"}</strong>
					<span class="gdlo-search-meta">{expression="date('M j, Y', (int)$c['created_at'])"}</span>
				</div>
				<div class="gdlo-comment-text">{expression="nl2br(htmlspecialchars($c['comment']))"}</div>
			</div>
			{{endforeach}}
		</div>

		<div id="gdCommentForm" class="gdlo-comment-form">
			<textarea id="gdCommentInput" class="gdlo-input" rows="2" placeholder="Add a comment..."></textarea>
			<button type="button" id="gdCommentBtn" class="ipsButton ipsButton--primary ipsButton--small" style="margin-top:8px">Post Comment</button>
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
				'template_name'    => 'builder',
				'template_data'    => '$initData',
				'template_content' => $builderContent,
				'template_updated' => $now,
				'template_version' => $ver,
			] );
		}
		catch ( \Throwable ) {}

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location'=> 'front',
				'template_group'   => 'loadouts',
				'template_name'    => 'view',
				'template_data'    => '$loadout, $items, $ownerName, $isOwner, $editUrl, $compliance, $hasVoted, $hasFollowed, $comments, $initData',
				'template_content' => $viewContent,
				'template_updated' => $now,
				'template_version' => $ver,
			] );
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
