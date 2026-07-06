<?php
/**
 * @brief  GD Reviews — upgrade 1.0.2 (HOTFIX).
 *
 * v1.0.1 declared a `use \IPS\Content\<phantom trait>;` line inside
 * the Product Content Item class body. IPS 5.0.18 has no such
 * trait — every page hit fatalled with "Trait ... not found." The
 * v1.0.2 tarball removes the offending line; product reviewability
 * is wired via the $reviewClass static property (the pattern IPS's
 * Downloads app uses on \IPS\downloads\File).
 *
 * Pure controller / model cache invalidation — no schema changes,
 * no lang changes, no route changes. Just force IPS to re-resolve
 * the Product class file so the fatal is gone.
 */

namespace IPS\gdreviews\setup\upg_10002;

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
		try { unset( \IPS\Data\Store::i()->furl_configuration ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->furl ); }               catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
