<?php

namespace IPS\gddealer\setup\upg_10312;

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

		try {
			$hasCol = (bool) \IPS\Db::i()->query( "SHOW COLUMNS FROM `{$prefix}gd_click_log` LIKE 'ip_hash'" )->num_rows;
			if ( !$hasCol ) {
				\IPS\Db::i()->query( "ALTER TABLE `{$prefix}gd_click_log` ADD COLUMN `ip_hash` CHAR(64) NULL DEFAULT NULL AFTER `member_id`, ADD KEY `idx_ip_hash` (`ip_hash`)" );
			}
		} catch ( \Throwable $e ) {
			try { \IPS\Log::log( $e, 'gddealer_upgrade' ); } catch ( \Throwable ) {}
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
