<?php
namespace IPS\gddealer\setup\upg_10222;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
    public function step1(): bool
    {
        $strings = [
            '__app_gddealer'                         => 'GD Dealer Manager',
            'menu__gddealer_dealers_dashboard'       => 'Dashboard',
            'menu__gddealer_dealers_dealers'         => 'Manage Dealers',
            'menu__gddealer_dealers_mrr'             => 'MRR Dashboard',
            'menu__gddealer_dealers_unmatched'       => 'Unmatched UPCs',
            'menu__gddealer_dealers_disputes'        => 'Disputes',
            'menu__gddealer_dealers_fflVerifications'=> 'FFL Verifications',
            'menu__gddealer_dealers_reviews'         => 'Reviews',
            'menu__gddealer_dealers_disputeCounts'   => 'Dispute Counts',
            'menu__gddealer_dealers_support'         => 'Support',
            'menu__gddealer_dealers_departments'     => 'Departments',
            'menu__gddealer_dealers_stockreplies'    => 'Stock Replies',
            'menu__gddealer_dealers_stockactions'    => 'Stock Actions',
            'menu__gddealer_dealers_settings'        => 'Settings',
            'menu__gddealer_dealers_plans'           => 'Plans',
            'gddealer_dash_total_dealers'            => 'Total Dealers',
            'gddealer_dash_active_dealers'           => 'Active Dealers',
            'gddealer_dash_suspended_dealers'        => 'Suspended Dealers',
            'gddealer_dash_total_listings'           => 'Total Listings',
            'gddealer_dash_in_stock_listings'        => 'In Stock Listings',
            'gddealer_dash_unmatched_total'          => 'Unmatched UPCs',
            'gddealer_dash_last_run'                 => 'Last Import Run',
            'gddealer_dash_pending_conflicts'        => 'Pending Conflicts',
            'gddealer_dash_pending_reviews'          => 'Pending Reviews',
            'gddealer_dash_open_disputes'            => 'Open Disputes',
            'gddealer_dash_pending_ffl'              => 'Pending FFL Verifications',
            'gddealer_dash_tier_breakdown'           => 'Subscription Tier Breakdown',
            'gddealer_dash_tier'                     => 'Tier',
            'gddealer_dash_dealers'                  => 'Dealers',
            'gddealer_dash_manage_dealers'           => 'Manage Dealers',
            'gddealer_dash_mrr_dashboard'            => 'MRR Dashboard',
            'gddealer_dash_unmatched_upcs'           => 'Unmatched UPCs',
            'gddealer_front_tab_overview'            => 'Overview',
            'gddealer_front_tab_listings'            => 'Listings',
            'gddealer_front_tab_reviews'             => 'Reviews',
            'gddealer_front_tab_wizard'              => 'Setup Wizard',
            'gddealer_front_tab_feed'                => 'Feed Settings',
            'gddealer_front_tab_validator'           => 'Feed Validator',
            'gddealer_front_tab_unmatched'           => 'Unmatched UPCs',
            'gddealer_front_tab_categories'          => 'Categories',
            'gddealer_front_tab_analytics'           => 'Analytics',
            'gddealer_front_tab_edit_profile'        => 'Edit Profile',
            'gddealer_front_tab_subscription'        => 'Subscription',
            'gddealer_front_tab_help'                => 'Help & Support',
            'gddealer_front_tab_data_flags'          => 'Flagged UPCs',
            'gddealer_support_nav'                   => 'Support Tickets',
            'gddealer_directory_title'               => 'Dealer Directory',
            'gddealer_directory_subtitle'            => '%1$s active dealers on GunRack.deals',
            'gddealer_directory_no_results'          => 'No dealers found',
            'gddealer_directory_search'              => 'Search dealers...',
            'gddealer_directory_filter_tier'         => 'Tier',
            'gddealer_directory_sort'                => 'Sort By',
            'gddealer_directory_become_dealer'       => 'Become a Dealer',
            'gddealer_frontend_dashboard_title'      => 'Dealer Dashboard',
            'gddealer_front_unmatched_intro'         => 'These UPCs appeared in your feed but could not be matched to products in the GunRack catalog.',
            'gddealer_front_export_csv'              => 'Export CSV',
            'gddealer_front_unmatched_empty'         => 'No unmatched UPCs — great work!',
            'gddealer_front_unmatched_exclude'       => 'Exclude',
            'gddealer_unmatched_upc'                 => 'UPC',
            'gddealer_unmatched_first_seen'          => 'First Seen',
            'gddealer_unmatched_last_seen'           => 'Last Seen',
            'gddealer_unmatched_count'               => 'Count',
            'gddealer_flag_submitted'                => 'Flag submitted for admin review.',
            'gddealer_flag_type_mpn'                 => 'MPN Mismatch',
            'gddealer_flag_type_title'               => 'Title Mismatch',
            'gddealer_flag_type_brand'               => 'Brand Mismatch',
            'task__DealerImportFeeds'                => 'Import Dealer Feeds',
            'task__ExpireTrials'                     => 'Expire Dealer Trials',
            'task__ComputeRankSnapshots'             => 'Compute Rank Snapshots',
            'task__ResolveExpiredDisputes'           => 'Resolve Expired Disputes',
            'gddealer_no_results'                    => 'No results found.',
            'gddealer_save'                          => 'Save',
            'gddealer_cancel'                        => 'Cancel',
            'gddealer_status_active'                 => 'Active',
            'gddealer_status_suspended'              => 'Suspended',
            'gddealer_status_inactive'               => 'Inactive',
            'gddealer_tier_basic'                    => 'Basic',
            'gddealer_tier_pro'                      => 'Pro',
            'gddealer_tier_enterprise'               => 'Enterprise',
            'gddealer_tier_founding'                 => 'Founding',
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
