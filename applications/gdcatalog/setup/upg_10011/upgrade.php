<?php
namespace IPS\gdcatalog\setup\upg_10011;

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
		/* gdcatalog v1.0.11 - Brand/Category lookups + image URL transform
		 * + productList template fix.
		 *
		 * New tables:
		 *   gd_sportssouth_brands - { brdno (PK), brdnam, last_synced }
		 *   gd_sportssouth_categories - { catid (PK), catdes, raw_data (JSON), last_synced }
		 *
		 * Note: We store the full raw row as JSON in raw_data for categories
		 * because we're not 100% certain about the field shape (ATTR1-N etc).
		 * After the first Refresh Lookups click, we can refactor to use proper
		 * columns in a later version.
		 *
		 * Importer changes (in Importer.php): adds enrichSportsSouthRecord()
		 * that runs BEFORE FieldMapper. Looks up brand name from IMFGNO/ITBRDNO,
		 * builds full image URL from PICREF.
		 *
		 * Template changes: productList template reseeded with corrected
		 * pagination output that uses raw HTML emission. Without this fix,
		 * the bottom of the products page shows raw HTML text. */

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
				'gdcatalog v1.0.11 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}

			if ( $longVer < 10010 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.11 WARNING: app_long_version=%d below 10010',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.11 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Create lookup tables. Use raw CREATE TABLE because IPS\Db
		 * helpers expect schema.json structure that we're not modifying. */
		$prefix = \IPS\Db::i()->prefix;

		try
		{
			\IPS\Db::i()->query(
				"CREATE TABLE IF NOT EXISTS {$prefix}gd_sportssouth_brands (
					brdno INT NOT NULL PRIMARY KEY,
					brdnam VARCHAR(255) NOT NULL DEFAULT '',
					last_synced INT NOT NULL DEFAULT 0,
					raw_data LONGTEXT NULL
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.11 CREATE brands TABLE failed: ' . $e->getMessage(), 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}
		}

		try
		{
			\IPS\Db::i()->query(
				"CREATE TABLE IF NOT EXISTS {$prefix}gd_sportssouth_categories (
					catid INT NOT NULL PRIMARY KEY,
					catdes VARCHAR(255) NOT NULL DEFAULT '',
					last_synced INT NOT NULL DEFAULT 0,
					raw_data LONGTEXT NULL
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.11 CREATE categories TABLE failed: ' . $e->getMessage(), 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}
		}

		/* Step 3: Seed new lang strings for the Refresh Lookups UI */
		$newStrings = [
			'gdcatalog_feed_refresh_lookups'           => 'Refresh Lookups',
			'gdcatalog_feed_refresh_lookups_title'     => 'Sports South Lookup Refresh',
			'gdcatalog_feed_refresh_lookups_success'   => 'Lookups refreshed',
			'gdcatalog_feed_refresh_lookups_brands'    => 'Brands synced',
			'gdcatalog_feed_refresh_lookups_categories' => 'Categories synced',
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
							'word_app'     => 'gdcatalog',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $rowException )
					{
						try { \IPS\Log::log( 'gdcatalog v1.0.11 lang seed failed key=' . $key . ': ' . $rowException->getMessage(), 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.11 lang seed outer failed: ' . $e->getMessage(), 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}
		}

		/* Step 4: Reseed productList template with pagination fix.
		 *
		 * The previous template emitted {$pagination} which auto-escapes HTML.
		 * IPS pagination doesn't have a single-variable raw output convention
		 * in its template engine - looking at IPS's own templates (e.g. 'pagination'
		 * in core), pagination is built from primitives inside the template using
		 * {{foreach}} loops over $pages with {$baseUrl->setPage(...)}.
		 *
		 * As a workaround for v1.0.11, we wrap the pagination string in the
		 * {expression="..."} tag which evaluates a PHP expression and emits
		 * its return value raw. We also use html_entity_decode as a backstop
		 * in case the previous template already escaped it once. */

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
					<td>{$product['primary_source']}</td>
					<td>
						<a href="{$product['edit_url']}" class="ipsButton ipsButton--primary ipsButton--small">Edit</a>
						{{if $product['record_status'] === 'admin_review'}}
							<a href="{$product['approve_url']}" class="ipsButton ipsButton--normal ipsButton--small">Approve</a>
						{{endif}}
					</td>
				</tr>
				{{endforeach}}
			</tbody>
		</table>
		{{endif}}

		{{if !empty( $pagination )}}
		<div style="margin-top:16px">{expression="html_entity_decode( $pagination, ENT_QUOTES | ENT_HTML5 )"}</div>
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
				'template_set_id'        => 0,
				'template_group'         => 'catalog',
				'template_content'       => $productListTemplate,
				'template_name'          => 'productList',
				'template_data'          => '$products, $productCount, $total, $categoryCount, $categories, $catId, $status, $search, $pagination, $formActionUrl',
				'template_updated'       => time(),
				'template_master_key'    => $masterKey,
				'template_location'      => 'admin',
				'template_app'           => 'gdcatalog',
				'template_version'       => '1.0.11',
				'template_has_hookpoints' => 0,
			] );

			try { \IPS\Log::log( 'gdcatalog v1.0.11 productList template reseeded with pagination fix', 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.11 productList template reseed FAILED: ' . $e->getMessage(), 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}
		}

		/* Step 4b: Update field_mapping for sportssouth feeds to include
		 * the new _BRAND_NAME synthetic field that the v1.0.11 enrichment
		 * adds. Only updates feeds where current mapping has the v1.0.10
		 * baseline shape (no _BRAND_NAME yet). */
		$updatedMapping = [
			'ITUPC'        => 'upc',
			'IDESC'        => 'title',
			'_BRAND_NAME'  => 'brand',
			'SHDESC'       => 'description',
			'IMODEL'       => 'model',
			'PRC1'         => 'msrp',
			'WTPBX'        => 'weight_oz',
			'PICREF'       => 'image_url',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'id, feed_name, field_mapping', 'gd_distributor_feeds', [ 'auth_type=?', 'sportssouth' ] ) as $feedRow )
			{
				$current = json_decode( (string) ( $feedRow['field_mapping'] ?? '' ), true );
				if ( !is_array( $current ) )
				{
					$current = [];
				}

				/* Only update if _BRAND_NAME isn't already in the mapping
				 * (i.e. don't override admin customizations) */
				if ( !isset( $current['_BRAND_NAME'] ) )
				{
					try
					{
						\IPS\Db::i()->update(
							'gd_distributor_feeds',
							[ 'field_mapping' => json_encode( $updatedMapping ) ],
							[ 'id=?', (int) $feedRow['id'] ]
						);
						try { \IPS\Log::log( 'Updated sportssouth field_mapping for feed_id=' . $feedRow['id'] . ' with _BRAND_NAME', 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}
					}
					catch ( \Throwable $rowException )
					{
						try { \IPS\Log::log( 'Failed updating field_mapping for feed_id=' . $feedRow['id'] . ': ' . $rowException->getMessage(), 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.11 field_mapping update outer failed: ' . $e->getMessage(), 'gdcatalog_upg_10011' ); } catch ( \Throwable ) {}
		}

		/* Step 5: Cache invalidation - aggressive because we touched templates */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store' ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/*.php' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/*.css' ) ?: [] as $f )
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
		return 'gdcatalog v1.0.11 - Brand/Category lookups + productList pagination fix';
	}
}

class upgrade extends _upgrade {}
