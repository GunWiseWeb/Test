<?php
namespace IPS\gddealer\setup\upg_10213;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) {
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}
class _upgrade
{
	public function step1(): bool
	{
		/* v1.0.213 — Register dev/css/ into IPS Theme system, compile
		 * dealer.css to a served URL, re-seed canonical templates. */

		/* 1. Import dev CSS into core_theme_css (IPS 5 native) */
		try {
			if ( class_exists( '\\IPS\\Theme\\Dev\\Theme' ) ) {
				\IPS\Theme\Dev\Theme::importDevCss( 'gddealer', 0 );
			}
		}
		catch ( \Throwable $e ) {
			try { \IPS\Log::log( 'upg_10213 importDevCss failed: ' . $e->getMessage(), 'gddealer_upg_10213' ); }
			catch ( \Throwable ) {}
		}

		/* 2. Compile dealer.css into served URLs */
		try {
			foreach ( \IPS\Theme::themes() as $theme ) {
				try {
					if ( method_exists( $theme, 'compileCss' ) ) {
						$theme->compileCss( 'gddealer', 'front', '.', 'dealer.css' );
					}
				} catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e ) {
			try { \IPS\Log::log( 'upg_10213 compileCss failed: ' . $e->getMessage(), 'gddealer_upg_10213' ); }
			catch ( \Throwable ) {}
		}

		/* 3. Re-seed canonical templates */
		try {
			require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
			$result = \IPS\gddealer\Setup\CanonicalTemplates::ensure();
			try {
				\IPS\Log::log(
					'upg_10213 ensure ran=' . count($result['ran'])
					. ' errors=' . count($result['errors']),
					'gddealer_upg_10213'
				);
			} catch ( \Throwable ) {}
		}
		catch ( \Throwable $e ) {
			try { \IPS\Log::log( 'upg_10213 ensure failed: ' . $e->getMessage(), 'gddealer_upg_10213' ); }
			catch ( \Throwable ) {}
		}

		/* 4. Cache busts */
		try {
			\IPS\gddealer\Setup\CanonicalTemplates::clearCaches();
		} catch ( \Throwable ) {}

		return TRUE;
	}
}
class upgrade extends _upgrade {}
