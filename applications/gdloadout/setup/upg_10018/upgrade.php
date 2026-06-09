<?php

namespace IPS\gdloadout\setup\upg_10018;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		// Seed gdloadout_share_forum setting (idempotent)
		try
		{
			$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_sys_conf_settings', [ 'conf_key=?', 'gdloadout_share_forum' ] )->first();
			if ( $exists === 0 )
			{
				\IPS\Db::i()->insert( 'core_sys_conf_settings', [
					'conf_key'     => 'gdloadout_share_forum',
					'conf_value'   => '0',
					'conf_default' => '0',
					'conf_app'     => 'gdloadout',
				] );
			}
		}
		catch ( \Throwable ) {}

		// Seed all lang strings accumulated through v1.0.18
		$newStrings = [
			'menutab__gdloadout'               => 'Loadouts',
			'gdloadout_share_forum'            => 'Loadout Share Forum',
			'gdloadout_share_forum_desc'       => 'Select the forum where loadout shares will be posted. If no forum is selected, the Share to Forum button will be hidden.',
			'gdloadout_share_to_forum'         => 'Share to Forum',
			'gdloadout_view_discussion'        => 'View Discussion',
			'gdloadout_already_shared'         => 'This loadout has already been shared to the forum',
			'gdloadout_forums_not_configured'  => 'Forum sharing is not available',
			'gdloadout_share_rate_limited'     => 'Please wait before sharing another loadout',
			'gdloadout_settings'               => 'Settings',
			'menu__gdloadout_manage_settings'  => 'Settings',
			'gdloadout_share_forum_none'       => 'No forum selected — sharing disabled',
			'gdloadout_shared_success'         => 'Loadout shared to forum',
			'menu__gdloadout'                  => 'Loadouts',
			'menu__gdloadout_manage_limits'    => 'Group Limits',
			'menu__gdloadout_manage_featured'  => 'Featured Loadouts',
			'r__limits_manage'                 => 'Manage group limits',
			'r__featured_manage'               => 'Manage featured loadouts',
			'r__settings_manage'               => 'Manage settings',
			'gdloadout_featured_title'         => 'Featured Loadouts',
			'gdloadout_manage'                 => 'Loadouts',
			'menu__gdloadout_manage'           => 'Loadouts',
			'gdloadout_limits_title'           => 'Loadout Group Limits',
			'gdloadout_limits_unlimited'       => 'Set to 0 for unlimited.',
			'gdloadout_limits_group'           => 'Group',
			'gdloadout_limits_max_loadouts'    => 'Max Loadouts',
			'gdloadout_limits_max_slots'       => 'Max Slots',
			'gdloadout_limits_saved'           => 'Group limits saved.',
			'gdloadout_modal_search'           => 'Search by name, UPC, or MPN...',
			'gdloadout_modal_no_products'      => 'No products in this category yet — try searching by name or UPC/MPN.',
			'gdloadout_modal_all'              => 'All',
			'gdloadout_modal_sort_relevance'   => 'Relevance',
			'gdloadout_modal_sort_name'        => 'Name (A–Z)',
			'gdloadout_modal_load_more'        => 'Load more',
			'gdloadout_modal_no_price'         => 'No price yet',
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
							'word_app'     => 'gdloadout',
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

		// Clear caches (version bump rotates CSS anti-cache key)
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }     catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
