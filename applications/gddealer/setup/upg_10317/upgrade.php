<?php

namespace IPS\gddealer\setup\upg_10317;

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
		/* Seed the two new lang words per language. The MyDealers profile-tab key
		   was never seeded so the tab label was rendering as the raw key. */
		$strings = [
			'profile_gddealer_MyDealers' => 'My Dealers',
			'gddealer_my_dealers_link'   => 'Dealers I Follow',
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

		/* Reseed the dealerDirectory template (header link + mobile-list-view fix). */
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
						'template_version'  => '1.0.317',
					] );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'upg_10317 reseed dealerDirectory: ' . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
				}
			}
		}

		/* Self-heal extensions.json so MyDealersNav is registered even if a stale
		   datastore cache stripped the entry on concurrent upgrade requests. */
		try
		{
			$extJsonPath = \IPS\ROOT_PATH . '/applications/gddealer/data/extensions.json';
			if ( is_readable( $extJsonPath ) && is_writable( $extJsonPath ) )
			{
				$data = json_decode( file_get_contents( $extJsonPath ), true );
				if ( is_array( $data ) )
				{
					$needs = [
						'DealerNav'    => 'IPS\\gddealer\\extensions\\core\\FrontNavigation\\DealerNav',
						'MyDealersNav' => 'IPS\\gddealer\\extensions\\core\\FrontNavigation\\MyDealersNav',
					];
					$current = $data['FrontNavigation'] ?? [];
					$changed = false;
					foreach ( $needs as $k => $cls )
					{
						if ( !isset( $current[ $k ] ) )
						{
							$current[ $k ] = $cls;
							$changed = true;
						}
					}
					if ( $changed )
					{
						$data['FrontNavigation'] = $current;
						file_put_contents( $extJsonPath, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
					}
				}
			}
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}

		require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		\IPS\gddealer\Setup\CanonicalTemplates::purgeCanonicalTemplates();

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
