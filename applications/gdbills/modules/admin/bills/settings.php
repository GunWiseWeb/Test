<?php
namespace IPS\gdbills\modules\admin\bills;

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
		\IPS\Dispatcher::i()->checkAcpPermission( 'bills_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$S    = \IPS\Settings::i();
		$form = new \IPS\Helpers\Form;

		$form->addHeader( 'gdbills_settings_general' );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gdbills_autosync_enabled', (int) ( $S->gdbills_autosync_enabled ?? 1 ) ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gdbills_relevance_threshold', (int) ( $S->gdbills_relevance_threshold ?? 50 ), TRUE, [ 'min' => 0, 'max' => 100 ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdbills_session_note', (string) ( $S->gdbills_session_note ?? '' ), FALSE, [ 'rows' => 2 ] ) );

		$form->addHeader( 'gdbills_settings_legiscan' );
		$form->add( new \IPS\Helpers\Form\Text( 'gdbills_legiscan_key', (string) ( $S->gdbills_legiscan_key ?? '' ), FALSE ) );

		$form->addHeader( 'gdbills_settings_keywords' );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdbills_search_keywords',    (string) ( $S->gdbills_search_keywords    ?? '' ), FALSE, [ 'rows' => 8 ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdbills_relevance_keywords', (string) ( $S->gdbills_relevance_keywords ?? '' ), FALSE, [ 'rows' => 8 ] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdbills_exclusion_keywords', (string) ( $S->gdbills_exclusion_keywords ?? '' ), FALSE, [ 'rows' => 5 ] ) );

		if ( $values = $form->values() )
		{
			$form->saveAsSettings( $values );
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdbills&module=bills&controller=settings' ), 'saved' );
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdbills_acp_settings_title' );
		\IPS\Output::i()->output = (string) $form;
	}
}

class settings extends _settings {}
