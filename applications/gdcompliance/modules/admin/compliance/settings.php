<?php
/**
 * @brief  GD Compliance — ACP settings (Phase 5 frontend display toggle)
 *
 * Master enable/disable + reason visibility + disclaimer text for the
 * "Sales Restrictions" panel that ships with Phase 5.
 */

namespace IPS\gdcompliance\modules\admin\compliance;

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
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$form = new \IPS\Helpers\Form;

		$form->add( new \IPS\Helpers\Form\YesNo(
			'gdcompliance_front_enabled',
			(int) ( \IPS\Settings::i()->gdcompliance_front_enabled ?? 1 ),
			FALSE
		) );

		$form->add( new \IPS\Helpers\Form\YesNo(
			'gdcompliance_front_show_reasons',
			(int) ( \IPS\Settings::i()->gdcompliance_front_show_reasons ?? 1 ),
			FALSE
		) );

		$form->add( new \IPS\Helpers\Form\TextArea(
			'gdcompliance_front_disclaimer',
			(string) ( \IPS\Settings::i()->gdcompliance_front_disclaimer ?? '' ),
			FALSE,
			[ 'rows' => 3 ]
		) );

		/* v1.6.22 — Public State Compliance Lookup (/state-lookup/). */
		$form->addHeader( 'gdcompliance_acp_settings_lookup_header' );
		$form->add( new \IPS\Helpers\Form\YesNo(
			'gdcompliance_lookup_enabled',
			(int) ( \IPS\Settings::i()->gdcompliance_lookup_enabled ?? 1 ),
			FALSE
		) );
		$form->add( new \IPS\Helpers\Form\TextArea(
			'gdcompliance_lookup_disclaimer',
			(string) ( \IPS\Settings::i()->gdcompliance_lookup_disclaimer ?? '' ),
			FALSE,
			[ 'rows' => 6 ]
		) );

		if ( $values = $form->values() )
		{
			try
			{
				\IPS\Settings::i()->changeValues( [
					'gdcompliance_front_enabled'      => (int) (bool) $values['gdcompliance_front_enabled'],
					'gdcompliance_front_show_reasons' => (int) (bool) $values['gdcompliance_front_show_reasons'],
					'gdcompliance_front_disclaimer'   => (string) $values['gdcompliance_front_disclaimer'],
					'gdcompliance_lookup_enabled'     => (int) (bool) $values['gdcompliance_lookup_enabled'],
					'gdcompliance_lookup_disclaimer'  => (string) $values['gdcompliance_lookup_disclaimer'],
				] );
			}
			catch ( \Throwable ) {}

			\IPS\Session::i()->log( 'acplog__gdcompliance_settings_saved' );
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=settings' ),
				'saved'
			);
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_settings_title' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class settings extends _settings {}
