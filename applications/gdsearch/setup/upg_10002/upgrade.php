<?php
namespace IPS\gdsearch\setup\upg_10002;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
    public function step1(): bool
    {
        $strings = [
            'gdsearch_search_submit'       => 'Search',
            'gdsearch_results_for'         => 'results for',
            'gdsearch_results_total'       => 'products',
            'gdsearch_dealers'             => 'dealers',
            'gdsearch_no_listings'         => 'No dealer listings available.',
            'gdsearch_filter_apply'        => 'Apply Filters',
            'gdsearch_filters'             => 'Filters',
            'gdsearch_back_to_results'     => 'Back to Results',
            'gdsearch_mpn'                 => 'MPN',
            'gdsearch_caliber'             => 'Caliber',
            'gdsearch_action_type'         => 'Action',
            'gdsearch_capacity'            => 'Capacity',
            'gdsearch_barrel_length'       => 'Barrel Length',
            'gdsearch_overall_length'      => 'Overall Length',
            'gdsearch_weight'              => 'Weight',
            'gdsearch_condition'           => 'Condition',
            'gdsearch_free_shipping'       => 'Free',
            'gdsearch_view_dealer'         => 'View Dealer',
        ];

        foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId ) {
            foreach ( $strings as $key => $val ) {
                try {
                    \IPS\Db::i()->replace( 'core_sys_lang_words', [
                        'lang_id'      => (int) $langId,
                        'word_app'     => 'gdsearch',
                        'word_key'     => $key,
                        'word_default' => $val,
                        'word_js'      => 0,
                        'word_export'  => 1,
                    ] );
                } catch ( \Throwable ) {}
            }
        }

        try { unset( \IPS\Data\Store::i()->furl ); } catch ( \Throwable ) {}
        try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
        return TRUE;
    }
}
class upgrade extends _upgrade {}
