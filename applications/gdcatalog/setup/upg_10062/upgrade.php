<?php
namespace IPS\gdcatalog\setup\upg_10062;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$newCols = [
			'trigger_type', 'grips', 'hammer_style', 'choke_config',
			'chamber', 'receiver_desc', 'bullet_type', 'bullet_weight',
			'muzzle_velocity', 'muzzle_energy', 'boxes_per_case',
			'casing_material', 'magnification', 'objective_mm', 'reticle',
			'tube_diameter', 'eye_relief', 'features', 'metal_finish',
			'frame_finish', 'gauge', 'weight_lbs',
		];

		foreach ( $newCols as $col )
		{
			$length = $col === 'weight_lbs' ? 50 : 255;
			try
			{
				\IPS\Db::i()->addColumn( 'gd_catalog', [
					'name'       => $col,
					'type'       => 'VARCHAR',
					'length'     => $length,
					'allow_null' => true,
					'default'    => null,
				] );
			}
			catch ( \Throwable ) {}
		}

		return TRUE;
	}

	public function step2(): bool
	{
		try
		{
			\IPS\Task\Queue::queue( 'gdcatalog', 'BackfillAttributes', [ 'offset' => 0 ] );
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
