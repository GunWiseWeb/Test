<?php
namespace IPS\gdrebates\modules\admin\rebates;

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
		$form->add( new \IPS\Helpers\Form\Text( 'gdrebates_api_key', \IPS\Settings::i()->gdrebates_api_key, FALSE, [ 'size' => 60 ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdrebates_model', \IPS\Settings::i()->gdrebates_model ?: 'claude-haiku-4-5-20251001', TRUE, [
			'options' => [
				'claude-haiku-4-5-20251001' => 'Haiku 4.5 (fast / cheap)',
				'claude-sonnet-4-6'         => 'Sonnet 4.6 (accurate)',
			],
		] ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gdrebates_parse_cap', \IPS\Settings::i()->gdrebates_parse_cap ?: 20, TRUE ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gdrebates_parse_active', \IPS\Settings::i()->gdrebates_parse_active, FALSE ) );

		if ( $values = $form->values() )
		{
			$form->saveAsSettings( $values );
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdrebates&module=rebates&controller=settings' ), 'saved' );
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'menu__gdrebates_rebates_settings' );
		\IPS\Output::i()->output = (string) $form;
	}
}
class settings extends _settings {}
