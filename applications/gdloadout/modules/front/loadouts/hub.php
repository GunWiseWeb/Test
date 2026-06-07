<?php
namespace IPS\gdloadout\modules\front\loadouts;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _hub extends \IPS\Dispatcher\Controller
{
	public function execute(): void { parent::execute(); }
	protected function manage(): void
	{
		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_hub_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->hub();
	}
	protected function view(): void
	{
		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdloadout_hub_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->hub();
	}
}
class hub extends _hub {}
