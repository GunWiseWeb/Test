<?php
/**
 * @brief  GD Reviews — upgrade 1.0.6 (HOTFIX).
 *
 * v1.0.5's Product ActiveRecord config set:
 *
 *   $databaseColumnId = 'product_id';
 *   $databasePrefix   = 'product_';
 *
 * IPS builds the PK column name as ($databasePrefix . $databaseColumnId)
 * = "product_" + "product_id" = "product_product_id" — a column that
 * does not exist in gdreviews_products. Any load / renderSection call
 * threw "Undefined array key product_product_id" and the reviews
 * section died on every hit. Review.php had the same double-prefix
 * bug (review_ + review_id = review_review_id).
 *
 * v1.0.6 sets the columnId to the UNPREFIXED "id" on both classes so
 * IPS resolves the PK to product_id / review_id — the real columns.
 * Column-map values on both classes were already unprefixed and
 * therefore correct.
 *
 * Pure ActiveRecord config fix — no schema, lang, or route changes.
 * Cache clear only so IPS re-resolves the two class files.
 */

namespace IPS\gdreviews\setup\upg_10006;

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
