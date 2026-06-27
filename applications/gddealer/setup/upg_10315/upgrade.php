<?php

namespace IPS\gddealer\setup\upg_10315;

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
		$devDir = \IPS\ROOT_PATH . '/applications/gddealer/dev/html/front/dealers';
		$path   = $devDir . '/dealerDirectory.phtml';

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
						'template_version'  => '1.0.315',
					] );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'upg_10315 reseed dealerDirectory: ' . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
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
