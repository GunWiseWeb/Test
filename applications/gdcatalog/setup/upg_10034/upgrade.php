<?php
namespace IPS\gdcatalog\setup\upg_10034;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* gdcatalog v1.0.34 - Lock indicator column + editable image_url.
		 *
		 * Two small quality-of-life fixes:
		 *
		 *   1. Add image_url to edit() form's editableFields so broken image
		 *      URLs can be corrected. (PHP patch in products.php)
		 *
		 *   2. Add a "Lock" column to the product list between Status and
		 *      Image, showing a 🔒 icon for products with any locked fields.
		 *      Lets admin spot locked products without clicking into edit.
		 *      (PHP patch in products.php adds locked_fields_count to row
		 *      map; template reseed adds the column.)
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10033). */

		/* Step 1: Sanity check */
		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gdcatalog' ]
			)->first();

			$longVer = (int) ( $row['app_long_version'] ?? 0 );
			$msg = sprintf(
				'gdcatalog v1.0.34 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10034' ); } catch ( \Throwable ) {}

			if ( $longVer < 10033 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.34 WARNING: app_long_version=%d below 10033',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10034' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.34 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10034' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Reseed productList template.
		 *
		 * v1.0.31 baseline md5 485dd399f8747faeaffb1c1c425fd718 (5691 bytes).
		 * v1.0.34 changes: add Lock column to <thead> + corresponding cell in
		 * <tbody>. Everything else byte-identical. New template_data param:
		 * (none - locked_fields_count is a per-row field added to the array
		 * passed in as $products, not a separate template arg). */
		$productListTemplate = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">

		<div style="display:flex;gap:16px;margin-bottom:24px">
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{expression="number_format( $total )"}</div>
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

		<div style="display:flex;justify-content:flex-end;margin-bottom:12px">
			<a href="{$addProductUrl}" class="ipsButton ipsButton--primary ipsButton--small" title="Manually create a new product. Auto-locks all populated fields against distributor overwrites.">+ Add Product</a>
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
			<select name="image_status" class="ipsInput ipsInput--select" style="min-width:160px">
				<option value="">All images</option>
				<option value="missing" {{if $imageStatus === 'missing'}}selected{{endif}}>Missing URL</option>
				<option value="present" {{if $imageStatus === 'present'}}selected{{endif}}>Has URL</option>
				<option value="broken" {{if $imageStatus === 'broken'}}selected{{endif}}>Broken (404)</option>
				<option value="ok" {{if $imageStatus === 'ok'}}selected{{endif}}>Verified OK</option>
				<option value="unchecked" {{if $imageStatus === 'unchecked'}}selected{{endif}}>Never Checked</option>
			</select>
			<select name="category" class="ipsInput ipsInput--select" style="min-width:160px">
				<option value="0">All categories</option>
				{{foreach $categories as $cat}}
					<option value="{$cat['id']}" {{if $catId === $cat['id']}}selected{{endif}}>{$cat['name']}</option>
				{{endforeach}}
			</select>
			<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Filter</button>
		</form>

		{{if $productCount === 0}}
			<div class="ipsEmptyMessage"><p>{lang="gdcatalog_products_empty"}</p></div>
		{{else}}
		<table class="ipsTable ipsTable_zebra" style="width:100%">
			<thead>
				<tr>
					<th>{lang="gdcatalog_product_upc"}</th>
					<th>{lang="gdcatalog_product_title"}</th>
					<th>{lang="gdcatalog_product_brand"}</th>
					<th>{lang="gdcatalog_product_caliber"}</th>
					<th>{lang="gdcatalog_product_msrp"}</th>
					<th>{lang="gdcatalog_product_status"}</th>
					<th title="Locked fields - distributor imports cannot overwrite locked fields" style="text-align:center;width:48px">Lock</th>
					<th>Image</th>
					<th>{lang="gdcatalog_product_primary_source"}</th>
					<th style="width:180px">Actions</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $products as $product}}
				<tr>
					<td><code>{$product['upc']}</code></td>
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
					<td style="text-align:center">
						{{if $product['locked_fields_count'] > 0}}
							<span title="{$product['locked_fields_count']} field(s) locked" style="font-size:1.2em;color:#b45309">🔒<span style="font-size:0.7em;color:#555;margin-left:2px">{$product['locked_fields_count']}</span></span>
						{{else}}
							<span style="color:#ccc">—</span>
						{{endif}}
					</td>
					<td>
						{{if empty( $product['image_url'] )}}
							<span class="ipsBadge ipsBadge--negative" title="No image URL">Missing</span>
						{{elseif $product['image_validated'] === null}}
							<span class="ipsBadge ipsBadge--neutral" title="Not yet validated">?</span>
						{{elseif $product['image_http_status'] >= 200 && $product['image_http_status'] < 400}}
							<span class="ipsBadge ipsBadge--positive" title="HTTP {$product['image_http_status']}">OK</span>
						{{else}}
							<span class="ipsBadge ipsBadge--negative" title="HTTP {$product['image_http_status']}">Broken</span>
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
		{{endif}}

		{{if !empty( $pagination )}}
		<div style="margin-top:16px">{$pagination|raw}</div>
		{{endif}}

	</div>
</div>
TEMPLATE_EOT;

		try
		{
			$masterKey = md5( 'gdcatalog;admin;catalog;productList' );

			\IPS\Db::i()->delete( 'core_theme_templates', [
				'template_app=? AND template_name=? AND template_location=?',
				'gdcatalog', 'productList', 'admin'
			] );

			\IPS\Db::i()->insert( 'core_theme_templates', [
				'template_set_id'         => 1,
				'template_group'          => 'catalog',
				'template_content'        => $productListTemplate,
				'template_name'           => 'productList',
				'template_data'           => '$products, $categories, $search, $status, $catId, $imageStatus, $total, $pagination, $formActionUrl, $productCount, $categoryCount, $addProductUrl',
				'template_updated'        => time(),
				'template_master_key'     => $masterKey,
				'template_location'       => 'admin',
				'template_app'            => 'gdcatalog',
				'template_version'        => '1.0.34',
				'template_has_hookpoints' => 0,
			] );

			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.34 reseeded productList template with Lock column (%d bytes)', strlen( $productListTemplate ) ), 'gdcatalog_upg_10034' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.34 productList reseed FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10034' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Cache invalidation */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/*.php' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/static/templates/*.php' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'gdcatalog v1.0.34 - lock indicator column + editable image_url';
	}
}

class upgrade extends _upgrade {}
