<?php
namespace IPS\gddeals\modules\admin\deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _categories extends \IPS\Node\Controller
{
	public static bool $csrfProtected = TRUE;

	protected string $nodeClass = 'IPS\\gddeals\\Category\\Category';

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'deals_manage' );
		parent::execute();
	}
}
class categories extends _categories {}
