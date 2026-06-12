<?php

namespace IPS\gddealer\setup\upg_10228;

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
		$prefix = \IPS\Db::i()->prefix;

		if ( !\IPS\Db::i()->checkForTable( 'gd_deals' ) )
		{
			try
			{
				\IPS\Db::i()->query( "CREATE TABLE `{$prefix}gd_deals` (
					`deal_id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					`dealer_id`    INT UNSIGNED NOT NULL DEFAULT 0,
					`upc`          VARCHAR(20) NOT NULL DEFAULT '',
					`deal_type`    VARCHAR(10) NOT NULL DEFAULT 'price',
					`deal_price`   DECIMAL(10,2) NULL DEFAULT NULL,
					`deal_percent` DECIMAL(5,2) NULL DEFAULT NULL,
					`title`        VARCHAR(150) NULL DEFAULT NULL,
					`blurb`        VARCHAR(500) NULL DEFAULT NULL,
					`start_date`   INT UNSIGNED NULL DEFAULT NULL,
					`end_date`     INT UNSIGNED NULL DEFAULT NULL,
					`is_active`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
					`source`       VARCHAR(10) NOT NULL DEFAULT 'dealer',
					`created`      INT UNSIGNED NOT NULL DEFAULT 0,
					`updated`      INT UNSIGNED NULL DEFAULT NULL,
					PRIMARY KEY (`deal_id`),
					KEY `idx_dealer_id` (`dealer_id`),
					KEY `idx_upc` (`upc`),
					KEY `idx_is_active` (`is_active`),
					KEY `idx_source` (`source`),
					KEY `idx_start_date` (`start_date`),
					KEY `idx_end_date` (`end_date`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10228 gd_deals create failed: ' . $e->getMessage(), 'gddealer_upg_10228' ); }
				catch ( \Throwable ) {}
			}
		}

		if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_coupons' ) )
		{
			try
			{
				\IPS\Db::i()->query( "CREATE TABLE `{$prefix}gd_dealer_coupons` (
					`coupon_id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					`dealer_id`      INT UNSIGNED NOT NULL DEFAULT 0,
					`code`           VARCHAR(40) NOT NULL DEFAULT '',
					`description`    VARCHAR(255) NULL DEFAULT NULL,
					`discount_type`  VARCHAR(10) NOT NULL DEFAULT 'percent',
					`discount_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
					`min_purchase`   DECIMAL(10,2) NULL DEFAULT NULL,
					`terms`          VARCHAR(500) NULL DEFAULT NULL,
					`expiry`         INT UNSIGNED NULL DEFAULT NULL,
					`is_active`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
					`created`        INT UNSIGNED NOT NULL DEFAULT 0,
					PRIMARY KEY (`coupon_id`),
					KEY `idx_dealer_id` (`dealer_id`),
					KEY `idx_is_active` (`is_active`),
					KEY `idx_expiry` (`expiry`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10228 gd_dealer_coupons create failed: ' . $e->getMessage(), 'gddealer_upg_10228' ); }
				catch ( \Throwable ) {}
			}
		}

		/* Re-seed setupWizardStep2 template (carried forward from v1.0.227) */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10152_part2.php';
			require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/templates_10152_part3.php';
		}
		catch ( \Throwable ) {}

		/* Seed new lang strings */
		$strings = [
			'gddealer_front_tab_deals'         => 'Deals',
			'gddealer_front_tab_coupons'       => 'Coupons',
			'gddealer_deals_create_title'      => 'Create Deal',
			'gddealer_deals_edit_title'        => 'Edit Deal',
			'gddealer_coupons_create_title'    => 'Create Coupon',
			'gddealer_coupons_edit_title'      => 'Edit Coupon',
			'gddealer_clear_failed_logs'       => 'Clear Failed Logs',
			'gddealer_confirm_clear_logs'      => 'Delete all failed import log entries for this dealer?',
			'gddealer_logs_cleared'            => '%d failed log entries deleted.',
			'gddealer_reset_feed'              => 'Reset Feed',
			'gddealer_confirm_reset_feed'      => 'This will wipe the feed configuration, all listings, all import logs, and all unmatched UPCs for this dealer. This cannot be undone. Continue?',
			'gddealer_feed_reset_done'         => 'Feed configuration and all related data has been reset.',
			'gddealer_unmatched_review'        => 'Review',
			'gddealer_unmatched_actions'       => 'Actions',
			'gddealer_unmatched_review_title'  => 'Review Unmatched UPC',
			'gddealer_unmatched_dealer_data'   => 'Dealer Feed Data (snapshot)',
			'gddealer_unmatched_add_form_title' => 'Add to Catalog',
			'gddealer_unmatched_category'      => 'Category',
			'gddealer_unmatched_added_to_catalog' => 'Product added to catalog successfully.',
			'gddealer_unmatched_already_exists' => 'This UPC already exists in the catalog.',
		];

		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $strings as $key => $val )
			{
				try
				{
					\IPS\Db::i()->replace( 'core_sys_lang_words', [
						'lang_id'      => (int) $langId,
						'word_app'     => 'gddealer',
						'word_key'     => $key,
						'word_default' => $val,
						'word_js'      => 0,
						'word_export'  => 1,
					] );
				}
				catch ( \Throwable ) {}
			}
		}

		/* Clear compiled templates + all caches */
		try
		{
			foreach ( \IPS\Theme::themes() as $theme )
			{
				try { $theme->deleteCompiledTemplate(); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
