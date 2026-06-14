<?php

namespace IPS\gddealer\setup\upg_10255;

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
		if ( !\IPS\Db::i()->checkForColumn( 'gd_dealer_import_log', 'records_capped' ) )
		{
			\IPS\Db::i()->addColumn( 'gd_dealer_import_log', [
				'name'           => 'records_capped',
				'type'           => 'INT',
				'length'         => 10,
				'unsigned'       => true,
				'allow_null'     => false,
				'default'        => 0,
			] );
		}

		try
		{
			\IPS\Db::i()->insert( 'core_notification_defaults', [
				'notification_key' => 'listing_cap_reached',
				'default'          => 'inline,email',
				'disabled'         => '',
			] );
		}
		catch ( \Throwable ) {}

		$newStrings = [
			'gddealer_notif_listing_cap_reached'      => 'Listing cap reached',
			'gddealer_notif_listing_cap_reached_desc'  => 'When your feed exceeds your plan\'s listing cap and some listings are skipped.',
		];

		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			foreach ( $newStrings as $key => $val )
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

		require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		\IPS\gddealer\Setup\CanonicalTemplates::ensure();
		\IPS\gddealer\Setup\CanonicalTemplates::clearCaches();

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
