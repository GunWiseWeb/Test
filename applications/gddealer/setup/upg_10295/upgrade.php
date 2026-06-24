<?php

namespace IPS\gddealer\setup\upg_10295;

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
		/* Re-seed the unmatched template to swap ipsMessage--info bar for gdNote */
		try
		{
			$body = \IPS\Db::i()->select( 'template_content', 'core_theme_templates', [
				'template_app=? AND template_name=? AND template_location=?',
				'gddealer', 'unmatched', 'front',
			] )->first();

			if ( strpos( $body, 'ipsMessage ipsMessage--info' ) !== false && strpos( $body, 'gddealer_front_unmatched_60day' ) !== false )
			{
				$old = '<div class="ipsMessage ipsMessage--info" style="margin:0 0 16px 0">{lang="gddealer_front_unmatched_60day"}</div>';
				$new = '<div class="gdNote" role="note">' . "\n"
				     . "\t\t" . '<span class="gdNote__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>' . "\n"
				     . "\t\t" . '<span class="gdNote__text">{lang="gddealer_front_unmatched_60day"}</span>' . "\n"
				     . "\t" . '</div>';
				$body = str_replace( $old, $new, $body );

				\IPS\Db::i()->update( 'core_theme_templates', [
					'template_content' => $body,
					'template_updated' => time(),
					'template_version' => '1.0.295',
				], [
					'template_app=? AND template_name=? AND template_location=?',
					'gddealer', 'unmatched', 'front',
				] );
			}
		}
		catch ( \Throwable ) {}

		/* Canonical templates + cache clear */
		try
		{
			$canonical = \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
			if ( file_exists( $canonical ) )
			{
				require_once $canonical;
				\IPS\gddealer\Setup\CanonicalTemplates::ensure();
				\IPS\gddealer\Setup\CanonicalTemplates::clearCaches();
			}
		}
		catch ( \Throwable ) {}

		try { \IPS\Data\Store::i()->clearAll(); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
