<?php
namespace IPS\gddeals\modules\front\deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _browse extends \IPS\Dispatcher\Controller
{
	public function execute(): void
	{
		parent::execute();
	}

	protected function manage(): void
	{
		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'gddeals_ct_deals' );
		\IPS\Output::i()->output = '';
	}
}
class browse extends _browse {}
