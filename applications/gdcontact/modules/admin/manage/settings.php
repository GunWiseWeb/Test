<?php
/**
 * @brief  GD Contact — ACP: general settings.
 *
 * A single \IPS\Helpers\Form (rule mirrored from
 * gdcatalog/feeds.php / gdbills — the framework-native path
 * that avoids the ACP 301-on-POST trap). All settings are
 * stored as-is via saveAsSettings().
 */

namespace IPS\gdcontact\modules\admin\manage;

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
		\IPS\Dispatcher::i()->checkAcpPermission( 'contact_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$form = new \IPS\Helpers\Form;

		$form->add( new \IPS\Helpers\Form\Email( 'gdcontact_recipient',
			(string) \IPS\Settings::i()->gdcontact_recipient, TRUE ) );

		$form->add( new \IPS\Helpers\Form\Email( 'gdcontact_from_email',
			(string) \IPS\Settings::i()->gdcontact_from_email, FALSE ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gdcontact_from_name',
			(string) \IPS\Settings::i()->gdcontact_from_name, FALSE ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gdcontact_subject_prefix',
			(string) \IPS\Settings::i()->gdcontact_subject_prefix, FALSE, [ 'maxLength' => 60 ] ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gdcontact_page_title',
			(string) \IPS\Settings::i()->gdcontact_page_title, TRUE, [ 'maxLength' => 120 ] ) );

		$form->add( new \IPS\Helpers\Form\TextArea( 'gdcontact_intro',
			(string) \IPS\Settings::i()->gdcontact_intro, FALSE, [ 'rows' => 3 ] ) );

		$form->add( new \IPS\Helpers\Form\TextArea( 'gdcontact_success_message',
			(string) \IPS\Settings::i()->gdcontact_success_message, TRUE, [ 'rows' => 3 ] ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gdcontact_captcha_enabled',
			(bool) \IPS\Settings::i()->gdcontact_captcha_enabled, FALSE ) );

		$form->add( new \IPS\Helpers\Form\YesNo( 'gdcontact_honeypot_enabled',
			(bool) \IPS\Settings::i()->gdcontact_honeypot_enabled, FALSE ) );

		$form->add( new \IPS\Helpers\Form\TextArea( 'gdcontact_routes_json',
			(string) \IPS\Settings::i()->gdcontact_routes_json, FALSE, [
				'rows'        => 6,
				'placeholder' => '[{"field_key":"reason","value":"Dealer inquiry","recipient":"dealers@example.com"}]',
			] ) );

		if ( $values = $form->values() )
		{
			/* Validate the routes JSON — reject a bad payload
			   silently by falling back to '[]' rather than
			   letting a bad blob poison every submit. */
			$routes = trim( (string) ( $values['gdcontact_routes_json'] ?? '' ) );
			if ( $routes !== '' )
			{
				$decoded = json_decode( $routes, TRUE );
				if ( !is_array( $decoded ) )
				{
					$routes = '[]';
				}
			}
			else
			{
				$routes = '[]';
			}

			$form->saveAsSettings( [
				'gdcontact_recipient'        => (string) $values['gdcontact_recipient'],
				'gdcontact_from_email'       => (string) $values['gdcontact_from_email'],
				'gdcontact_from_name'        => (string) $values['gdcontact_from_name'],
				'gdcontact_subject_prefix'   => (string) $values['gdcontact_subject_prefix'],
				'gdcontact_page_title'       => (string) $values['gdcontact_page_title'],
				'gdcontact_intro'            => (string) $values['gdcontact_intro'],
				'gdcontact_success_message'  => (string) $values['gdcontact_success_message'],
				'gdcontact_captcha_enabled'  => (int) $values['gdcontact_captcha_enabled'],
				'gdcontact_honeypot_enabled' => (int) $values['gdcontact_honeypot_enabled'],
				'gdcontact_routes_json'      => $routes,
			] );

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcontact&module=manage&controller=settings', 'admin' ),
				'gdcontact_settings_saved'
			);
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcontact_settings_title' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class settings extends _settings {}
