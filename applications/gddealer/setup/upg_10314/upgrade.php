<?php

namespace IPS\gddealer\setup\upg_10314;

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

		$templates = [
			'dealerDirectory'    => [
				'path' => $devDir . '/dealerDirectory.phtml',
				'data' => '$dealers, $total, $page, $perPage, $pagination, $sort, $search, $stateParam, $minRating, $states, $stateCounts, $stateList, $loggedIn, $joinUrl, $directoryUrl',
			],
			'dashboardCustomize' => [
				'path' => $devDir . '/dashboardCustomize.phtml',
				'data' => '$data',
			],
		];

		foreach ( $templates as $name => $cfg )
		{
			if ( !is_readable( $cfg['path'] ) ) { continue; }

			$body = file_get_contents( $cfg['path'] );
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
					'template_data'     => $cfg['data'],
					'template_content'  => $body,
					'template_updated'  => time(),
					'template_version'  => '1.0.314',
				] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( "upg_10314 reseed $name: " . $e->getMessage(), 'gddealer_upgrade' ); } catch ( \Throwable ) {}
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
