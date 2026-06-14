<?php

namespace IPS\gddealer\setup\upg_10254;

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
		$newStrings = [
			'gddealer_group_max'              => 'Max Tier Group ID',
			'gddealer_group_max_desc'         => 'IPS member group ID assigned to Max-tier dealers.',
			'gddealer_plan_section_max'       => 'Max Plan',
			'gddealer_plan_max_name'          => 'Display Name',
			'gddealer_plan_max_price'         => 'Price Display',
			'gddealer_plan_max_tagline'       => 'Audience Tagline',
			'gddealer_plan_max_sync_label'    => 'Sync Frequency Label',
			'gddealer_plan_max_features'      => 'Features (one per line, basic HTML allowed)',
			'gddealer_plan_max_color'         => 'Accent Color',
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
