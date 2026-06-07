<?php

namespace IPS\gdloadout\setup\upg_10001;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_loadout_group_limits' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_loadout_group_limits',
					'columns' => [
						[
							'name'           => 'id',
							'type'           => 'INT',
							'length'         => 10,
							'unsigned'       => TRUE,
							'auto_increment' => TRUE,
						],
						[
							'name'     => 'group_id',
							'type'     => 'INT',
							'length'   => 10,
							'unsigned' => TRUE,
							'default'  => 0,
						],
						[
							'name'     => 'max_loadouts',
							'type'     => 'INT',
							'length'   => 10,
							'unsigned' => TRUE,
							'default'  => 0,
						],
						[
							'name'     => 'max_slots',
							'type'     => 'INT',
							'length'   => 10,
							'unsigned' => TRUE,
							'default'  => 0,
						],
					],
					'indexes' => [
						[
							'type'    => 'primary',
							'columns' => [ 'id' ],
						],
						[
							'type'    => 'unique',
							'name'    => 'uq_group',
							'columns' => [ 'group_id' ],
						],
					],
				] );
			}
		}
		catch ( \Throwable ) {}

		$builderBody = <<<'TEMPLATE_EOT'
<div id="gdLoadoutBuilder"
     data-save-url="{$saveUrl}"
     data-delete-url="{$deleteUrl}"
     data-search-url="{$searchUrl}"
     data-csrf-key="{$csrfKey}"
     data-loadout-id="{expression="$loadout ? (int)$loadout['id'] : 0"}"
     data-loadout-name="{expression="$loadout ? htmlspecialchars($loadout['name'], ENT_QUOTES) : ''"}"
     data-loadout-description="{expression="$loadout ? htmlspecialchars($loadout['description'] ?? '', ENT_QUOTES) : ''"}"
     data-loadout-use-case="{expression="$loadout ? htmlspecialchars($loadout['use_case'] ?? '', ENT_QUOTES) : ''"}"
     data-loadout-visibility="{expression="$loadout ? $loadout['visibility'] : 'unlisted'"}"
     data-max-loadouts="{expression="(int)$limits['max_loadouts']"}"
     data-max-slots="{expression="(int)$limits['max_slots']"}"
     data-items="{expression="htmlspecialchars(json_encode($items), ENT_QUOTES)"}"
     data-core-slots="{expression="htmlspecialchars(json_encode($coreSlots), ENT_QUOTES)"}"
     data-extra-lib="{expression="htmlspecialchars(json_encode($extraLib), ENT_QUOTES)"}">

