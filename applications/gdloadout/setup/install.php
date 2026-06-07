<?php
namespace IPS\gdloadout\setup;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }

/* ── Hub template ── */
$hubContent = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPad" style="text-align:center">
	<h1>{lang="gdloadout_hub_title"}</h1>
	<p class="ipsType_light">{lang="gdloadout_hub_empty"}</p>
</div>
TEMPLATE_EOT;

try
{
	\IPS\Db::i()->replace( 'core_theme_templates', [
		'template_set_id'         => 1,
		'template_app'            => 'gdloadout',
		'template_location'       => 'front',
		'template_group'          => 'loadouts',
		'template_name'           => 'hub',
		'template_data'           => '',
		'template_content'        => $hubContent,
		'template_updated'        => time(),
		'template_version'        => '1.0.1',
		'template_master_key'     => '',
		'template_has_hookpoints' => 0,
	] );
}
catch ( \Throwable ) {}

/* ── Builder template ── */
$builderContent = <<<'TEMPLATE_EOT'
<div id="gdlo-builder" class="ipsColumns ipsColumns_collapsePhone">
	<div class="ipsColumn ipsColumn_wide">
		<div class="ipsBox ipsPad">
			<h2 class="ipsType_sectionTitle">{lang="gdloadout_builder_title"}</h2>
			<div id="gdlo-meta" class="ipsPad_half">
				<div class="ipsFieldRow">
					<label class="ipsFieldRow_label">Name</label>
					<input type="text" id="gdlo-name" class="ipsField_fullWidth" maxlength="150" placeholder="My Build" />
				</div>
				<div class="ipsFieldRow">
					<label class="ipsFieldRow_label">Description</label>
					<textarea id="gdlo-desc" class="ipsField_fullWidth" rows="2" maxlength="2000"></textarea>
				</div>
				<div class="ipsFieldRow">
					<label class="ipsFieldRow_label">Use Case</label>
					<input type="text" id="gdlo-usecase" class="ipsField_fullWidth" maxlength="100" placeholder="Home Defense, Range Day, etc." />
				</div>
				<div class="ipsFieldRow">
					<label class="ipsFieldRow_label">Visibility</label>
					<select id="gdlo-visibility" class="ipsField_fullWidth">
						<option value="public">Public</option>
						<option value="unlisted" selected>Unlisted</option>
					</select>
				</div>
			</div>
			<h3 class="ipsType_sectionTitle">Slots</h3>
			<div id="gdlo-slots"></div>
			<h3 class="ipsType_sectionTitle">Extras</h3>
			<div id="gdlo-extras"></div>
			<button id="gdlo-add-extra" class="ipsButton ipsButton_small ipsButton_light">+ Add Extra</button>
		</div>
		<div class="ipsBox ipsPad ipsMargin_top">
			<h3 class="ipsType_sectionTitle">Search Products</h3>
			<input type="text" id="gdlo-search-input" class="ipsField_fullWidth" placeholder="Search by name, UPC, or brand..." />
			<div id="gdlo-search-results" class="ipsMargin_top:half"></div>
		</div>
	</div>
	<div class="ipsColumn ipsColumn_narrow">
		<div class="ipsBox ipsPad" id="gdlo-summary">
			<h3 class="ipsType_sectionTitle">Build Summary</h3>
			<div id="gdlo-summary-count" class="ipsPad_half">
				<strong>Items:</strong> <span id="gdlo-item-count">0</span>
			</div>
			<div id="gdlo-summary-cost" class="ipsPad_half">
				<strong>Est. Total:</strong> <span id="gdlo-total-cost">$0.00</span>
			</div>
			<div id="gdlo-slot-limit-notice" class="ipsMessage ipsMessage_info" style="display:none">
				Slot limit reached.
			</div>
			<div id="gdlo-nfa-notice" class="ipsMessage ipsMessage_warning" style="display:none">
				This build includes NFA items — additional regulations may apply.
			</div>
			<table class="ipsTable ipsTable_zebra" id="gdlo-item-table">
				<thead><tr><th>Item</th><th>Price</th><th></th></tr></thead>
				<tbody id="gdlo-item-tbody"></tbody>
			</table>
			<div class="ipsPad_top">
				<button id="gdlo-save" class="ipsButton ipsButton_primary ipsButton_fullWidth">Save Loadout</button>
			</div>
			<div id="gdlo-delete-wrap" class="ipsPad_top" style="display:none">
				<button id="gdlo-delete" class="ipsButton ipsButton_negative ipsButton_fullWidth ipsButton_small">Delete Loadout</button>
			</div>
		</div>
	</div>
</div>
<script type="application/json" id="gdlo-init">{expression="$initJson"}</script>
TEMPLATE_EOT;

try
{
	\IPS\Db::i()->replace( 'core_theme_templates', [
		'template_set_id'         => 1,
		'template_app'            => 'gdloadout',
		'template_location'       => 'front',
		'template_group'          => 'loadouts',
		'template_name'           => 'builder',
		'template_data'           => '$initJson',
		'template_content'        => $builderContent,
		'template_updated'        => time(),
		'template_version'        => '1.0.1',
		'template_master_key'     => '',
		'template_has_hookpoints' => 0,
	] );
}
catch ( \Throwable ) {}

/* ── Core menu seed (front nav link) ── */
try
{
	$hasRow = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_menu', [ 'app=?', 'gdloadout' ] )->first();
	if ( !$hasRow )
	{
		$maxPos = 0;
		try
		{
			$maxPos = (int) \IPS\Db::i()->select( 'MAX(position)', 'core_menu' )->first();
		}
		catch ( \Throwable ) {}

		\IPS\Db::i()->insert( 'core_menu', [
			'app'            => 'gdloadout',
			'extension'      => 'Loadouts',
			'config'         => '{}',
			'parent'         => 0,
			'position'       => $maxPos + 1,
			'permissions'    => '*',
			'is_menu_child'  => 0,
		] );
	}
}
catch ( \Throwable ) {}

/* ── Seed group-limits defaults ── */
try
{
	if ( \IPS\Db::i()->checkForTable( 'gd_loadout_group_limits' ) )
	{
		foreach ( \IPS\Db::i()->select( 'g_id', 'core_groups' ) as $gid )
		{
			try
			{
				$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_loadout_group_limits', [ 'group_id=?', (int) $gid ] )->first();
				if ( !$exists )
				{
					\IPS\Db::i()->insert( 'gd_loadout_group_limits', [
						'group_id'     => (int) $gid,
						'max_slots'    => 15,
						'max_loadouts' => 0,
					] );
				}
			}
			catch ( \Throwable ) {}
		}
	}
}
catch ( \Throwable ) {}

/* ── Clear nav caches ── */
try { unset( \IPS\Data\Store::i()->menu ); }              catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->frontNavigation ); }    catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
