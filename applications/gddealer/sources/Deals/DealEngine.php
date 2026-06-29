<?php
/**
 * @brief       GD Dealer Manager — Deal Engine (Phase 1)
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       v1.0.319
 *
 * Computes 7 automatic "deal type" flags on gd_dealer_listings via
 * set-based SQL after each feed import:
 *
 *   1. lowest_ever       — current price is the lowest ever recorded
 *                          for this (upc, dealer)
 *   2. lowest_30d        — lowest within the last 30 days
 *   3. msrp_off          — >= MSRP_OFF_PCT below catalog MSRP
 *   4. price_drop        — >= DROP_PCT below price from DROP_HOURS ago
 *   5. back_in_stock     — currently in stock AND was OOS within
 *                          BACK_IN_DAYS days
 *   6. rare_find         — <= RARE_MAX dealers stock this UPC
 *   7. free_ship_steal   — free shipping AND lowest price for the UPC
 *
 * Each UPDATE runs in its own try/catch so one failing type cannot
 * block the others. computeAll() returns per-type affected-row counts.
 */

namespace IPS\gddealer\Deals;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _DealEngine
{
	/* Default thresholds — Phase 3 will move these to settings. */
	const MSRP_OFF_PCT  = 25.0;   /* 25%+ off MSRP */
	const DROP_PCT      = 15.0;   /* 15%+ price drop */
	const DROP_HOURS    = 48;     /* in the last 48 hours */
	const RARE_MAX      = 3;      /* <= 3 dealers stock this UPC */
	const BACK_IN_DAYS  = 14;     /* was OOS within last 14 days */

