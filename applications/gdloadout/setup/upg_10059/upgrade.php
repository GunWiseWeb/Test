<?php

namespace IPS\gdloadout\setup\upg_10059;

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
		$defaults = [
			'gdloadout_beta_notice_enabled'     => '1',
			'gdloadout_beta_notice_title'       => 'Gun Rack Deals is in active development',
			'gdloadout_beta_notice_body'        => "We're still building this thing out — some features may be incomplete or behave unexpectedly, and products may occasionally appear in the wrong category as we tune our catalog system. Pricing, availability, and listings are being verified continuously.",
			'gdloadout_beta_notice_url_contact' => '/contact/',
			'gdloadout_beta_notice_url_forums'  => '/forums/',
		];
		try { \IPS\Settings::i()->changeValues( $defaults ); } catch ( \Throwable ) {}

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}

class upgrade extends _upgrade {}
