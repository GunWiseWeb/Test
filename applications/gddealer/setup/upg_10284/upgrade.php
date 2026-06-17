<?php

namespace IPS\gddealer\setup\upg_10284;

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
		$add = [
			'publish_community'     => "ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_deals` ADD COLUMN `publish_community` TINYINT(1) NOT NULL DEFAULT 0",
			'community_category_id' => "ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_deals` ADD COLUMN `community_category_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL",
			'community_post_id'     => "ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_deals` ADD COLUMN `community_post_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL",
		];
		foreach ( $add as $col => $sql )
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_deals', $col ) )
			{
				try { \IPS\Db::i()->query( $sql ); } catch ( \IPS\Db\Exception $e ) { if ( $e->getCode() !== 1060 ) { throw $e; } }
			}
		}
		return TRUE;
	}
}

class upgrade extends _upgrade {}
