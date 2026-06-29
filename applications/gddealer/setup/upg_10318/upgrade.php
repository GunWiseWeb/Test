<?php

namespace IPS\gddealer\setup\upg_10318;

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
		/* Seed Directory settings defaults so the page renders identically to the
		   previous hardcoded values until Derrick changes one. Only set values
		   that are currently empty/null (don't overwrite an existing admin choice). */
		$directoryDefaults = [
			'gddealer_dir_map_enabled'        => '1',
			'gddealer_dir_default_sort'       => 'featured',
			'gddealer_dir_per_page'           => '24',
			'gddealer_dir_default_view'       => 'grid',
			'gddealer_dir_hero_eyebrow'       => 'Dealer directory',
			'gddealer_dir_hero_title'         => 'Find a trusted FFL dealer',
			'gddealer_dir_hero_sub'           => 'Every dealer on GunRack is a verified FFL holder. Browse by state, search by name, or filter by rating.',
			'gddealer_dir_join_url'           => 'https://gunrack.deals/dealer-memberships/',
			'gddealer_dir_join_text'          => 'Apply to join',
			'gddealer_dir_show_search'        => '1',
			'gddealer_dir_show_state_filter'  => '1',
			'gddealer_dir_show_rating_filter' => '1',
			'gddealer_dir_show_sort'          => '1',
		];
		$toSeed = [];
		foreach ( $directoryDefaults as $k => $v )
		{
			try
			{
				$current = \IPS\Settings::i()->$k;
				if ( $current === null || $current === '' )
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

		/* Seed lang words per language. */
		$strings = [
			'gddealer_settings_directory'     => 'Directory',
			'gddealer_dir_map_enabled'        => 'Show state map',
			'gddealer_dir_default_sort'       => 'Default sort order',
			'gddealer_dir_per_page'           => 'Dealers per page',
			'gddealer_dir_default_view'       => 'Default view',
			'gddealer_dir_hero_eyebrow'       => 'Hero eyebrow text',
			'gddealer_dir_hero_title'         => 'Hero title',
			'gddealer_dir_hero_sub'           => 'Hero subtitle',
			'gddealer_dir_join_url'           => 'Join CTA link URL',
			'gddealer_dir_join_text'          => 'Join CTA button text',
			'gddealer_dir_show_search'        => 'Show search box',
			'gddealer_dir_show_state_filter'  => 'Show state filter',
			'gddealer_dir_show_rating_filter' => 'Show rating filter',
			'gddealer_dir_show_sort'          => 'Show sort dropdown',
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

		/* Reseed the dealerDirectory template from dev/html (drops PR from the map
		   layout, swaps hardcoded hero/join to {setting=...}, gates each filter +
		   map on the new toggles, default-view class applied server-side). */
		$path = \IPS\ROOT_PATH . '/applications/gddealer/dev/html/front/dealers/dealerDirectory.phtml';
		if ( is_readable( $path ) )
		{
			$body = file_get_contents( $path );
			if ( $body !== false && $body !== '' )
			{
				$body = preg_replace( '/^<ips:template[^>]*\/>\s*\n?/', '', $body, 1 );

				try
				{
					\IPS\Db::i()->delete( 'core_theme_templates', [
						'template_app=? AND template_location=? AND template_group=? AND template_name=? AND template_set_id<>?',
						'gddealer', 'front', 'dealers', 'dealerDirectory', 1
					] );
				}
				catch ( \Throwable ) {}

				try
				{
					\IPS\Db::i()->replace( 'core_theme_templates', [
						'template_set_id'   => 1,
						'template_app'      => 'gddealer',
						'template_location' => 'front',
						'template_group'    => 'dealers',
						'template_name'     => 'dealerDirectory',
						'template_data'     => '$dealers, $total, $page, $perPage, $pagination, $sort, $search, $stateParam, $minRating, $states, $stateCounts, $stateList, $loggedIn, $joinUrl, $directoryUrl',
						'template_content'  => $body,
						'template_updated'  => time(),
						'template_version'  => '1.0.318',
					] );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'upg_10318 reseed dealerDirectory: ' . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
				}
			}
		}

		require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		\IPS\gddealer\Setup\CanonicalTemplates::purgeCanonicalTemplates();

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
