<?php
namespace IPS\gdcatalog\setup\upg_10031;

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
		/* gdcatalog v1.0.31 - Manual product entry + per-product lock-all.
		 *
		 * Three new capabilities:
		 *   1. Add Product button on product list -> opens a blank edit form
		 *      with editable UPC. Saves as primary_source='manual'.
		 *   2. On manual create: every populated field is auto-locked via
		 *      $product->lockField(). The existing ConflictResolver checks
		 *      isFieldLocked() and routes any incoming distributor change to
		 *      gd_feed_conflicts for admin review (or 48hr auto-resolve).
		 *   3. Edit form gets "Lock All Fields" + "Unlock All Fields" buttons
		 *      that iterate every populated field on the current product and
		 *      lockField/unlockField each one. One click to trust an entire
		 *      record or release all locks.
		 *
		 * Template reseed:
		 *   - productList: same verified bytes from v1.0.28 (md5
		 *     a checked at install time) plus a new "Add Product" button
		 *     above the filter form.
		 *
		 * No schema changes. No FieldLock API additions. The existing
		 * Product->locked_fields JSON column is already wired into
		 * ConflictResolver - we just feed it from new UI surfaces.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10030). */

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
				'gdcatalog v1.0.31 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10031' ); } catch ( \Throwable ) {}

			if ( $longVer < 10030 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.31 WARNING: app_long_version=%d below 10030',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10031' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.31 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10031' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Reseed productList template.
		 *
		 * The base template is the exact v1.0.28 bytes (which themselves are
		 * verified against /tmp/productList_current.html md5
		 * ddc2e392bdc87c84d1b9a374b753e49e from before v1.0.28).
		 *
		 * v1.0.31 adds ONE element: an "Add Product" button at the top of the
		 * filter form area, before the search input. Everything else byte-
		 * identical to v1.0.28. */
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
				'template_version'        => '1.0.31',
				'template_has_hookpoints' => 0,
			] );

			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.31 reseeded productList template with Add Product button (%d bytes)', strlen( $productListTemplate ) ), 'gdcatalog_upg_10031' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.31 productList reseed FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10031' ); } catch ( \Throwable ) {}
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
		return 'gdcatalog v1.0.31 - manual product entry + per-product lock-all';
	}
}

class upgrade extends _upgrade {}