	/**
	 * Run all 7 deal-flag computations across active listings.
	 * Returns affected-row counts keyed by deal type for logging.
	 */
	public static function computeAll(): array
	{
		$p       = \IPS\Db::i()->prefix;
		$results = [];

		/* Read settings with constant fallback. Empty settings can never break
		   the pipeline — they just fall back to the original Phase 1 defaults. */
		$S         = \IPS\Settings::i();
		$msrpPct   = self::settingFloat( $S, 'gddeals_thr_msrp_pct',   self::MSRP_OFF_PCT );
		$dropPct   = self::settingFloat( $S, 'gddeals_thr_drop_pct',   self::DROP_PCT );
		$dropHours = self::settingInt(   $S, 'gddeals_thr_drop_hours', self::DROP_HOURS );
		$rareMax   = self::settingInt(   $S, 'gddeals_thr_rare_max',   self::RARE_MAX );
		$backDays  = self::settingInt(   $S, 'gddeals_thr_back_days',  self::BACK_IN_DAYS );
		$d30       = self::settingInt(   $S, 'gddeals_thr_30d_days',   30 );

		/* Per-type enable gates. When disabled, the reset still zeroes the
		   flag, but the UPDATE that would set it is skipped. */
		$enabled = [
			'lowest_ever'     => self::settingBool( $S, 'gddeals_type_lowest_ever',    true ),
			'lowest_30d'      => self::settingBool( $S, 'gddeals_type_lowest_30d',     true ),
			'msrp_off'        => self::settingBool( $S, 'gddeals_type_msrp_off',       true ),
			'price_drop'      => self::settingBool( $S, 'gddeals_type_price_drop',     true ),
			'back_in_stock'   => self::settingBool( $S, 'gddeals_type_back_in_stock',  true ),
			'rare_find'       => self::settingBool( $S, 'gddeals_type_rare_find',      true ),
			'free_ship_steal' => self::settingBool( $S, 'gddeals_type_free_ship',      true ),
		];

		/* 0. Reset all deal flags on active listings — so dealers that no longer
		   qualify stop flying the flag from the previous run. */
		$results['reset'] = self::run(
			'reset',
			"UPDATE `{$p}gd_dealer_listings`
			 SET deal_lowest_ever=0, deal_lowest_30d=0, deal_msrp_off=0, deal_msrp_pct=0,
			     deal_price_drop=0, deal_drop_pct=0, deal_back_in_stock=0,
			     deal_rare_find=0, deal_dealer_count=0, deal_free_ship_steal=0
			 WHERE listing_status='active'"
		);

		/* A. lowest_ever */
		if ( $enabled['lowest_ever'] )
		{
			$results['lowest_ever'] = self::run(
				'lowest_ever',
				"UPDATE `{$p}gd_dealer_listings` l
				 JOIN ( SELECT upc, dealer_id, MIN(price) AS lo
				        FROM `{$p}gd_price_history` GROUP BY upc, dealer_id ) h
				   ON h.upc = l.upc AND h.dealer_id = l.dealer_id
				 SET l.deal_lowest_ever = 1
				 WHERE l.listing_status = 'active' AND l.dealer_price <= h.lo"
			);
		}

		/* B. lowest_30d */
		if ( $enabled['lowest_30d'] )
		{
			$results['lowest_30d'] = self::run(
				'lowest_30d',
				"UPDATE `{$p}gd_dealer_listings` l
				 JOIN ( SELECT upc, dealer_id, MIN(price) AS lo
				        FROM `{$p}gd_price_history`
				        WHERE recorded_at >= ( NOW() - INTERVAL {$d30} DAY )
				        GROUP BY upc, dealer_id ) h
				   ON h.upc = l.upc AND h.dealer_id = l.dealer_id
				 SET l.deal_lowest_30d = 1
				 WHERE l.listing_status = 'active' AND l.dealer_price <= h.lo"
			);
		}

		/* C. msrp_off (sets pct on all matching rows; flag only when >= threshold) */
		if ( $enabled['msrp_off'] )
		{
		$results['msrp_off'] = self::run(
			'msrp_off',
			"UPDATE `{$p}gd_dealer_listings` l
			 JOIN `{$p}gd_catalog` c ON c.upc = l.upc
			 SET l.deal_msrp_pct = ROUND( ((c.msrp - l.dealer_price) / c.msrp) * 100, 2 ),
			     l.deal_msrp_off = ( ((c.msrp - l.dealer_price) / c.msrp) * 100 >= {$msrpPct} )
			 WHERE l.listing_status = 'active'
			   AND c.msrp > 0
			   AND l.dealer_price > 0
			   AND l.dealer_price < c.msrp"
		);
		}

		/* D. price_drop */
		if ( $enabled['price_drop'] )
		{
			$results['price_drop'] = self::run(
				'price_drop',
				"UPDATE `{$p}gd_dealer_listings` l
				 JOIN (
				   SELECT ph.upc, ph.dealer_id, ph.price AS old_price
				   FROM `{$p}gd_price_history` ph
				   JOIN ( SELECT upc, dealer_id, MAX(recorded_at) AS mx
				          FROM `{$p}gd_price_history`
				          WHERE recorded_at <= ( NOW() - INTERVAL {$dropHours} HOUR )
				          GROUP BY upc, dealer_id ) m
				     ON m.upc = ph.upc AND m.dealer_id = ph.dealer_id AND m.mx = ph.recorded_at
				 ) b ON b.upc = l.upc AND b.dealer_id = l.dealer_id
				 SET l.deal_drop_pct   = ROUND( ((b.old_price - l.dealer_price) / b.old_price) * 100, 2 ),
				     l.deal_price_drop = ( b.old_price > 0 AND ((b.old_price - l.dealer_price) / b.old_price) * 100 >= {$dropPct} )
				 WHERE l.listing_status = 'active' AND b.old_price > 0 AND l.dealer_price < b.old_price"
			);
		}

		/* E. back_in_stock */
		if ( $enabled['back_in_stock'] )
		{
			$results['back_in_stock'] = self::run(
				'back_in_stock',
				"UPDATE `{$p}gd_dealer_listings` l
				 SET l.deal_back_in_stock = 1
				 WHERE l.listing_status = 'active' AND l.in_stock = 1
				   AND EXISTS (
				     SELECT 1 FROM `{$p}gd_price_history` ph
				     WHERE ph.upc = l.upc AND ph.dealer_id = l.dealer_id
				       AND ph.in_stock = 0
				       AND ph.recorded_at >= ( NOW() - INTERVAL {$backDays} DAY )
				   )"
			);
		}

		/* F. rare_find — sets count + flag */
		if ( $enabled['rare_find'] )
		{
			$results['rare_find'] = self::run(
				'rare_find',
				"UPDATE `{$p}gd_dealer_listings` l
				 JOIN ( SELECT upc, COUNT(DISTINCT dealer_id) AS dc
				        FROM `{$p}gd_dealer_listings`
				        WHERE listing_status = 'active' AND in_stock = 1
				        GROUP BY upc ) d
				   ON d.upc = l.upc
				 SET l.deal_dealer_count = d.dc,
				     l.deal_rare_find    = ( d.dc <= {$rareMax} )
				 WHERE l.listing_status = 'active'"
			);
		}

		/* G. free_ship_steal — free shipping AND tied-for-lowest active in-stock price */
		if ( $enabled['free_ship_steal'] )
		{
			$results['free_ship_steal'] = self::run(
				'free_ship_steal',
				"UPDATE `{$p}gd_dealer_listings` l
				 JOIN ( SELECT upc, MIN(dealer_price) AS lo
				        FROM `{$p}gd_dealer_listings`
				        WHERE listing_status = 'active' AND in_stock = 1
				        GROUP BY upc ) p
				   ON p.upc = l.upc
				 SET l.deal_free_ship_steal = 1
				 WHERE l.listing_status = 'active' AND l.in_stock = 1
				   AND l.dealer_price <= p.lo
				   AND l.shipping_info IS NOT NULL
				   AND LOWER(l.shipping_info) LIKE '%free%'"
			);
		}

		/* Timestamp the computation. */
		$results['computed_at'] = self::run(
			'computed_at',
			"UPDATE `{$p}gd_dealer_listings`
			 SET deals_computed_at = NOW()
			 WHERE listing_status = 'active'"
		);

		try
		{
			\IPS\Log::log(
				'DealEngine::computeAll ' . implode( ' ', array_map(
					fn( $k, $v ) => "$k=$v",
					array_keys( $results ), array_values( $results )
				) ),
				'gddealer_deals'
			);
		}
		catch ( \Throwable ) {}

		return $results;
	}

