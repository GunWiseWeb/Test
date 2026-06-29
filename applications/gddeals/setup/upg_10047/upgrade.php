<?php

namespace IPS\gddeals\setup\upg_10047;

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
		/* Seed defaults for the new auto-deal settings — only when currently
		   empty/null, so we never overwrite an admin choice. */
		$defaults = [
			'gddeals_auto_enabled'        => '1',
			'gddeals_auto_approve'        => '0',
			'gddeals_auto_cap'            => '50',
			'gddeals_auto_badge_label'    => 'Auto Deal',
			'gddeals_type_lowest_ever'    => '1',
			'gddeals_type_lowest_30d'     => '1',
			'gddeals_type_msrp_off'       => '1',
			'gddeals_type_price_drop'     => '1',
			'gddeals_type_back_in_stock'  => '1',
			'gddeals_type_rare_find'      => '1',
			'gddeals_type_free_ship'      => '1',
			'gddeals_thr_msrp_pct'        => '25',
			'gddeals_thr_drop_pct'        => '15',
			'gddeals_thr_drop_hours'      => '48',
			'gddeals_thr_rare_max'        => '3',
			'gddeals_thr_back_days'       => '14',
			'gddeals_thr_30d_days'        => '30',
			'gddeals_wt_msrp'             => '1.0',
			'gddeals_wt_drop'             => '0.8',
			'gddeals_wt_drop_flag'        => '10',
			'gddeals_wt_back'             => '8',
			'gddeals_wt_freeship'         => '6',
			'gddeals_wt_lowest_ever'      => '4',
			'gddeals_wt_lowest_30d'       => '2',
			'gddeals_wt_rare'             => '2',
			'gddeals_front_lowest_ever'   => '1',
			'gddeals_front_lowest_30d'    => '1',
			'gddeals_front_msrp_off'      => '1',
			'gddeals_front_price_drop'    => '1',
			'gddeals_front_back_in_stock' => '1',
			'gddeals_front_rare_find'     => '1',
			'gddeals_front_free_ship'     => '1',
		];
		$toSeed = [];
		foreach ( $defaults as $k => $v )
		{
			try
			{
				$cur = \IPS\Settings::i()->$k;
				if ( $cur === null || $cur === '' )
				{
					$toSeed[ $k ] = $v;
				}
			}
			catch ( \Throwable )
			{
				$toSeed[ $k ] = $v;
			}
		}
		if ( !empty( $toSeed ) )
		{
			try { \IPS\Settings::i()->changeValues( $toSeed ); } catch ( \Throwable ) {}
		}

		/* Seed lang words per-language. */
		$words = [
			'menu__deals_autodeals'           => 'Auto Deals',
			'gddeals_ad_title'                => 'Auto Deals',
			'gddeals_ad_recompute'            => 'Recompute now',
			'gddeals_ad_recompute_hint'       => 'Apply changes immediately without waiting for the next import.',
			'gddeals_ad_recompute_done'       => 'Auto deals recomputed.',
			'gddeals_ad_recompute_partial'    => 'Recompute ran but one or more steps failed — check the logs.',
			'gddeals_ad_generation'           => 'Generation',
			'gddeals_ad_types'                => 'Deal Types',
			'gddeals_ad_thresholds'           => 'Thresholds',
			'gddeals_ad_weights'              => 'Score Weights',
			'gddeals_ad_placement'            => 'Front-Page Placement',
			'gddeals_auto_enabled'            => 'Enable auto-deal generation',
			'gddeals_auto_approve'            => 'Auto-approve (skip moderation)',
			'gddeals_auto_cap'                => 'Maximum auto deals',
			'gddeals_auto_badge_label'        => 'Auto-deal badge label',
			'gddeals_type_lowest_ever'        => 'Lowest ever',
			'gddeals_type_lowest_30d'         => 'Lowest in 30 days',
			'gddeals_type_msrp_off'           => 'Discount off MSRP',
			'gddeals_type_price_drop'         => 'Recent price drop',
			'gddeals_type_back_in_stock'      => 'Back in stock',
			'gddeals_type_rare_find'          => 'Rare find (few dealers)',
			'gddeals_type_free_ship'          => 'Free-shipping steal',
			'gddeals_thr_msrp_pct'            => 'MSRP-off threshold (%)',
			'gddeals_thr_drop_pct'            => 'Price-drop threshold (%)',
			'gddeals_thr_drop_hours'          => 'Price-drop lookback (hours)',
			'gddeals_thr_rare_max'            => 'Rare-find max dealers',
			'gddeals_thr_back_days'           => 'Back-in-stock lookback (days)',
			'gddeals_thr_30d_days'            => 'Lowest-in-N-days window',
			'gddeals_wt_msrp'                 => 'Weight: MSRP %',
			'gddeals_wt_drop'                 => 'Weight: Drop %',
			'gddeals_wt_drop_flag'            => 'Weight: Price-drop flag bonus',
			'gddeals_wt_back'                 => 'Weight: Back-in-stock flag',
			'gddeals_wt_freeship'             => 'Weight: Free-shipping steal',
			'gddeals_wt_lowest_ever'          => 'Weight: Lowest ever',
			'gddeals_wt_lowest_30d'           => 'Weight: Lowest in 30 days',
			'gddeals_wt_rare'                 => 'Weight: Rare find',
			'gddeals_front_lowest_ever'       => 'Show on front page: Lowest ever',
			'gddeals_front_lowest_30d'        => 'Show on front page: Lowest in 30 days',
			'gddeals_front_msrp_off'          => 'Show on front page: MSRP off',
			'gddeals_front_price_drop'        => 'Show on front page: Price drop',
			'gddeals_front_back_in_stock'     => 'Show on front page: Back in stock',
			'gddeals_front_rare_find'         => 'Show on front page: Rare find',
			'gddeals_front_free_ship'         => 'Show on front page: Free shipping',
		];
		try
		{
			foreach ( \IPS\Lang::languages() as $lang )
			{
				foreach ( $words as $k => $v )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $lang->id,
							'word_app'     => 'gddeals',
							'word_key'     => $k,
							'word_default' => $v,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->acpmenu ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
