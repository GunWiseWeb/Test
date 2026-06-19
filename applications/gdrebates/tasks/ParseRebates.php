<?php
namespace IPS\gdrebates\tasks;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ParseRebates extends \IPS\Task
{
	public function execute(): mixed
	{
		if ( !\IPS\Settings::i()->gdrebates_parse_active )
		{
			return null;
		}

		$cap = (int) ( \IPS\Settings::i()->gdrebates_parse_cap ?: 20 );

		$sources = \IPS\Db::i()->select( '*', 'gd_rebate_sources', [ 'is_active=?', 1 ], 'last_parsed_at ASC', [ 0, $cap ] );

		foreach ( $sources as $source )
		{
			try
			{
				$parser = new \IPS\gdrebates\sources\Parser( $source );
				$parser->run();
			}
			catch ( \Throwable $e )
			{
				\IPS\Log::log( $e, 'gdrebates_parse' );
			}
		}

		return null;
	}
}
class ParseRebates extends _ParseRebates {}
