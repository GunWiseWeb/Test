<?php
/**
 * v1.0.199: Defensive fix — ensure listings, feedSettings, unmatched,
 *           analytics, and reviews templates all have '$data' signature.
 */

namespace IPS\gddealer\setup\upg_10199;

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
		$templates = [ 'listings', 'feedSettings', 'unmatched', 'analytics', 'reviews' ];

		foreach ( $templates as $name )
		{
			try
			{
				\IPS\Db::i()->update( 'core_theme_templates',
					[
						'template_data'    => '$data',
						'template_updated' => time(),
						'template_version' => '1.0.199',
					],
					[ 'template_app=? AND template_location=? AND template_group=? AND template_name=? AND template_data != ?',
					  'gddealer', 'front', 'dealers', $name, '$data' ]
				);
			}
			catch ( \Throwable ) {}
		}

		/* Ensure reviews template exists (was missing from install.php before v199) */
		try
		{
			$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'core_theme_templates',
				[ 'template_app=? AND template_location=? AND template_group=? AND template_name=?',
				  'gddealer', 'front', 'dealers', 'reviews' ] )->first();
		}
		catch ( \Throwable ) { $exists = 1; }

		if ( $exists === 0 )
		{
			require_once __DIR__ . '/../templates_10077.php';
		}

		/* Cache bust */
		try { \IPS\Db::i()->delete( 'core_cache' ); }                                                  catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { unset( \IPS\Data\Store::i()->themes ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
