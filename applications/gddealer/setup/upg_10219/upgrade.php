<?php
namespace IPS\gddealer\setup\upg_10219;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$strings = [
			'gddealer_front_tab_overview'      => 'Overview',
			'gddealer_front_tab_listings'      => 'Listings',
			'gddealer_front_tab_reviews'       => 'Reviews',
			'gddealer_front_tab_wizard'        => 'Setup Wizard',
			'gddealer_front_tab_feed'          => 'Feed Settings',
			'gddealer_front_tab_validator'     => 'Feed Validator',
			'gddealer_front_tab_unmatched'     => 'Unmatched UPCs',
			'gddealer_front_tab_categories'    => 'Categories',
			'gddealer_front_tab_analytics'     => 'Analytics',
			'gddealer_front_tab_edit_profile'  => 'Edit Profile',
			'gddealer_front_tab_subscription'  => 'Subscription',
			'gddealer_front_tab_help'          => 'Help & Support',
			'gddealer_support_nav'             => 'Support Tickets',
			'gddealer_directory_title'         => 'Dealer Directory',
			'gddealer_directory_subtitle'      => '%1$s active dealers on GunRack.deals',
			'gddealer_directory_no_results'    => 'No dealers found',
			'gddealer_directory_search'        => 'Search dealers...',
			'gddealer_directory_filter_tier'   => 'Tier',
			'gddealer_directory_sort'          => 'Sort By',
			'gddealer_directory_become_dealer' => 'Become a Dealer',
			'gddealer_frontend_dashboard_title'  => 'Dealer Dashboard',
			'gddealer_front_unmatched_intro'     => 'These UPCs appeared in your feed but could not be matched to products in the GunRack catalog.',
			'gddealer_front_export_csv'          => 'Export CSV',
			'gddealer_front_unmatched_empty'     => 'No unmatched UPCs — great work!',
			'gddealer_unmatched_upc'             => 'UPC',
			'gddealer_unmatched_first_seen'      => 'First Seen',
			'gddealer_unmatched_last_seen'       => 'Last Seen',
			'gddealer_unmatched_count'           => 'Count',
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

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
