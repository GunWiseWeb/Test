<?php
namespace IPS\gddeals\modules\admin\deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _settings extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'settings_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$form = new \IPS\Helpers\Form;

		$form->addHeader( 'gddeals_settings_display' );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gddeals_show_coupon_strip', \IPS\Settings::i()->gddeals_show_coupon_strip ?? 1 ) );

		$form->addHeader( 'gddeals_colhdr_brand' );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_primary', \IPS\Settings::i()->gddeals_c_primary ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_primary_hover', \IPS\Settings::i()->gddeals_c_primary_hover ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_dealer', \IPS\Settings::i()->gddeals_c_dealer ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_dealer_hover', \IPS\Settings::i()->gddeals_c_dealer_hover ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_discount', \IPS\Settings::i()->gddeals_c_discount ) );

		$form->addHeader( 'gddeals_colhdr_text' );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_heading', \IPS\Settings::i()->gddeals_c_heading ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_text', \IPS\Settings::i()->gddeals_c_text ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_text_muted', \IPS\Settings::i()->gddeals_c_text_muted ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_text_faint', \IPS\Settings::i()->gddeals_c_text_faint ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_text_strike', \IPS\Settings::i()->gddeals_c_text_strike ) );

		$form->addHeader( 'gddeals_colhdr_surfaces' );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_border', \IPS\Settings::i()->gddeals_c_border ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_border_hover', \IPS\Settings::i()->gddeals_c_border_hover ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_border_light', \IPS\Settings::i()->gddeals_c_border_light ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_surface', \IPS\Settings::i()->gddeals_c_surface ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_surface_alt', \IPS\Settings::i()->gddeals_c_surface_alt ) );

		$form->addHeader( 'gddeals_colhdr_badges' );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_promo_bg', \IPS\Settings::i()->gddeals_c_promo_bg ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_promo_text', \IPS\Settings::i()->gddeals_c_promo_text ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_ship_bg', \IPS\Settings::i()->gddeals_c_ship_bg ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_expired', \IPS\Settings::i()->gddeals_c_expired ) );
		$form->add( new \IPS\Helpers\Form\Color( 'gddeals_c_expired_bg', \IPS\Settings::i()->gddeals_c_expired_bg ) );

		if ( $values = $form->values() )
		{
			$form->saveAsSettings( $values );
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gddeals&module=deals&controller=settings' ), 'saved' );
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_colors_title' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class settings extends _settings {}
