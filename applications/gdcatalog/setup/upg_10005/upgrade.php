<?php
namespace IPS\gdcatalog\setup\upg_10005;

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
		/* v1.0.5: file moves only (dev/js/admin/feedSort.js -> interface/feedSort.js)
		 * and controller change to use interface/ path. No DB changes.
		 *
		 * Why the move: IPS\Output::i()->js() only serves dev/js/ paths in IN_DEV mode.
		 * In production mode (Derrick's install) the call returns an empty array unless
		 * the JS has been compiled via the IPS dev build process. The interface/ path
		 * branch in Output::js() serves files directly without compilation - the right
		 * pattern for shipped plugin JS.
		 *
		 * Just clear caches so any stale theme/javascript references are flushed. */

		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'javascript_%'" ] ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/javascript_*' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'v1.0.5 - move feedSort.js to interface/ path so production mode can serve it';
	}
}

class upgrade extends _upgrade {}
