<?php

namespace IPS\gddealer\setup\upg_10303;

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
		require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		\IPS\gddealer\Setup\CanonicalTemplates::purgeCanonicalTemplates();

		$devDir = \IPS\ROOT_PATH . '/applications/gddealer/dev/html/front/dealers';

		$templates = [
			'analytics'    => $devDir . '/analytics.phtml',
			'feedSettings' => $devDir . '/feedSettings.phtml',
		];

		foreach ( $templates as $name => $path )
		{
			if ( !is_readable( $path ) ) { continue; }

			$body = file_get_contents( $path );
			if ( $body === false || $body === '' ) { continue; }

			$body = preg_replace( '/^<ips:template[^>]*\/>\s*\n?/', '', $body, 1 );

			try
			{
				\IPS\Db::i()->delete( 'core_theme_templates', [
					'template_app=? AND template_location=? AND template_group=? AND template_name=? AND template_set_id<>?',
					'gddealer', 'front', 'dealers', $name, 1
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
					'template_name'     => $name,
					'template_data'     => '$data',
					'template_content'  => $body,
					'template_updated'  => time(),
					'template_version'  => '1.0.303',
				] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( "upg_10303 reseed $name failed: " . $e->getMessage(), 'gddealer_upg_10303' ); } catch ( \Throwable ) {}
			}
		}

		foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
		{
			$strings = [
				'gddealer_analytics_upsell_title' => 'Unlock more analytics',
				'gddealer_analytics_upsell_basic' => 'Upgrade to Pro to see your daily clicks chart and top-clicked listings. Upgrade to Enterprise for price competitiveness, rank breakdown, and geographic distribution.',
				'gddealer_analytics_upsell_pro'   => 'Upgrade to Enterprise to unlock price competitiveness counts, rank-tier breakdown, and geographic distribution of your click-throughs.',
				'gddealer_analytics_upsell_cta'   => 'View plans',
			];
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

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
