<?php
namespace IPS\gdcatalog\setup\upg_10063;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$newStrings = [
			'gdcatalog_product_not_found'  => 'Product not found.',
			'gdcatalog_upc_edit_failed'    => 'Failed to change UPC. The new UPC may already exist.',
			'gdcatalog_product_saved'      => 'Product saved.',
			'gdcatalog_edit_product_title' => 'Edit Product',
			'gdcatalog_edit_upc'           => 'UPC',
			'gdcatalog_edit_title'         => 'Title',
			'gdcatalog_edit_brand'         => 'Brand',
			'gdcatalog_edit_caliber'       => 'Caliber',
			'gdcatalog_edit_model'         => 'Model',
			'gdcatalog_edit_mpn'           => 'MPN / Manufacturer SKU',
			'gdcatalog_edit_msrp'          => 'MSRP',
			'gdcatalog_edit_status'        => 'Status',
			'gdcatalog_edit_description'   => 'Description',
			'gdcatalog_edit_image_url'     => 'Image URL',
		];

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
				catch ( \Throwable ) {}
			}
		}

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gdcatalog',
				'template_location'=> 'admin',
				'template_group'   => 'catalog',
				'template_name'    => 'editProductForm',
				'template_data'    => '$form, $product',
				'template_content' => '<div class="ipsBox ipsPull">
	<div style="padding:10px 16px;border-bottom:1px solid var(--i-border-color, #e0e0e0)">
		<h2 class="ipsType_sectionHead" style="margin:0">Edit Product: {$product[\'upc\']}</h2>
	</div>
	<div class="ipsBox_body ipsPad">
		<div class="ipsMessage ipsMessage--info" style="margin-bottom:16px">
			<p>Changing the UPC will delete the existing product record and create a new one with the new UPC. All other data will be preserved.</p>
		</div>
		{$form|raw}
	</div>
</div>',
				'template_updated'        => time(),
				'template_version'        => '1.0.63',
				'template_has_hookpoints' => 0,
			] );
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
