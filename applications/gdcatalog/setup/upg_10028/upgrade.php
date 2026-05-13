<?php
namespace IPS\gdcatalog\setup\upg_10028;

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
		/* gdcatalog v1.0.28 - Image validation + productList UI.
		 *
		 * Schema additions:
		 *   image_validated     TINYINT(1) NULL - 1 if HEAD-checked, 0 if pending, NULL = never checked
		 *   image_validated_at  DATETIME NULL  - when last HEAD-checked
		 *   image_http_status   SMALLINT NULL  - HTTP status code from last HEAD (200, 404, etc)
		 *   idx_image_validated (image_validated, image_validated_at) - for finding next batch
		 *
		 * Settings:
		 *   gdcatalog_image_validation_batch_size  = 2000 (per task run, hourly)
		 *   gdcatalog_image_validation_revalidate_days = 30 (re-check after this many days)
		 *
		 * Task registration:
		 *   ValidateProductImages - PT1H (every hour, processes 2000 oldest-validated)
		 *
		 * Template reseed:
		 *   productList - adds image_status dropdown filter to existing form,
		 *                 preserves all existing functionality
		 *
		 * The reseed embeds exact bytes captured from the working DB template
		 * on 2026-05-11 with the ONE addition of a new <select> for image_status.
		 *
		 * Per CLAUDE.md rule #51: sanity check vs PREVIOUS version (10027). */

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
				'gdcatalog v1.0.28 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}

			if ( $longVer < 10027 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.28 WARNING: app_long_version=%d below 10027',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.28 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Add image validation columns. Introspect first. */
		try
		{
			$prefix = \IPS\Db::i()->prefix;
			$describe = \IPS\Db::i()->query( "DESCRIBE {$prefix}gd_catalog" );
			$existingCols = [];
			foreach ( $describe as $c )
			{
				$existingCols[] = (string) ( $c['Field'] ?? '' );
			}

			$toAdd = [
				'image_validated'    => "ADD COLUMN image_validated TINYINT(1) NULL DEFAULT NULL AFTER additional_images",
				'image_validated_at' => "ADD COLUMN image_validated_at DATETIME NULL DEFAULT NULL AFTER image_validated",
				'image_http_status'  => "ADD COLUMN image_http_status SMALLINT NULL DEFAULT NULL AFTER image_validated_at",
			];

			foreach ( $toAdd as $col => $ddl )
			{
				if ( !in_array( $col, $existingCols, true ) )
				{
					try
					{
						\IPS\Db::i()->query( "ALTER TABLE {$prefix}gd_catalog {$ddl}" );
						try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.28 added column gd_catalog.%s', $col ), 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
					}
					catch ( \Throwable $e )
					{
						try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.28 column add FAILED for %s: %s', $col, $e->getMessage() ), 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
					}
				}
			}

			/* Add composite index for "oldest pending validation" queries */
			$indexCheck = \IPS\Db::i()->query( "SHOW INDEX FROM {$prefix}gd_catalog WHERE Key_name = 'idx_image_validated'" );
			$hasIndex = FALSE;
			foreach ( $indexCheck as $r )
			{
				$hasIndex = TRUE;
				break;
			}

			if ( !$hasIndex )
			{
				try
				{
					\IPS\Db::i()->query( "ALTER TABLE {$prefix}gd_catalog ADD INDEX idx_image_validated (image_validated, image_validated_at)" );
					try { \IPS\Log::log( 'gdcatalog v1.0.28 added idx_image_validated', 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.28 schema add failed: ' . $e->getMessage(), 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Insert settings. Schema introspect with fallback. */
		$settingsToInsert = [
			'gdcatalog_image_validation_batch_size' => '2000',
			'gdcatalog_image_validation_revalidate_days' => '30',
		];

		try
		{
			$prefix = \IPS\Db::i()->prefix;
			$describe = \IPS\Db::i()->query( "DESCRIBE {$prefix}core_sys_conf_settings" );
			$cols = [];
			foreach ( $describe as $c )
			{
				$cols[] = (string) ( $c['Field'] ?? '' );
			}

			foreach ( $settingsToInsert as $key => $default )
			{
				$exists = (int) \IPS\Db::i()->select(
					'COUNT(*)',
					'core_sys_conf_settings',
					[ 'conf_key=?', $key ]
				)->first();

				if ( $exists === 0 )
				{
					$row = [];
					if ( in_array( 'conf_key',     $cols, true ) ) $row['conf_key']     = $key;
					if ( in_array( 'conf_value',   $cols, true ) ) $row['conf_value']   = $default;
					if ( in_array( 'conf_default', $cols, true ) ) $row['conf_default'] = $default;
					if ( in_array( 'conf_app',     $cols, true ) ) $row['conf_app']     = 'gdcatalog';

					if ( !empty( $row ) )
					{
						\IPS\Db::i()->insert( 'core_sys_conf_settings', $row );
						try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.28 inserted setting %s=%s', $key, $default ), 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.28 settings insert failed: ' . $e->getMessage(), 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
		}

		/* Step 4: Register ValidateProductImages task in core_tasks */
		try
		{
			$exists = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				'core_tasks',
				[ '`key`=? AND app=?', 'ValidateProductImages', 'gdcatalog' ]
			)->first();

			if ( $exists === 0 )
			{
				\IPS\Db::i()->insert( 'core_tasks', [
					'app'       => 'gdcatalog',
					'key'       => 'ValidateProductImages',
					'frequency' => 'PT1H',
					'next_run'  => time() + 3600,
					'last_run'  => 0,
					'enabled'   => 1,
					'running'   => 0,
				] );
				try { \IPS\Log::log( 'gdcatalog v1.0.28 registered ValidateProductImages task (hourly)', 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.28 task registration failed: ' . $e->getMessage(), 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
		}

		/* Step 5: Reseed productList template.
		 *
		 * Exact byte content matches /tmp/productList_current.html (md5
		 * ddc2e392bdc87c84d1b9a374b753e49e) WITH ONE ADDITION: a new
		 * <select name="image_status"> dropdown between the status select
		 * and the category select. Plus a new <th>Image</th> column showing
		 * a small badge for validation state.
		 *
		 * template_data updated to include $imageStatus. */
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
				'template_data'           => '$products, $categories, $search, $status, $catId, $imageStatus, $total, $pagination, $formActionUrl, $productCount, $categoryCount',
				'template_updated'        => time(),
				'template_master_key'     => $masterKey,
				'template_location'       => 'admin',
				'template_app'            => 'gdcatalog',
				'template_version'        => '1.0.28',
				'template_has_hookpoints' => 0,
			] );

			try { \IPS\Log::log( sprintf( 'gdcatalog v1.0.28 reseeded productList template (%d bytes)', strlen( $productListTemplate ) ), 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.28 template reseed FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10028' ); } catch ( \Throwable ) {}
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
		return 'gdcatalog v1.0.28 - image validation + productList UI';
	}
}

class upgrade extends _upgrade {}
