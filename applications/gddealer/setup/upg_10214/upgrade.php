<?php
namespace IPS\gddealer\setup\upg_10214;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) {
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}
class _upgrade
{
	public function step1(): bool
	{
		/* v1.0.214 — Final structural fix: REPLACE-based template writes
		 * with per-template byte verification logged to core_log. */

		try {
			require_once \IPS\ROOT_PATH . '/applications/gddealer/sources/Setup/CanonicalTemplates.php';

			$result = \IPS\gddealer\Setup\CanonicalTemplates::ensure();

			$forced = \IPS\gddealer\Setup\CanonicalTemplates::verifyAndForce();

			try {
				$msg = 'upg_10214 ensure ran=' . count($result['ran'])
					. ' errors=' . count($result['errors'])
					. ' verifyAndForce: ';
				foreach ( $forced as $n => $r ) {
					$msg .= "$n=" . $r['bytes'] . ' ';
				}
				\IPS\Log::log( $msg, 'gddealer_upg_10214' );
			} catch ( \Throwable ) {}
		}
		catch ( \Throwable $e ) {
			try { \IPS\Log::log( 'upg_10214 failed: ' . $e->getMessage(), 'gddealer_upg_10214' ); }
			catch ( \Throwable ) {}
		}

		/* Cache busts */
		try {
			\IPS\gddealer\Setup\CanonicalTemplates::clearCaches();
		} catch ( \Throwable ) {}

		return TRUE;
	}
}
class upgrade extends _upgrade {}
