<?php

namespace IPS\gddealer\setup\upg_10319;

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
		$p = \IPS\Db::i()->prefix;

		/* Add deal columns + composite index. Each ADD COLUMN guarded by
		   SHOW COLUMNS so re-running is safe. */
		$cols = [
			'deal_lowest_ever'     => "TINYINT(1) NOT NULL DEFAULT 0",
			'deal_lowest_30d'      => "TINYINT(1) NOT NULL DEFAULT 0",
			'deal_msrp_off'        => "TINYINT(1) NOT NULL DEFAULT 0",
			'deal_msrp_pct'        => "DECIMAL(5,2) NOT NULL DEFAULT 0",
			'deal_price_drop'      => "TINYINT(1) NOT NULL DEFAULT 0",
			'deal_drop_pct'        => "DECIMAL(5,2) NOT NULL DEFAULT 0",
			'deal_back_in_stock'   => "TINYINT(1) NOT NULL DEFAULT 0",
			'deal_rare_find'       => "TINYINT(1) NOT NULL DEFAULT 0",
			'deal_dealer_count'    => "INT(10) UNSIGNED NOT NULL DEFAULT 0",
			'deal_free_ship_steal' => "TINYINT(1) NOT NULL DEFAULT 0",
			'deals_computed_at'    => "DATETIME NULL DEFAULT NULL",
		];
		foreach ( $cols as $col => $def )
		{
			try
			{
				$has = (bool) \IPS\Db::i()->query( "SHOW COLUMNS FROM `{$p}gd_dealer_listings` LIKE '{$col}'" )->num_rows;
				if ( !$has )
				{
					\IPS\Db::i()->query( "ALTER TABLE `{$p}gd_dealer_listings` ADD COLUMN `{$col}` {$def}" );
				}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( "upg_10319 add column {$col}: " . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
			}
		}

		/* Composite index for the upcoming front-page reads. SHOW INDEX guard. */
		try
		{
			$hasIdx = (bool) \IPS\Db::i()->query( "SHOW INDEX FROM `{$p}gd_dealer_listings` WHERE Key_name = 'idx_deals'" )->num_rows;
			if ( !$hasIdx )
			{
				\IPS\Db::i()->query( "ALTER TABLE `{$p}gd_dealer_listings` ADD KEY `idx_deals` (`listing_status`, `in_stock`)" );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( "upg_10319 add idx_deals: " . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
		}

		/* Initial compute so deals populate immediately — wrapped, never fatal. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Deals/DealEngine.php';
			$results = \IPS\gddealer\Deals\DealEngine::computeAll();
			try { \IPS\Log::log( 'upg_10319 initial computeAll: ' . json_encode( $results ), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( "upg_10319 initial computeAll: " . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
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
