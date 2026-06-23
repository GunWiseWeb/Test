<?php

namespace IPS\gddealer\setup\upg_10288;

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
		/* Seed new listing-cap lang strings for existing installs */
		$newStrings = [
			'gddealer_caps_header'          => 'Listing Caps (per tier)',
			'gddealer_cap_basic'            => 'Basic Cap',
			'gddealer_cap_basic_desc'       => 'Maximum active listings for Basic-tier dealers. 0 = unlimited.',
			'gddealer_cap_pro'              => 'Pro Cap',
			'gddealer_cap_pro_desc'         => 'Maximum active listings for Pro-tier dealers. 0 = unlimited.',
			'gddealer_cap_enterprise'       => 'Enterprise Cap',
			'gddealer_cap_enterprise_desc'  => 'Maximum active listings for Enterprise-tier dealers. 0 = unlimited.',
			'gddealer_cap_max'              => 'Max Cap',
			'gddealer_cap_max_desc'         => 'Maximum active listings for Max-tier dealers. 0 = unlimited.',
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
							'word_app'     => 'gddealer',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e ) {}
				}
			}
		}
		catch ( \Throwable $e ) {}

		require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		\IPS\gddealer\Setup\CanonicalTemplates::ensure();
		\IPS\gddealer\Setup\CanonicalTemplates::clearCaches();

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable $e ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable $e ) {}
		try { \IPS\Data\Store::i()->clearAll(); }            catch ( \Throwable $e ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable $e ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
