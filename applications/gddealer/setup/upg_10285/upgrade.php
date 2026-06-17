<?php

namespace IPS\gddealer\setup\upg_10285;

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

		/* gd_deals columns (from v1.0.284) */
		$dealsCols = [
			'publish_community'     => "ALTER TABLE `{$prefix}gd_deals` ADD COLUMN `publish_community` TINYINT(1) NOT NULL DEFAULT 0",
			'community_category_id' => "ALTER TABLE `{$prefix}gd_deals` ADD COLUMN `community_category_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL",
			'community_post_id'     => "ALTER TABLE `{$prefix}gd_deals` ADD COLUMN `community_post_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL",
		];
		foreach ( $dealsCols as $col => $sql )
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_deals', $col ) )
			{
				try { \IPS\Db::i()->query( $sql ); } catch ( \IPS\Db\Exception $e ) { if ( $e->getCode() !== 1060 ) { throw $e; } }
			}
		}

		/* gd_dealer_coupons columns (new in v1.0.285) */
		$couponCols = [
			'publish_community'     => "ALTER TABLE `{$prefix}gd_dealer_coupons` ADD COLUMN `publish_community` TINYINT(1) NOT NULL DEFAULT 0",
			'community_category_id' => "ALTER TABLE `{$prefix}gd_dealer_coupons` ADD COLUMN `community_category_id` BIGINT(20) NULL DEFAULT NULL",
			'community_post_id'     => "ALTER TABLE `{$prefix}gd_dealer_coupons` ADD COLUMN `community_post_id` BIGINT(20) NULL DEFAULT NULL",
		];
		foreach ( $couponCols as $col => $sql )
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_dealer_coupons', $col ) )
			{
				try { \IPS\Db::i()->query( $sql ); } catch ( \IPS\Db\Exception $e ) { if ( $e->getCode() !== 1060 ) { throw $e; } }
			}
		}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
