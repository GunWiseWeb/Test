<?php
namespace IPS\gdcatalog\setup\upg_10084;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	/** Add gd_categories.hidden (0/1) for storefront visibility control. */
	public function step1(): bool
	{
		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_categories', 'hidden' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_categories', [
					'name'       => 'hidden',
					'type'       => 'TINYINT',
					'length'     => 1,
					'unsigned'   => TRUE,
					'allow_null' => FALSE,
					'default'    => 0,
				] );
			}
		}
		catch ( \Throwable ) {}

		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}

class upgrade extends _upgrade {}
