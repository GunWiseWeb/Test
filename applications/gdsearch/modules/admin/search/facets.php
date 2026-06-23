<?php
namespace IPS\gdsearch\modules\admin\search;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _facets extends \IPS\Dispatcher\Controller
{
	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'facets_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$groups = [
			'Brand'    => [ 'brands' => 'Brand' ],
			'Firearm'  => [ 'calibers'=>'Caliber','actions'=>'Action','capacities'=>'Capacity','barrel_present'=>'Barrel Length' ],
			'Ammo'     => [ 'casings'=>'Casing','bullet_types'=>'Bullet Type','grain'=>'Grain','velocity'=>'Velocity' ],
			'Holsters' => [ 'holster_types'=>'Holster Type','holster_colors'=>'Holster Color','holster_materials'=>'Holster Material','holster_hands'=>'Holster Hand' ],
			'Apparel'  => [ 'apparel_patterns'=>'Apparel Pattern','apparel_sizes'=>'Apparel Size','apparel_materials'=>'Apparel Material' ],
			'Knives'   => [ 'blade_shapes'=>'Blade Shape','blade_lengths'=>'Blade Length','blade_materials'=>'Blade Material','blade_edges'=>'Blade Edge','knife_handles'=>'Knife Handle' ],
			'Hunting'  => [ 'hunt_call_types'=>'Hunt Call Type','hunt_games'=>'Hunt Game' ],
			'Optics'   => [ 'optics_mags'=>'Magnification','optics_objs'=>'Objective Size' ],
		];

		$hidden = [];
		try { foreach ( \IPS\Db::i()->select( 'facet_key', 'gd_facet_settings', [ 'hidden=?', 1 ] ) as $k ) { $hidden[ $k ] = TRUE; } } catch ( \Throwable ) {}

		$form = new \IPS\Helpers\Form;
		$form->addMessage( 'gdsearch_facets_intro', 'ipsMessage ipsMessage--information' );
		foreach ( $groups as $groupLabel => $facets )
		{
			$form->addHeader( $groupLabel );
			foreach ( $facets as $key => $label )
			{
				$field = new \IPS\Helpers\Form\YesNo( 'gdfacet_' . $key, !isset( $hidden[ $key ] ), FALSE, [], NULL, NULL, NULL, 'gdfacet_' . $key );
				$field->label = $label;
				$form->add( $field );
			}
		}

		if ( $values = $form->values() )
		{
			foreach ( $groups as $facets )
			{
				foreach ( $facets as $key => $label )
				{
					$visible = !empty( $values[ 'gdfacet_' . $key ] );
					try {
						\IPS\Db::i()->replace( 'gd_facet_settings', [
							'facet_key'   => $key,
							'hidden'      => $visible ? 0 : 1,
							'category_id' => 0,
							'position'    => 0,
						] );
					} catch ( \Throwable ) {}
				}
			}
			try { unset( \IPS\Data\Store::i()->gdsearch_hidden_facets ); } catch ( \Throwable ) {}
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdsearch&module=search&controller=facets' ), 'saved' );
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdsearch_facets_title' );
		\IPS\Output::i()->output = (string) $form;
	}
}
class facets extends _facets {}
