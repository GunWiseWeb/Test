<?php

namespace IPS\gddeals\setup\upg_10049;

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
		/* Register the new leaderboard front module (idempotent — check first). */
		try
		{
			$exists = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'core_modules',
				[ 'sys_module_application=? AND sys_module_area=? AND sys_module_key=?',
				  'gddeals', 'front', 'leaderboard' ] )->first();
			if ( !$exists )
			{
				\IPS\Db::i()->insert( 'core_modules', [
					'sys_module_application' => 'gddeals',
					'sys_module_key'         => 'leaderboard',
					'sys_module_protected'   => 0,
					'sys_module_visible'     => 1,
					'sys_module_area'        => 'front',
					'sys_module_default'     => 0,
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10049 register module: ' . $e->getMessage(), 'gddeals_upgrade' ); } catch ( \Throwable ) {}
		}

		/* Register the leaderboard ACP menu item under the deals tab. */
		try
		{
			$exists = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'core_acp_tab_items',
				[ 'app=? AND `key`=?', 'gddeals', 'leaderboard' ] )->first();
			if ( !$exists )
			{
				$maxPos = 0;
				try { $maxPos = (int) \IPS\Db::i()->select( 'MAX(position)', 'core_acp_tab_items', [ 'tab=?', 'gddeals' ] )->first(); } catch ( \Throwable ) {}
				\IPS\Db::i()->insert( 'core_acp_tab_items', [
					'tab'         => 'gddeals',
					'app'         => 'gddeals',
					'key'         => 'leaderboard',
					'controller'  => 'leaderboard',
					'do'          => '',
					'restriction' => 'settings_manage',
					'position'    => $maxPos + 10,
				] );
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10049 register acp menu: ' . $e->getMessage(), 'gddeals_upgrade' ); } catch ( \Throwable ) {}
		}

		/* Seed settings defaults (only when absent — don't overwrite admin choices). */
		$defaults = [
			'gddeals_lb_enabled'              => '1',
			'gddeals_lb_default_window'       => 'month',
			'gddeals_lb_per_board'            => '25',
			'gddeals_lb_show_top_deals'       => '1',
			'gddeals_lb_show_best_savings'    => '1',
			'gddeals_lb_show_most_clicked'    => '1',
			'gddeals_lb_show_top_dealers'     => '1',
			'gddeals_lb_show_best_value'      => '1',
			'gddeals_lb_show_top_members'     => '1',
			'gddeals_lb_members_exclude_auto' => '1',
		];
		$toSeed = [];
		foreach ( $defaults as $k => $v )
		{
			try { $cur = \IPS\Settings::i()->$k; if ( $cur === null || $cur === '' ) { $toSeed[ $k ] = $v; } }
			catch ( \Throwable ) { $toSeed[ $k ] = $v; }
		}
		if ( !empty( $toSeed ) )
		{
			try { \IPS\Settings::i()->changeValues( $toSeed ); } catch ( \Throwable ) {}
		}

		/* Seed lang words per-language. */
		$words = [
			'menu__gddeals_deals_leaderboard' => 'Leaderboard',
			'gddeals_lb_acp_title'            => 'Leaderboard',
			'gddeals_lb_title'                => 'Top Deals & Dealers',
			'gddeals_lb_subtitle'             => 'The hottest deals, the best-value dealers, and the most active community members.',
			'gddeals_lb_disabled'             => 'The leaderboard is currently disabled.',
			'gddeals_lb_general'              => 'General',
			'gddeals_lb_boards'               => 'Boards',
			'gddeals_lb_enabled'              => 'Enable leaderboard',
			'gddeals_lb_default_window'       => 'Default time window',
			'gddeals_lb_per_board'            => 'Rows per board',
			'gddeals_lb_show_top_deals'       => 'Show Top Deals',
			'gddeals_lb_show_best_savings'    => 'Show Best Savings',
			'gddeals_lb_show_most_clicked'    => 'Show Most Clicked',
			'gddeals_lb_show_top_dealers'     => 'Show Top Dealers',
			'gddeals_lb_show_best_value'      => 'Show Best Value Dealers',
			'gddeals_lb_show_top_members'     => 'Show Top Members',
			'gddeals_lb_members_exclude_auto' => 'Exclude auto-deals from Top Members count',
			'gddeals_lb_board_top_deals'      => 'Top Deals',
			'gddeals_lb_board_best_savings'   => 'Best Savings',
			'gddeals_lb_board_most_clicked'   => 'Most Clicked',
			'gddeals_lb_board_top_dealers'    => 'Top Dealers',
			'gddeals_lb_board_best_value'     => 'Best Value Dealers',
			'gddeals_lb_board_top_members'    => 'Top Members',
			'gddeals_lb_window_week'          => 'This Week',
			'gddeals_lb_window_month'         => 'This Month',
			'gddeals_lb_window_all'           => 'All Time',
			'gddeals_lb_empty_title'          => 'Nothing here yet',
			'gddeals_lb_empty_body'           => 'Check back as the community votes and clicks roll in.',
			'gddeals_lb_score'                => 'Score',
			'gddeals_lb_clicks'               => 'clicks',
			'gddeals_lb_listings'             => 'listings',
			'gddeals_lb_rating'               => 'Rating',
			'gddeals_lb_off'                  => 'off',
			'gddeals_lb_cheapest'             => 'Cheapest on',
			'gddeals_lb_items'                => 'items',
			'gddeals_lb_avg_delta'            => 'Avg delta',
			'gddeals_lb_posts'                => 'posts',
			'gddeals_lb_nav'                  => 'Top Deals & Dealers',
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
		try { unset( \IPS\Data\Store::i()->modules ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
