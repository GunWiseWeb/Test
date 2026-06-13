<?php

namespace IPS\gddealer\setup\upg_10249;

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
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_listing_reports' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_listing_reports',
					'columns' => [
						[
							'name'           => 'id',
							'type'           => 'INT',
							'length'         => 11,
							'unsigned'       => true,
							'allow_null'     => false,
							'auto_increment' => true,
						],
						[
							'name'           => 'upc',
							'type'           => 'VARCHAR',
							'length'         => 20,
							'allow_null'     => false,
							'auto_increment' => false,
						],
						[
							'name'           => 'dealer_id',
							'type'           => 'INT',
							'length'         => 11,
							'unsigned'       => true,
							'allow_null'     => false,
							'auto_increment' => false,
						],
						[
							'name'           => 'reporter_member_id',
							'type'           => 'INT',
							'length'         => 11,
							'unsigned'       => true,
							'allow_null'     => false,
							'auto_increment' => false,
						],
						[
							'name'           => 'reason',
							'type'           => 'VARCHAR',
							'length'         => 32,
							'allow_null'     => false,
							'auto_increment' => false,
						],
						[
							'name'           => 'note',
							'type'           => 'TEXT',
							'length'         => null,
							'allow_null'     => true,
							'auto_increment' => false,
						],
						[
							'name'           => 'status',
							'type'           => 'VARCHAR',
							'length'         => 20,
							'allow_null'     => false,
							'default'        => 'new',
							'auto_increment' => false,
						],
						[
							'name'           => 'created_at',
							'type'           => 'INT',
							'length'         => 11,
							'unsigned'       => true,
							'allow_null'     => false,
							'auto_increment' => false,
						],
						[
							'name'           => 'updated_at',
							'type'           => 'INT',
							'length'         => 11,
							'unsigned'       => true,
							'allow_null'     => true,
							'auto_increment' => false,
						],
					],
					'indexes' => [
						[
							'type'    => 'primary',
							'name'    => 'PRIMARY',
							'columns' => [ 'id' ],
							'length'  => [ null ],
						],
						[
							'type'    => 'key',
							'name'    => 'idx_dealer_id',
							'columns' => [ 'dealer_id' ],
							'length'  => [ null ],
						],
						[
							'type'    => 'key',
							'name'    => 'idx_upc',
							'columns' => [ 'upc' ],
							'length'  => [ null ],
						],
						[
							'type'    => 'key',
							'name'    => 'idx_status',
							'columns' => [ 'status' ],
							'length'  => [ null ],
						],
						[
							'type'    => 'unique',
							'name'    => 'uniq_open',
							'columns' => [ 'dealer_id', 'upc', 'reporter_member_id', 'status' ],
							'length'  => [ null, null, null, null ],
						],
					],
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( $e, 'gddealer_upg_10249' ); } catch ( \Throwable ) {}
		}

		$newStrings = [
			'gddealer_notif_listing_reported'                 => 'A listing was reported',
			'gddealer_notif_listing_reported_desc'            => 'When a user reports one of your listings (wrong price, dead link, etc.)',
			'mailani__gddealer_notification_listing_reported'  => '{member} reported one of your listings.',
			'gddealer_front_tab_reported_listings'            => 'Reported Listings',
		];

		try
		{
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
		}
		catch ( \Throwable ) {}

		try
		{
			\IPS\Db::i()->replace( 'core_notification_defaults', [
				'notification_key' => 'listing_reported',
				'default'          => '["inline","email"]',
				'disabled'         => '[]',
			] );
		}
		catch ( \Throwable ) {}

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
