<?php
namespace IPS\gddealer\setup\upg_10224;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
    public function step1(): bool
    {
        $strings = [
            'gddealer_flagged_upcs_title'             => 'Flagged UPCs',
            'gddealer_flagged_upc_detail_title'       => 'Flagged UPC Detail',
            'gddealer_flagged_upcs_count'             => 'total flags',
            'gddealer_flagged_upcs_empty'             => 'No flagged UPCs match this filter.',
            'gddealer_flag_status_new'                => 'New',
            'gddealer_flag_status_submitted'          => 'Submitted',
            'gddealer_flag_status_resolved'           => 'Resolved',
            'gddealer_flag_status_dismissed'          => 'Dismissed',
            'gddealer_flag_status_all'                => 'All',
            'gddealer_flag_col_dealer'                => 'Dealer',
            'gddealer_flag_col_upc'                   => 'UPC',
            'gddealer_flag_col_type'                  => 'Field',
            'gddealer_flag_col_dealer_value'          => 'Dealer\'s Value',
            'gddealer_flag_col_catalog_value'         => 'Catalog Value',
            'gddealer_flag_col_status'                => 'Status',
            'gddealer_flag_col_submitted'             => 'Submitted',
            'gddealer_flag_review'                    => 'Review',
            'gddealer_flag_back'                      => 'Back to Flags',
            'gddealer_flag_value_comparison'          => 'Value Comparison',
            'gddealer_flag_catalog_context'           => 'Catalog Product',
            'gddealer_flag_admin_note'                => 'Admin Note',
            'gddealer_flag_save_note'                 => 'Save Note',
            'gddealer_flag_actions'                   => 'Actions',
            'gddealer_flag_resolve_update'            => 'Resolve — Update Catalog',
            'gddealer_flag_resolve_no_update'         => 'Resolve — Keep Catalog',
            'gddealer_flag_dismiss'                   => 'Dismiss',
            'gddealer_flag_resolve_confirm'           => 'Update the catalog with the dealer\'s value and mark this flag resolved?',
            'gddealer_flag_resolve_no_update_confirm' => 'Mark this flag resolved without updating the catalog?',
            'gddealer_flag_dismiss_confirm'           => 'Dismiss this flag? The dealer\'s feed data is incorrect.',
            'gddealer_flag_no_note'                   => 'No admin note.',
            'gddealer_flag_resolved'                  => 'Flag resolved.',
            'gddealer_flag_dismissed_acp'             => 'Flag dismissed.',
            'gddealer_flag_note_saved'                => 'Note saved.',
            'menu__gddealer_dealers_flaggedUpcs'      => 'Flagged UPCs',
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
