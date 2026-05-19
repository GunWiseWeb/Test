<?php
namespace IPS\gddealer\setup\upg_10212;

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
		/* v1.0.212 — Structural fix: canonical template enforcement.
		 *
		 * Calls CanonicalTemplates::ensure() to force the 10 managed
		 * templates to match their .tpl files. After this upgrade,
		 * fresh install and upgrade paths produce the same DB state
		 * for these templates.
		 *
		 * Every future upgrade.php should ALSO call ensure() as its
		 * last step (before cache busts). Pattern:
		 *
		 *   require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
		 *   \IPS\gddealer\Setup\CanonicalTemplates::ensure();
		 *   \IPS\gddealer\Setup\CanonicalTemplates::clearCaches();
		 */

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';
			$result = \IPS\gddealer\Setup\CanonicalTemplates::ensure();
			\IPS\gddealer\Setup\CanonicalTemplates::clearCaches();

			try {
				\IPS\Log::log(
					'upg_10212 canonical pass: written=' . $result['written']
					. ' errors=' . count( $result['errors'] )
					. ( !empty( $result['errors'] ) ? ' details: ' . implode( '; ', $result['errors'] ) : '' ),
					'gddealer_upg_10212'
				);
			} catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'upg_10212 failed: ' . $e->getMessage(), 'gddealer_upg_10212' ); }
			catch ( \Throwable ) {}
		}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
