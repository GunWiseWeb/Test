<?php

namespace IPS\gddealer\setup\upg_10321;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* Re-run publish with the fixed column reference + category mapping +
		   proper mod-queue Approval registration. Wrapped — never fatal. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Deals/DealPublisher.php';
			$counts = \IPS\gddealer\Deals\DealPublisher::publish();
			try { \IPS\Log::log( 'upg_10321 publish: ' . json_encode( $counts ), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10321 DealPublisher::publish: ' . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
		}

		require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		\IPS\gddealer\Setup\CanonicalTemplates::purgeCanonicalTemplates();

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
