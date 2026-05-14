<?php
namespace IPS\gdcatalog\setup\upg_10038;

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
		/* gdcatalog v1.0.38 - CSV Bulk Import (Simple + Advanced modes).
		 *
		 * Adds the ability to bulk-import products via CSV upload. Supports
		 * two modes:
		 *
		 *   1. Simple mode: CSV uses canonical column names (upc, title,
		 *      brand, mpn, etc) matching the v1.0.31 add() editableFields.
		 *      Headers read from row 1 automatically.
		 *
		 *   2. Advanced mode: CSV has arbitrary column names. After upload,
		 *      admin maps each CSV column to a canonical field via a
		 *      dropdown UI. Mapping submitted as JSON, then queued.
		 *
		 * Per-row logic:
		 *   - UPC missing → skip + log
		 *   - UPC doesn't exist → CREATE new product, NOT locked, primary_source='manual_csv'
		 *   - UPC exists → for each populated field that differs from current,
		 *     INSERT into gd_feed_conflicts (status='pending', auto_resolve_at
		 *     = NOW + 48h based on gdcatalog_auto_resolve_hours setting)
		 *
		 * Implementation:
		 *   - ALTER gd_distributor_feeds.auth_type ENUM to add 'csv'
		 *   - INSERT synthetic feed row with distributor='manual_csv' so
		 *     conflicts have a valid FK target (gd_feed_conflicts.distributor_id
		 *     is NOT NULL)
		 *   - New queue extension CsvBulkImport processes 100 rows/chunk
		 *   - 3 new products.php controller methods: importCsv, importCsvMap,
		 *     downloadCsvTemplate
		 *   - productList template reseed adds "Import CSV" + "Download
		 *     Template" buttons next to "+ Add Product"
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10037). */

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
				'gdcatalog v1.0.38 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}

			if ( $longVer < 10037 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.38 WARNING: app_long_version=%d below 10037',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.38 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
		}

		/* Step 2: ALTER ENUM gd_distributor_feeds.auth_type to add 'csv'.
		 *
		 * Idempotent - check current enum values first. */
		try
		{
			$col = \IPS\Db::i()->query(
				"SHOW COLUMNS FROM gd_distributor_feeds WHERE Field='auth_type'"
			)->fetch_assoc();

			$currentType = (string) ( $col['Type'] ?? '' );

			if ( strpos( $currentType, "'csv'" ) === false )
			{
				\IPS\Db::i()->query(
					"ALTER TABLE gd_distributor_feeds MODIFY auth_type ENUM('none','basic','apikey','ftp','sportssouth','csv') NOT NULL DEFAULT 'none'"
				);
				try { \IPS\Log::log( 'gdcatalog v1.0.38 ALTER enum auth_type added csv', 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
			}
			else
			{
				try { \IPS\Log::log( 'gdcatalog v1.0.38 enum auth_type already has csv, skipping ALTER', 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.38 ALTER enum FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Insert synthetic CSV feed row.
		 *
		 * Existence check first. If a row with distributor='manual_csv'
		 * exists, skip. Otherwise insert. */
		try
		{
			$existing = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				'gd_distributor_feeds',
				[ 'distributor=?', 'manual_csv' ]
			)->first();

			if ( $existing === 0 )
			{
				\IPS\Db::i()->insert( 'gd_distributor_feeds', [
					'feed_name'                 => 'Manual CSV Import',
					'distributor'               => 'manual_csv',
					'priority'                  => 99,
					'feed_url'                  => '',
					'feed_format'               => 'csv',
					'auth_type'                 => 'csv',
					'auth_credentials'          => null,
					'field_mapping'             => '{}',
					'category_mapping'          => null,
					'import_schedule'           => 'manual',
					'conflict_detection_fields' => '{}',
					'active'                    => 0,
					'last_run'                  => null,
					'last_record_count'         => 0,
					'last_run_status'           => null,
					'locked'                    => 0,
					'locked_at'                 => null,
					'locked_by'                 => null,
				] );

				try { \IPS\Log::log( 'gdcatalog v1.0.38 inserted synthetic manual_csv feed row', 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
			}
			else
			{
				try { \IPS\Log::log( 'gdcatalog v1.0.38 manual_csv feed already exists, skipping insert', 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.38 synthetic feed insert FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
		}

		/* Step 4: Insert language strings for CSV import UI. */
		$langStrings = [
			'gdcatalog_csv_import_title'        => 'Import Products from CSV',
			'gdcatalog_csv_upload'              => 'CSV File',
			'gdcatalog_csv_upload_desc'         => 'Upload a CSV file with product data. UPC column is required; other columns are optional.',
			'gdcatalog_csv_mode'                => 'Import Mode',
			'gdcatalog_csv_mode_desc'           => 'Simple: CSV uses canonical column names (upc, title, brand, mpn, etc). Advanced: map your CSV columns to fields after upload.',
			'gdcatalog_csv_mode_simple'         => 'Simple (canonical column names)',
			'gdcatalog_csv_mode_advanced'       => 'Advanced (custom column mapping)',
			'gdcatalog_csv_map_title'           => 'Map CSV Columns',
			'gdcatalog_csv_map_desc'            => 'Map each column in your CSV to a canonical field. Leave as "(ignore)" to skip a column. The UPC column is required.',
			'gdcatalog_csv_submit'              => 'Start Import',
			'gdcatalog_csv_map_submit'          => 'Run Import with Mapping',
			'gdcatalog_csv_download_template'   => 'Download CSV Template',
			'gdcatalog_csv_import_link'         => 'Import CSV',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langRow )
			{
				$langId = (int) $langRow['lang_id'];
				foreach ( $langStrings as $key => $default )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => $langId,
							'word_app'     => 'gdcatalog',
							'word_key'     => $key,
							'word_default' => $default,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.38 lang insert failed for %s: %s', $key, $e->getMessage() ), 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
					}
				}
			}
			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.38 inserted %d language strings', count( $langStrings ) ), 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.38 lang_id select failed: ' . $e->getMessage(), 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
		}

		/* Step 5: Reseed productList template.
		 *
		 * Baseline: v1.0.34 productList template (md5
		 * 582f10cfcc531236df65fbd114451250). v1.0.38 changes:
		 *   - Add "Import CSV" button next to "+ Add Product"
		 *   - Add "Download Template" link
		 * Everything else byte-identical to baseline. New template_data params:
		 * $importCsvUrl, $downloadCsvTemplateUrl appended to end. */
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

		<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:12px">
			<a href="{$downloadCsvTemplateUrl}" class="ipsButton ipsButton--secondary ipsButton--small" title="Download a blank CSV template with all canonical column names pre-filled in the header row.">⬇ CSV Template</a>
			<a href="{$importCsvUrl}" class="ipsButton ipsButton--secondary ipsButton--small" title="Bulk-import products from a CSV file. Creates new products and routes conflicts (UPCs already in catalog) to admin review.">Import CSV</a>
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
				'template_data'           => '$products, $categories, $search, $status, $catId, $imageStatus, $total, $pagination, $formActionUrl, $productCount, $categoryCount, $addProductUrl, $importCsvUrl, $downloadCsvTemplateUrl',
				'template_updated'        => time(),
				'template_master_key'     => $masterKey,
				'template_location'       => 'admin',
				'template_app'            => 'gdcatalog',
				'template_version'        => '1.0.38',
				'template_has_hookpoints' => 0,
			] );

			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.38 reseeded productList template with CSV import buttons (%d bytes)', strlen( $productListTemplate ) ), 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.38 productList reseed FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10038' ); } catch ( \Throwable ) {}
		}

		/* Step 6: Cache invalidation */
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
		return 'gdcatalog v1.0.38 - CSV bulk import (Simple + Advanced modes)';
	}
}

class upgrade extends _upgrade {}
