<?php

namespace IPS\gddealer\setup\upg_10308;

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

		$path = \IPS\ROOT_PATH . '/applications/gddealer/dev/html/front/dealers/feedSchema.phtml';

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
						'gddealer', 'front', 'dealers', 'feedSchema', 1
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
						'template_name'     => 'feedSchema',
						'template_data'     => '',
						'template_content'  => $body,
						'template_updated'  => time(),
						'template_version'  => '1.0.308',
					] );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'upg_10308 reseed feedSchema failed: ' . $e->getMessage(), 'gddealer_upg_10308' ); } catch ( \Throwable ) {}
				}
			}
		}

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