	protected static function settingFloat( \IPS\Settings $S, string $key, float $default ): float
	{
		try { $v = $S->$key; } catch ( \Throwable ) { return $default; }
		if ( $v === null || $v === '' ) { return $default; }
		return (float) $v;
	}

	protected static function settingInt( \IPS\Settings $S, string $key, int $default ): int
	{
		try { $v = $S->$key; } catch ( \Throwable ) { return $default; }
		if ( $v === null || $v === '' ) { return $default; }
		return (int) $v;
	}

	protected static function settingBool( \IPS\Settings $S, string $key, bool $default ): bool
	{
		try { $v = $S->$key; } catch ( \Throwable ) { return $default; }
		if ( $v === null || $v === '' ) { return $default; }
		return (bool) (int) $v;
	}

	/**
	 * Run one UPDATE statement with its own try/catch.
	 * Returns the affected-row count, or 0 on throw.
	 */
	protected static function run( string $label, string $sql ): int
	{
		try
		{
			$result = \IPS\Db::i()->query( $sql );

			/* mysqli affected rows: \IPS\Db::i()->affected_rows or use mysqli's
			   stmt result. Db->query returns mysqli_result for SELECT, bool/true
			   for UPDATE. Fall back to ->affected_rows on the wrapper. */
			try
			{
				return (int) \IPS\Db::i()->affected_rows;
			}
			catch ( \Throwable )
			{
				return 0;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( "DealEngine[$label] failed: " . $e->getMessage(), 'gddealer_deals' ); } catch ( \Throwable ) {}
			return 0;
		}
	}
}

class DealEngine extends _DealEngine {}
