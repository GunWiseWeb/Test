<?php
/**
 * @brief  GD Deals — Auto-expire coupons whose end date has passed.
 *
 * Runs every 15 minutes (see data/tasks.json). One SQL statement:
 * flip the `expired` flag on any post_type='coupon' row whose
 * expires_at is in the past.
 *
 * Why this exists — the DealPublisher / DealEngine pipeline in
 * gddealer handles the auto-deal top-N ranking (heat scores, cap,
 * lowest_ever, price_drop, etc.). Coupons are NOT part of that
 * ranking pass — no code path touches their `expired` column, so
 * an out-of-date coupon would stay `expired=0` forever without
 * this task. Widgets/pages that filter on `expired=0` therefore
 * kept showing stale coupons to customers indefinitely.
 *
 * Gated by the gddeals_coupon_auto_expire setting (default ON) —
 * turning that OFF from ACP disables this task without needing
 * to unregister it. Rule #34: no manual SQL concat of user input
 * (`?` placeholder for the timestamp).
 */

namespace IPS\gddeals\tasks;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ExpireCoupons extends \IPS\Task
{
	public function execute(): mixed
	{
		$enabled = (int) ( \IPS\Settings::i()->gddeals_coupon_auto_expire ?? 1 );
		if ( !$enabled )
		{
			return 'gddeals_coupon_auto_expire=0 — skipping';
		}

		$now      = time();
		$affected = 0;

		try
		{
			/* Count first so we can log a meaningful message without
			   depending on \IPS\Db driver-specific affected_rows APIs. */
			$affected = (int) \IPS\Db::i()->select(
				'COUNT(*)', 'gd_deal_posts',
				[ "post_type='coupon' AND ( expired=0 OR expired IS NULL ) AND expires_at IS NOT NULL AND expires_at > 0 AND expires_at < ?", $now ]
			)->first();

			if ( $affected > 0 )
			{
				\IPS\Db::i()->update(
					'gd_deal_posts',
					[ 'expired' => 1, 'updated' => $now ],
					[ "post_type='coupon' AND ( expired=0 OR expired IS NULL ) AND expires_at IS NOT NULL AND expires_at > 0 AND expires_at < ?", $now ]
				);
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gddeals ExpireCoupons: ' . $e->getMessage(), 'gddeals' ); } catch ( \Throwable ) {}
			return 'gddeals ExpireCoupons FAILED: ' . $e->getMessage();
		}

		return $affected > 0 ? 'Expired ' . $affected . ' coupon(s)' : NULL;
	}
}
class ExpireCoupons extends _ExpireCoupons {}
