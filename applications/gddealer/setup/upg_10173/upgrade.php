<?php
namespace IPS\gddealer\setup\upg_10173;

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
		/* v1.0.173 - extends v1.0.172's founding member display fix to profile.php
		 * and dashboard.php. v1.0.172 only updated directory.php; the dealer's
		 * own profile page and their dashboard still showed "Basic Dealer" / etc
		 * instead of "Founder" badge.
		 *
		 * Two PHP file edits, no schema changes, no migration. Just a cache
		 * clear so any stale opcache/template caches don't serve old labels. */

		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'v1.0.173 - extend founding badge display to profile + dashboard pages';
	}
}

class upgrade extends _upgrade {}
