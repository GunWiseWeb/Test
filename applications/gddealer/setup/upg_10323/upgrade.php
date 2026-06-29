<?php

namespace IPS\gddealer\setup\upg_10323;

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
		/* Re-publish so existing source_badge='auto' posts get re-categorized
		   via the new catalog-hierarchy resolver (rifles ≠ handguns). The
		   reconcile UPDATE path already writes category_id, so the existing
		   ~50 auto posts get fixed without recreation. Wrapped — never fatal. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Deals/DealEngine.php';
			\IPS\gddealer\Deals\DealEngine::computeAll();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10323 DealEngine::computeAll: ' . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
		}

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Deals/DealPublisher.php';
			$counts = \IPS\gddealer\Deals\DealPublisher::publish();
			try { \IPS\Log::log( 'upg_10323 publish: ' . json_encode( $counts ), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10323 DealPublisher::publish: ' . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
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
