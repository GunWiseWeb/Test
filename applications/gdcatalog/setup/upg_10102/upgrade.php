<?php
namespace IPS\gdcatalog\setup\upg_10102;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$db = \IPS\Db::i();

		/* Carry forward from v1.0.97: optic columns (guarded) */
		$cols = [
			'optic_magnification' => 'VARCHAR(80) NULL DEFAULT NULL',
			'optic_objective'     => 'VARCHAR(80) NULL DEFAULT NULL',
		];
		foreach ( $cols as $name => $ddl )
		{
			try {
				if ( !$db->checkForColumn( 'gd_catalog', $name ) )
				{
					$db->query( "ALTER TABLE `" . $db->prefix . "gd_catalog` ADD COLUMN `{$name}` {$ddl}" );
				}
			} catch ( \Throwable ) {}
		}

		/* v1.0.98+: Seed new child categories (idempotent) */
		$seeds = [
			'Parts & Accessories' => [ 'Scope Rings', 'Upper Receivers', 'Lower Receivers' ],
			'Tactical Gear'       => [ 'Flashlights & Headlamps' ],
		];
		foreach ( $seeds as $parentName => $children )
		{
			try
			{
				$parentId = (int) $db->select( 'id', 'gd_categories', [ 'name=?', $parentName ] )->first();
			}
			catch ( \Throwable )
			{
				$parentId = 0;
			}
			if ( $parentId > 0 )
			{
				foreach ( $children as $childName )
				{
					try
					{
						$exists = (int) $db->select( 'COUNT(*)', 'gd_categories', [ 'name=? AND parent_id=?', $childName, $parentId ] )->first();
						if ( $exists === 0 )
						{
							$slug = strtolower( str_replace( [ ' ', '&' ], [ '-', 'and' ], $childName ) );
							$pos  = (int) $db->select( 'MAX(position)', 'gd_categories', [ 'parent_id=?', $parentId ] )->first() + 1;
							$db->insert( 'gd_categories', [
								'parent_id'      => $parentId,
								'name'           => $childName,
								'slug'           => $slug,
								'position'       => $pos,
								'product_count'  => 0,
							] );
						}
					}
					catch ( \Throwable ) {}
				}
			}
		}

		/* v1.0.101 + v1.0.102: Seed lang strings */
		$newStrings = [
			'gdcatalog_product_category_id'      => 'Category',
			'gdcatalog_bulk_selected'             => 'product(s) selected',
			'gdcatalog_bulk_select_all_matching'  => 'Select all matching',
			'gdcatalog_bulk_all_selected'         => 'All matching products selected',
			'gdcatalog_bulk_move_to'              => 'Move to',
			'gdcatalog_bulk_choose_category'      => '— Choose category —',
			'gdcatalog_bulk_apply'                => 'Apply',
		];
		try
		{
			foreach ( $db->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						$db->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdcatalog',
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

		/* v1.0.102: Reseed productList template with bulk select UI */
		try
		{
			$templateContent = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div style="display:flex;gap:8px;padding:10px 16px;border-bottom:1px solid var(--i-border-color, #e0e0e0)">
		<a href="{$addProductUrl}" class="ipsButton ipsButton--primary ipsButton--small">+ Add Product</a>
		<a href="{$importCsvUrl}" class="ipsButton ipsButton--secondary ipsButton--small">Import CSV</a>
		<a href="{$downloadCsvTemplateUrl}" class="ipsButton ipsButton--soft ipsButton--small">CSV Template</a>
	</div>
	<div class="ipsBox_body ipsPad">

		<div style="display:flex;gap:16px;margin-bottom:24px">
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{expression="number_format( (int) $total )"}</div>
				<div>Total Matching Products</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$productCount}</div>
				<div>Showing On This Page</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$categoryCount}</div>
				<div>Categories</div>
			</div>
		</div>

		<form method="get" action="{$formActionUrl}" style="display:flex;gap:8px;padding:12px 16px;border-bottom:1px solid var(--i-border-color, #e0e0e0);align-items:center;flex-wrap:wrap">
			<input type="hidden" name="app" value="gdcatalog">
			<input type="hidden" name="module" value="catalog">
			<input type="hidden" name="controller" value="products">
			<input type="text" name="q" value="{$search}" placeholder="Search UPC, title, or brand..." class="ipsInput ipsInput--text" style="flex:1;min-width:200px">
			<select name="status" class="ipsInput ipsInput--select" style="min-width:140px">
				<option value="">All statuses</option>
				<option value="active" {{if $status === 'active'}}selected{{endif}}>Active</option>
				<option value="discontinued" {{if $status === 'discontinued'}}selected{{endif}}>Discontinued</option>
				<option value="admin_review" {{if $status === 'admin_review'}}selected{{endif}}>Admin Review</option>
				<option value="pending" {{if $status === 'pending'}}selected{{endif}}>Pending</option>
			</select>
			<select name="category" class="ipsInput ipsInput--select" style="min-width:160px">
				<option value="0">All categories</option>
				{{foreach $categories as $cat}}
					<option value="{$cat['id']}" {{if $catId === $cat['id']}}selected{{endif}}>{$cat['name']}</option>
				{{endforeach}}
			</select>
			<select name="image_status" class="ipsInput ipsInput--select" style="min-width:160px">
				<option value="">All images</option>
				<option value="missing" {{if $imageStatus === 'missing'}}selected{{endif}}>No Image</option>
				<option value="broken" {{if $imageStatus === 'broken'}}selected{{endif}}>Broken Image</option>
				<option value="ok" {{if $imageStatus === 'ok'}}selected{{endif}}>Image OK</option>
				<option value="unchecked" {{if $imageStatus === 'unchecked'}}selected{{endif}}>Not Checked</option>
			</select>
			<select name="missing_field" class="ipsInput ipsInput--select" style="min-width:180px">
				<option value="">All products</option>
				<optgroup label="Firearms">
					<option value="caliber" {{if $missingField === 'caliber'}}selected{{endif}}>Missing: Caliber</option>
					<option value="capacity" {{if $missingField === 'capacity'}}selected{{endif}}>Missing: Capacity</option>
					<option value="action_type" {{if $missingField === 'action_type'}}selected{{endif}}>Missing: Action Type</option>
					<option value="barrel_length" {{if $missingField === 'barrel_length'}}selected{{endif}}>Missing: Barrel Length</option>
					<option value="weight_lbs" {{if $missingField === 'weight_lbs'}}selected{{endif}}>Missing: Weight</option>
					<option value="overall_length" {{if $missingField === 'overall_length'}}selected{{endif}}>Missing: Overall Length</option>
					<option value="gun_type" {{if $missingField === 'gun_type'}}selected{{endif}}>Missing: Gun Type</option>
					<option value="safety_type" {{if $missingField === 'safety_type'}}selected{{endif}}>Missing: Safety</option>
					<option value="sight_type" {{if $missingField === 'sight_type'}}selected{{endif}}>Missing: Sights</option>
					<option value="stock_material" {{if $missingField === 'stock_material'}}selected{{endif}}>Missing: Stock</option>
					<option value="trigger_type" {{if $missingField === 'trigger_type'}}selected{{endif}}>Missing: Trigger</option>
					<option value="gauge" {{if $missingField === 'gauge'}}selected{{endif}}>Missing: Gauge</option>
				</optgroup>
				<optgroup label="Ammo">
					<option value="bullet_type" {{if $missingField === 'bullet_type'}}selected{{endif}}>Missing: Bullet Type</option>
					<option value="rounds_per_box" {{if $missingField === 'rounds_per_box'}}selected{{endif}}>Missing: Rounds/Box</option>
				</optgroup>
				<optgroup label="Optics">
					<option value="magnification" {{if $missingField === 'magnification'}}selected{{endif}}>Missing: Magnification</option>
				</optgroup>
				<optgroup label="General">
					<option value="image_url" {{if $missingField === 'image_url'}}selected{{endif}}>Missing: Image</option>
					<option value="brand" {{if $missingField === 'brand'}}selected{{endif}}>Missing: Brand</option>
					<option value="mpn" {{if $missingField === 'mpn'}}selected{{endif}}>Missing: MPN</option>
					<option value="metal_finish" {{if $missingField === 'metal_finish'}}selected{{endif}}>Missing: Finish</option>
				</optgroup>
			</select>
			<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Filter</button>
		</form>

		{{if $productCount === 0}}
			<div class="ipsEmptyMessage"><p>{lang="gdcatalog_products_empty"}</p></div>
		{{else}}
		<form method="post" action="{$bulkMoveUrl}" id="gdBulkForm">
			<input type="hidden" name="csrfKey" value="{$csrfKey}">
			<input type="hidden" name="bulk_mode" value="selected" id="gdBulkMode">

			<div id="gdBulkBar" style="display:none;padding:12px 16px;background:var(--i-color-info-bg, #e8f4fd);border:1px solid var(--i-color-info-border, #bee5eb);border-radius:4px;margin-bottom:12px;align-items:center;gap:10px;flex-wrap:wrap">
				<span><strong id="gdBulkCount">0</strong> {lang="gdcatalog_bulk_selected"}</span>
				<span id="gdBulkAllWrap" style="display:none">
					&mdash; <a href="#" id="gdBulkSelectAll">{lang="gdcatalog_bulk_select_all_matching"} (<span id="gdBulkTotalMatching">{$totalMatching}</span>)</a>
					<span id="gdBulkAllLabel" style="display:none">&mdash; {lang="gdcatalog_bulk_all_selected"}</span>
				</span>
				<span style="margin-left:auto;display:flex;gap:8px;align-items:center">
					<label>{lang="gdcatalog_bulk_move_to"}:</label>
					<select name="bulk_target_category" class="ipsInput ipsInput--select" style="min-width:200px">
						<option value="0">{lang="gdcatalog_bulk_choose_category"}</option>
						{{foreach $catOptions as $groupName => $groupChildren}}
							<optgroup label="{$groupName}">
								{{foreach $groupChildren as $cid => $cname}}
									<option value="{$cid}">{$cname}</option>
								{{endforeach}}
							</optgroup>
						{{endforeach}}
					</select>
					<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">{lang="gdcatalog_bulk_apply"}</button>
				</span>
			</div>

		<table class="ipsTable ipsTable_zebra" style="width:100%" id="gdBulkTable" data-total-matching="{$totalMatching}">
			<thead>
				<tr>
					<th style="width:40px"><input type="checkbox" id="gdMasterCheckbox"></th>
					<th>{lang="gdcatalog_product_upc"}</th>
					<th style="width:60px">Image</th>
					<th>{lang="gdcatalog_product_title"}</th>
					<th>{lang="gdcatalog_product_brand"}</th>
					<th>{lang="gdcatalog_product_caliber"}</th>
					<th>{lang="gdcatalog_product_msrp"}</th>
					<th>{lang="gdcatalog_product_status"}</th>
					<th>Missing</th>
					<th>{lang="gdcatalog_product_primary_source"}</th>
					<th style="width:180px">Actions</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $products as $product}}
				<tr>
					<td><input type="checkbox" name="bulk_upc[]" value="{$product['upc']}" class="gdBulkCheckbox"></td>
					<td><code>{$product['upc']}</code></td>
					<td>
						{{if $product['image_url']}}
							{{if $product['image_http_status'] >= 400}}
								<span class="ipsBadge ipsBadge--negative" title="Broken image">&#10007;</span>
							{{else}}
								<img src="{$product['image_url']}" style="width:40px;height:40px;object-fit:contain" loading="lazy">
							{{endif}}
						{{else}}
							<span class="ipsBadge ipsBadge--neutral" title="No image">&mdash;</span>
						{{endif}}
					</td>
					<td>{$product['title']}</td>
					<td>{$product['brand']}</td>
					<td>{$product['caliber']}</td>
					<td>{$product['msrp']}</td>
					<td>
						{{if $product['record_status'] === 'active'}}
							<span class="ipsBadge ipsBadge--positive">{lang="gdcatalog_status_active"}</span>
						{{elseif $product['record_status'] === 'admin_review'}}
							<span class="ipsBadge ipsBadge--warning">{lang="gdcatalog_status_admin_review"}</span>
						{{elseif $product['record_status'] === 'discontinued'}}
							<span class="ipsBadge ipsBadge--negative">{lang="gdcatalog_status_discontinued"}</span>
						{{else}}
							<span class="ipsBadge ipsBadge--neutral">{lang="gdcatalog_status_pending"}</span>
						{{endif}}
					</td>
					<td style="font-size:0.75em">
						{{if count($product['missing_fields']) === 0}}
							<span class="ipsBadge ipsBadge--positive">Complete</span>
						{{else}}
							{{foreach $product['missing_fields'] as $mf}}
							<span class="ipsBadge ipsBadge--warning" style="margin:1px">{$mf}</span>
							{{endforeach}}
						{{endif}}
					</td>
					<td>{$product['primary_source']}</td>
					<td>
						<a href="{$product['edit_url']}" class="ipsButton ipsButton--primary ipsButton--small">Edit</a>
						{{if $product['record_status'] === 'admin_review'}}
							<a href="{$product['approve_url']}" class="ipsButton ipsButton--secondary ipsButton--small">Approve</a>
						{{endif}}
					</td>
				</tr>
				{{endforeach}}
			</tbody>
		</table>
		</form>
		{{endif}}

		<div style="margin-top:16px">{$pagination|raw}</div>

	</div>
</div>
TEMPLATE_EOT;

			$db->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gdcatalog',
				'template_location'=> 'admin',
				'template_group'   => 'catalog',
				'template_name'    => 'productList',
				'template_data'    => '$products, $categories, $search, $status, $catId, $imageStatus, $total, $pagination, $formActionUrl, $productCount, $categoryCount, $addProductUrl, $importCsvUrl, $downloadCsvTemplateUrl, $missingField, $catOptions, $bulkMoveUrl, $csrfKey, $totalMatching',
				'template_content' => $templateContent,
				'template_updated' => time(),
				'template_version' => '1.0.102',
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