<div style="display:flex;gap:24px;flex-wrap:wrap;padding:16px">

	<div style="flex:2;min-width:320px">
		<div style="margin-bottom:16px">
			<input type="text" id="gdLoadoutName" placeholder="{lang="gdloadout_builder_name"}" class="ipsField_fullWidth" style="font-size:1.2em;padding:10px 14px;border:1px solid var(--i-border-color,#ccc);border-radius:6px;width:100%;box-sizing:border-box" />
		</div>
		<div style="margin-bottom:16px">
			<textarea id="gdLoadoutDesc" placeholder="{lang="gdloadout_builder_description"}" rows="2" class="ipsField_fullWidth" style="padding:10px 14px;border:1px solid var(--i-border-color,#ccc);border-radius:6px;width:100%;box-sizing:border-box;resize:vertical"></textarea>
		</div>
		<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap">
			<select id="gdLoadoutUseCase" style="padding:8px 12px;border:1px solid var(--i-border-color,#ccc);border-radius:6px;flex:1;min-width:140px">
				<option value="">-- Use Case --</option>
				<option value="Home Defense">{lang="gdloadout_use_case_home_defense"}</option>
				<option value="Concealed Carry">{lang="gdloadout_use_case_concealed_carry"}</option>
				<option value="Hunting">{lang="gdloadout_use_case_hunting"}</option>
				<option value="Competition">{lang="gdloadout_use_case_competition"}</option>
				<option value="Range">{lang="gdloadout_use_case_range"}</option>
				<option value="Tactical">{lang="gdloadout_use_case_tactical"}</option>
				<option value="Collection">{lang="gdloadout_use_case_collection"}</option>
			</select>
			<select id="gdLoadoutVisibility" style="padding:8px 12px;border:1px solid var(--i-border-color,#ccc);border-radius:6px;flex:1;min-width:120px">
				<option value="public">{lang="gdloadout_vis_public"}</option>
				<option value="unlisted" selected>{lang="gdloadout_vis_unlisted"}</option>
				<option value="private">{lang="gdloadout_vis_private"}</option>
			</select>
		</div>

		<div style="font-weight:700;font-size:0.85em;text-transform:uppercase;letter-spacing:0.05em;color:#666;margin-bottom:10px">Core Slots</div>
		<div id="gdSlotGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:20px">
		</div>

		<div style="font-weight:700;font-size:0.85em;text-transform:uppercase;letter-spacing:0.05em;color:#666;margin-bottom:10px">Extras</div>
		<div id="gdExtraSlots" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px">
		</div>
		<button type="button" id="gdAddExtra" class="ipsButton ipsButton--small ipsButton--light" style="margin-bottom:20px">{lang="gdloadout_builder_add_extra"}</button>

		<div id="gdExtraPicker" style="display:none;border:1px dashed var(--i-border-color,#ccc);border-radius:8px;padding:16px;margin-bottom:20px">
			<div style="font-weight:600;margin-bottom:8px">Add Slot</div>
			<div id="gdExtraChips" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px"></div>
			<div style="display:flex;gap:8px">
				<input type="text" id="gdCustomSlotName" placeholder="Custom slot name..." style="flex:1;padding:6px 10px;border:1px solid var(--i-border-color,#ccc);border-radius:4px" />
				<button type="button" id="gdAddCustomSlot" class="ipsButton ipsButton--small ipsButton--primary">Add</button>
			</div>
		</div>
	</div>

	<div style="flex:1;min-width:280px">
		<div style="position:sticky;top:80px">
			<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;background:var(--i-background,#fff);overflow:hidden;margin-bottom:16px">
				<div style="padding:14px 16px;border-bottom:1px solid var(--i-border-color,#e0e0e0);font-weight:700">Product Search</div>
				<div style="padding:12px 16px">
					<input type="text" id="gdSearchInput" placeholder="{lang="gdloadout_builder_search"}" style="width:100%;padding:8px 12px;border:1px solid var(--i-border-color,#ccc);border-radius:6px;box-sizing:border-box" />
				</div>
				<div id="gdSearchResults" style="max-height:300px;overflow-y:auto;padding:0 16px 12px"></div>
			</div>

			<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;background:var(--i-background,#fff);overflow:hidden;margin-bottom:16px">
				<div style="padding:14px 16px;border-bottom:1px solid var(--i-border-color,#e0e0e0);font-weight:700">{lang="gdloadout_builder_total_cost"}</div>
				<div style="padding:16px;text-align:center">
					<div id="gdTotalCost" style="font-size:2em;font-weight:700;color:#2a8a2a">$0.00</div>
					<div id="gdTotalItems" style="font-size:0.85em;color:#888;margin-top:4px">0 items</div>
				</div>
				<div id="gdItemBreakdown" style="border-top:1px solid var(--i-border-color,#e0e0e0);padding:12px 16px;font-size:0.85em"></div>
			</div>

			<div style="display:flex;gap:10px">
				<button type="button" id="gdSaveBtn" class="ipsButton ipsButton--primary" style="flex:1">{lang="gdloadout_builder_save"}</button>
				{{if $loadout}}
				<button type="button" id="gdDeleteBtn" class="ipsButton ipsButton--negative" style="flex:0 0 auto">{lang="gdloadout_builder_delete"}</button>
				{{endif}}
			</div>
		</div>
	</div>

</div>
</div>
TEMPLATE_EOT;

		$hubBody = <<<'TEMPLATE_EOT'
<div class="ipsBox">
	<div class="ipsBox_container">
		<div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--i-border-color,#e0e0e0)">
			<h1 class="ipsType_pageTitle">{lang="gdloadout_hub_title"}</h1>
			{{if $canCreate}}
			<a href="{$builderUrl}" class="ipsButton ipsButton--primary ipsButton--small"><i class="fa-solid fa-plus"></i> New Loadout</a>
			{{endif}}
		</div>
		{{if count($loadouts) > 0}}
		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;padding:20px">
			{{foreach $loadouts as $lo}}
			<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;padding:16px;background:var(--i-background,#fff)">
				<div style="font-weight:700;font-size:1.1em;margin-bottom:6px">{expression="htmlspecialchars($lo['name'])"}</div>
				{{if $lo['use_case']}}
				<div style="font-size:0.85em;color:#666;margin-bottom:8px">{expression="htmlspecialchars($lo['use_case'])"}</div>
				{{endif}}
				<div style="display:flex;gap:12px;font-size:0.85em;color:#888">
					<span><i class="fa-solid fa-box"></i> {expression="(int)$lo['total_items']"} items</span>
					{{if $lo['total_min_price']}}
					<span><i class="fa-solid fa-dollar-sign"></i> {expression="number_format((float)$lo['total_min_price'],2)"}</span>
					{{endif}}
					<span><i class="fa-solid fa-arrow-up"></i> {expression="(int)$lo['upvotes']"}</span>
				</div>
			</div>
			{{endforeach}}
		</div>
		{{else}}
		<div style="text-align:center;padding:60px 20px;color:#888">
			<i class="fa-solid fa-layer-group" style="font-size:3em;margin-bottom:12px;display:block;opacity:0.4"></i>
			<p>{lang="gdloadout_hub_empty"}</p>
			{{if $canCreate}}
			<a href="{$builderUrl}" class="ipsButton ipsButton--primary" style="margin-top:16px"><i class="fa-solid fa-plus"></i> Create Your First Build</a>
			{{endif}}
		</div>
		{{endif}}
	</div>
</div>
TEMPLATE_EOT;

		$limitsBody = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">
		<h2 style="margin-bottom:16px">{lang="gdloadout_limits_title"}</h2>
		<p style="color:#666;margin-bottom:20px">{lang="gdloadout_limits_unlimited"}</p>
		<form method="post" action="{$saveUrl}">
			<input type="hidden" name="csrfKey" value="{$csrfKey}" />
			<table class="ipsTable ipsTable_zebra" style="width:100%">
				<thead>
					<tr>
						<th>{lang="gdloadout_limits_group"}</th>
						<th style="width:160px">{lang="gdloadout_limits_max_loadouts"}</th>
						<th style="width:160px">{lang="gdloadout_limits_max_slots"}</th>
					</tr>
				</thead>
				<tbody>
					{{foreach $groups as $gid => $gname}}
					<tr>
						<td>{expression="htmlspecialchars($gname)"}</td>
						<td><input type="number" name="group_limits[{expression="(int)$gid"}][max_loadouts]" value="{expression="isset($limits[$gid]) ? (int)$limits[$gid]['max_loadouts'] : 0"}" min="0" style="width:100%;padding:6px 10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" /></td>
						<td><input type="number" name="group_limits[{expression="(int)$gid"}][max_slots]" value="{expression="isset($limits[$gid]) ? (int)$limits[$gid]['max_slots'] : 0"}" min="0" style="width:100%;padding:6px 10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box" /></td>
					</tr>
					{{endforeach}}
				</tbody>
			</table>
			<div style="margin-top:16px">
				<button type="submit" class="ipsButton ipsButton--primary">{lang="gdloadout_builder_save"}</button>
			</div>
		</form>
	</div>
</div>
TEMPLATE_EOT;

		$templates = [
			[
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location' => 'front',
				'template_group'   => 'loadouts',
				'template_name'    => 'hub',
				'template_data'    => '$loadouts, $canCreate, $builderUrl',
				'template_content' => $hubBody,
				'template_updated' => time(),
				'template_version' => '1.0.1',
			],
			[
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location' => 'front',
				'template_group'   => 'loadouts',
				'template_name'    => 'builder',
				'template_data'    => '$loadout, $items, $coreSlots, $extraLib, $limits, $saveUrl, $deleteUrl, $searchUrl, $csrfKey',
				'template_content' => $builderBody,
				'template_updated' => time(),
				'template_version' => '1.0.1',
			],
			[
				'template_set_id'  => 1,
				'template_app'     => 'gdloadout',
				'template_location' => 'admin',
				'template_group'   => 'manage',
				'template_name'    => 'limits',
				'template_data'    => '$groups, $limits, $csrfKey, $saveUrl',
				'template_content' => $limitsBody,
				'template_updated' => time(),
				'template_version' => '1.0.1',
			],
		];

		foreach ( $templates as $tpl )
		{
			try
			{
				\IPS\Db::i()->replace( 'core_theme_templates', $tpl );
			}
			catch ( \Throwable ) {}
		}

		$newStrings = [
			'__app_gdloadout'                  => 'Loadouts',
			'frontnavigation_loadouts'         => 'Loadouts',
			'menutab__gdloadout'               => 'Loadouts',
			'menutab__gdloadout_icon'          => 'layer-group',
			'gdloadout_hub_title'              => 'Loadouts',
			'gdloadout_hub_empty'              => 'No community builds yet — be the first to create a loadout!',
			'menu__gdloadout_loadouts_hub'     => 'Loadouts',
			'menu__gdloadout_manage'           => 'Loadout Settings',
			'menu__gdloadout_manage_limits'    => 'Group Limits',
			'gdloadout_builder_title'          => 'Loadout Builder',
			'gdloadout_builder_name'           => 'Build Name',
			'gdloadout_builder_description'    => 'Description (optional)',
			'gdloadout_builder_use_case'       => 'Use Case',
			'gdloadout_builder_visibility'     => 'Visibility',
			'gdloadout_builder_save'           => 'Save Loadout',
			'gdloadout_builder_delete'         => 'Delete Loadout',
			'gdloadout_builder_search'         => 'Search products...',
			'gdloadout_builder_slot_empty'     => '+ Add',
			'gdloadout_builder_total_cost'     => 'Est. Build Cost',
			'gdloadout_builder_total_items'    => 'Total Items',
			'gdloadout_builder_add_extra'      => '+ Add Extra Slot',
			'gdloadout_builder_remove'         => 'Remove',
			'gdloadout_slot_base_firearm'      => 'Base Firearm',
			'gdloadout_slot_optic'             => 'Optic',
			'gdloadout_slot_weapon_light'      => 'Weapon Light',
			'gdloadout_slot_laser'             => 'Laser',
			'gdloadout_slot_suppressor'        => 'Suppressor',
			'gdloadout_slot_foregrip'          => 'Foregrip',
			'gdloadout_slot_sling'             => 'Sling',
			'gdloadout_slot_holster'           => 'Holster',
			'gdloadout_slot_ammo'              => 'Ammo',
			'gdloadout_slot_cleaning'          => 'Cleaning',
			'gdloadout_slot_extra'             => 'Extra',
			'gdloadout_use_case_home_defense'  => 'Home Defense',
			'gdloadout_use_case_concealed_carry' => 'Concealed Carry',
			'gdloadout_use_case_hunting'       => 'Hunting',
			'gdloadout_use_case_competition'   => 'Competition',
			'gdloadout_use_case_range'         => 'Range',
			'gdloadout_use_case_tactical'      => 'Tactical',
			'gdloadout_use_case_collection'    => 'Collection',
			'gdloadout_vis_public'             => 'Public',
			'gdloadout_vis_unlisted'           => 'Unlisted',
			'gdloadout_vis_private'            => 'Private',
			'gdloadout_limits_title'           => 'Loadout Group Limits',
			'gdloadout_limits_group'           => 'Member Group',
			'gdloadout_limits_max_loadouts'    => 'Max Loadouts',
			'gdloadout_limits_max_slots'       => 'Max Slots per Loadout',
			'gdloadout_limits_unlimited'       => '0 = unlimited',
			'gdloadout_limits_saved'           => 'Group limits saved.',
			'gdloadout_err_limit_loadouts'     => 'You have reached the maximum number of loadouts for your account.',
			'gdloadout_err_limit_slots'        => 'You have reached the maximum number of slots for this loadout.',
			'gdloadout_err_name_required'      => 'Please enter a name for your loadout.',
			'gdloadout_err_not_found'          => 'Loadout not found.',
			'gdloadout_err_no_permission'      => 'You do not have permission to edit this loadout.',
			'gdloadout_saved'                  => 'Loadout saved successfully.',
			'gdloadout_deleted'                => 'Loadout deleted.',
			'gdloadout_confirm_delete'         => 'Are you sure you want to delete this loadout? This cannot be undone.',
			'r__limits_manage'                 => 'Can manage loadout limits',
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

		try
		{
			\IPS\core\FrontNavigation::buildDefaultFrontNavigation();
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
