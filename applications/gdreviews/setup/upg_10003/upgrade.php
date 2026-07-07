<?php
/**
 * @brief  GD Reviews — upgrade 1.0.3 (HOTFIX).
 *
 * v1.0.2 declared canView() as `public static function` on the
 * Product Content Item. IPS 5.0.18's \IPS\Content\Item::canView()
 * is a NON-STATIC instance method, so class autoload fatalled at
 * compile with "Cannot make non static method canView() static."
 * The v1.0.3 tarball drops the `static` keyword on canView() and
 * aligns canCreate() to core's exact 3-parameter signature.
 *
 * Contract mirrored from \IPS\downloads\File:
 *   STATIC   — canCreate( Member, ?Node\Model, bool )
 *   INSTANCE — canView / canEdit / canDelete / canEditTitle
 *
 * Pure signature fix — no schema, no lang, no route change. Just
 * force IPS to re-resolve the Product class so the fatal is gone.
 */

namespace IPS\gdreviews\setup\upg_10003;

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
