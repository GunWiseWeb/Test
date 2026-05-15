<?php
namespace IPS\gddealer\setup\upg_10203;
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
		try {
			$cols = \IPS\Db::i()->getTableDefinition( 'gd_dealer_listings' );
			$colNames = array_keys( $cols['columns'] ?? [] );

			if ( !in_array( 'shipping_info', $colNames, true ) ) {
				try {
					\IPS\Db::i()->addColumn( 'gd_dealer_listings', [
						'name'       => 'shipping_info',
						'type'       => 'VARCHAR',
						'length'     => 60,
						'allow_null' => true,
						'default'    => null,
					] );
				} catch ( \Throwable $e ) {
					try { \IPS\Log::log( 'upg_10203 addColumn shipping_info failed: ' . $e->getMessage(), 'gddealer_upg_10203' ); } catch ( \Throwable ) {}
				}
			}

			$hasFreeShip = in_array( 'free_shipping', $colNames, true );
			$hasShipCost = in_array( 'shipping_cost', $colNames, true );

			if ( $hasFreeShip ) {
				try {
					\IPS\Db::i()->update( 'gd_dealer_listings',
						[ 'shipping_info' => 'Free shipping' ],
						[ 'free_shipping=? AND (shipping_info IS NULL OR shipping_info = ?)', 1, '' ]
					);
				} catch ( \Throwable $e ) {
					try { \IPS\Log::log( 'upg_10203 backfill free_shipping failed: ' . $e->getMessage(), 'gddealer_upg_10203' ); } catch ( \Throwable ) {}
				}
			}

			if ( $hasShipCost ) {
				try {
					\IPS\Db::i()->preparedQuery(
						"UPDATE " . \IPS\Db::i()->prefix . "gd_dealer_listings
						 SET shipping_info = CONCAT('$', FORMAT(shipping_cost, 2), ' flat')
						 WHERE (shipping_info IS NULL OR shipping_info = '')
						   AND shipping_cost IS NOT NULL
						   AND shipping_cost > 0",
						[]
					);
				} catch ( \Throwable $e ) {
					try { \IPS\Log::log( 'upg_10203 backfill shipping_cost failed: ' . $e->getMessage(), 'gddealer_upg_10203' ); } catch ( \Throwable ) {}
				}
			}

			if ( $hasShipCost ) {
				try { \IPS\Db::i()->dropColumn( 'gd_dealer_listings', 'shipping_cost' ); } catch ( \Throwable ) {}
			}
			if ( $hasFreeShip ) {
				try { \IPS\Db::i()->dropColumn( 'gd_dealer_listings', 'free_shipping' ); } catch ( \Throwable ) {}
			}
		} catch ( \Throwable $e ) {
			try { \IPS\Log::log( 'upg_10203 schema migration failed: ' . $e->getMessage(), 'gddealer_upg_10203' ); } catch ( \Throwable ) {}
		}

		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }       catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
