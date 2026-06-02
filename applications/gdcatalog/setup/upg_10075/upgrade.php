<?php
namespace IPS\gdcatalog\setup\upg_10075;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$strings = [
			'gdcatalog_product_gun_type'        => 'Gun Type',
			'gdcatalog_product_action_type'     => 'Action Type',
			'gdcatalog_product_safety_type'     => 'Safety',
			'gdcatalog_product_trigger_type'    => 'Trigger',
			'gdcatalog_product_caliber'         => 'Caliber',
			'gdcatalog_product_capacity'        => 'Capacity',
			'gdcatalog_product_barrel_length'   => 'Barrel Length',
			'gdcatalog_product_overall_length'  => 'Overall Length',
			'gdcatalog_product_weight_lbs'      => 'Weight (lbs)',
			'gdcatalog_product_gauge'           => 'Gauge',
			'gdcatalog_product_finish'          => 'Finish',
			'gdcatalog_product_metal_finish'    => 'Metal Finish',
			'gdcatalog_product_frame_finish'    => 'Frame Finish',
			'gdcatalog_product_stock_material'  => 'Stock Material',
			'gdcatalog_product_stock_type'      => 'Stock Type',
			'gdcatalog_product_sight_type'      => 'Sights',
			'gdcatalog_product_grips'           => 'Grips',
			'gdcatalog_product_hammer_style'    => 'Hammer Style',
			'gdcatalog_product_choke_config'    => 'Choke Configuration',
			'gdcatalog_product_chamber'         => 'Chamber',
			'gdcatalog_product_receiver_desc'   => 'Receiver Description',
			'gdcatalog_product_receiver_type'   => 'Receiver Type',
			'gdcatalog_product_frame_material'  => 'Frame Material',
			'gdcatalog_product_slide_material'  => 'Slide Material',
			'gdcatalog_product_bullet_type'     => 'Bullet Type',
			'gdcatalog_product_bullet_weight'   => 'Bullet Weight',
			'gdcatalog_product_muzzle_velocity' => 'Muzzle Velocity',
			'gdcatalog_product_muzzle_energy'   => 'Muzzle Energy',
			'gdcatalog_product_rounds_per_box'  => 'Rounds Per Box',
			'gdcatalog_product_boxes_per_case'  => 'Boxes Per Case',
			'gdcatalog_product_casing_material' => 'Casing Material',
			'gdcatalog_product_magnification'   => 'Magnification',
			'gdcatalog_product_objective_mm'    => 'Objective (mm)',
			'gdcatalog_product_reticle'         => 'Reticle',
			'gdcatalog_product_tube_diameter'   => 'Tube Diameter',
			'gdcatalog_product_eye_relief'      => 'Eye Relief',
			'gdcatalog_product_features'        => 'Features',
			'gdcatalog_product_title'           => 'Title',
			'gdcatalog_product_mpn'             => 'MPN / Manufacturer SKU',
			'gdcatalog_product_brand'           => 'Brand',
			'gdcatalog_product_manufacturer'    => 'Manufacturer',
			'gdcatalog_product_importer'        => 'Importer',
			'gdcatalog_product_model'           => 'Model',
			'gdcatalog_product_image_url'       => 'Image URL',
			'gdcatalog_product_msrp'            => 'MSRP',
			'gdcatalog_product_description'     => 'Description',
			'gdcatalog_product_type_group'      => 'Product Type',
			'gdcatalog_product_type_firearm'    => 'Firearm',
			'gdcatalog_product_type_shotgun'    => 'Shotgun',
			'gdcatalog_product_type_ammo'       => 'Ammunition',
			'gdcatalog_product_type_optic'      => 'Optic',
			'gdcatalog_product_type_magazine'   => 'Magazine',
			'gdcatalog_product_type_accessory'  => 'Accessory / Other',
		];

		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $strings as $key => $val )
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

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
