<?php
/**
 * @brief  GD FFL Finder — ACP: Settings.
 *
 * Default search radius, transfer-capable license-type list,
 * import delimiter, replace/merge mode, and results-per-page.
 * All settings are simple scalars persisted via IPS\Settings.
 */

namespace IPS\gdffl\modules\admin\manage;

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
		\IPS\Dispatcher::i()->checkAcpPermission( 'ffl_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();

		$form = new \IPS\Helpers\Form( 'form', 'save' );

		$form->add( new \IPS\Helpers\Form\Number( 'gdffl_default_radius',
			(int) \IPS\Settings::i()->gdffl_default_radius, FALSE,
			[ 'min' => 1, 'max' => 500 ], NULL, NULL, NULL, 'gdffl_default_radius' ) );

		$form->add( new \IPS\Helpers\Form\Text( 'gdffl_default_types',
			(string) \IPS\Settings::i()->gdffl_default_types, FALSE,
			[ 'maxLength' => 60 ], NULL, NULL, NULL, 'gdffl_default_types' ) );

		$form->add( new \IPS\Helpers\Form\Radio( 'gdffl_delimiter',
			(string) ( \IPS\Settings::i()->gdffl_delimiter ?: 'auto' ), TRUE, [
				'options' => [ 'auto' => 'Auto-detect', 'tab' => 'Tab', 'comma' => 'Comma' ],
			], NULL, NULL, NULL, 'gdffl_delimiter' ) );

		$form->add( new \IPS\Helpers\Form\Radio( 'gdffl_import_mode',
			(string) ( \IPS\Settings::i()->gdffl_import_mode ?: 'replace' ), TRUE, [
				'options' => [ 'replace' => 'Replace all', 'merge' => 'Merge (upsert by lic_number)' ],
			], NULL, NULL, NULL, 'gdffl_import_mode' ) );

		$form->add( new \IPS\Helpers\Form\Number( 'gdffl_per_page',
			(int) \IPS\Settings::i()->gdffl_per_page, FALSE,
			[ 'min' => 5, 'max' => 200 ], NULL, NULL, NULL, 'gdffl_per_page' ) );

		if ( $values = $form->values() )
		{
			$delim = (string) ( $values['gdffl_delimiter'] ?? 'auto' );
			if ( !in_array( $delim, [ 'auto', 'tab', 'comma' ], TRUE ) ) { $delim = 'auto'; }
			$mode  = (string) ( $values['gdffl_import_mode'] ?? 'replace' );
			if ( !in_array( $mode, [ 'replace', 'merge' ], TRUE ) ) { $mode = 'replace'; }

			try
			{
				\IPS\Settings::i()->changeValues( [
					'gdffl_default_radius' => max( 1, min( 500, (int) $values['gdffl_default_radius'] ) ),
					'gdffl_default_types'  => trim( (string) $values['gdffl_default_types'] ),
					'gdffl_delimiter'      => $delim,
					'gdffl_import_mode'    => $mode,
					'gdffl_per_page'       => max( 5, min( 200, (int) $values['gdffl_per_page'] ) ),
				] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'gdffl settings: ' . $e->getMessage(), 'gdffl' ); } catch ( \Throwable ) {}
			}

			\IPS\Output::i()->redirect(
				(string) \IPS\Http\Url::internal( 'app=gdffl&module=manage&controller=settings' ),
				'saved'
			);
			return;
		}

		\IPS\Output::i()->title  = $lang->addToStack( 'gdffl_acp_settings_title' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class settings extends _settings {}
