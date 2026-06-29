<?php

namespace IPS\gddealer\setup\upg_10320;

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
		/* Recompute deal flags first (Phase 1 engine), then publish (Phase 2). */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Deals/DealEngine.php';
			\IPS\gddealer\Deals\DealEngine::computeAll();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10320 DealEngine::computeAll: ' . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
		}

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Deals/DealPublisher.php';
			$counts = \IPS\gddealer\Deals\DealPublisher::publish();
			try { \IPS\Log::log( 'upg_10320 initial publish: ' . json_encode( $counts ), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10320 DealPublisher::publish: ' . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
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
