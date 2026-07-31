<?php
/**
 * @brief  GD Deals — upgrade 1.0.55
 *
 * Rule #79 — exactly ONE upg_* dir per app. Self-contained.
 * Rule #27 — dual class wrapper, guard header.
 *
 * WHAT SHIPS IN 1.0.55 — Coupons ACP management + auto-expire.
 *
 *   Widget fix (URGENT, customer-facing):
 *     widgets/gdRecentCoupons.php now filters expired coupons at
 *     query time (expired flag + expires_at date), matching the
 *     pattern already used by modules/front/deals/browse.php.
 *
 *   ACP additions:
 *     * NEW modules/admin/deals/coupons.php — Table\Db over
 *       gd_deal_posts WHERE post_type='coupon', with Active/
 *       Pending/Expired/All status tabs, edit-in-place via
 *       delete + a manual expired-toggle button that uses the
 *       EXISTING manually_expired_by/manually_expired_at columns
 *       (no schema change).
 *     * Registered in data/acpmenu.json under the "deals" tab,
 *       restriction 'settings_manage'.
 *     * modules/admin/deals/settings.php gains a "Coupons" header
 *       with gddeals_coupon_auto_expire (default ON). Also
 *       corrects the stale ApprovalPageSize hook comment (that
 *       hook was deleted in v1.0.53 — setting is now read via
 *       DIRECT EDIT to core Unapproved.php).
 *
 *   Background task:
 *     * NEW tasks/ExpireCoupons.php + data/tasks.json entry
 *       (P15M). SELECT-then-UPDATE (avoids Db driver-specific
 *       affected_rows). Gated by gddeals_coupon_auto_expire.
 *
 *   Upgrade responsibilities (this file):
 *     1. Seed setting default (gddeals_coupon_auto_expire=1).
 *     2. Re-seed all new lang keys across every lang_id
 *        (Rule #43/#44 — 6-col core_sys_lang_words, per-row
 *        try/catch).
 *     3. One-time backfill: flip expired=1 on any coupon whose
 *        expires_at is already in the past — so Derrick doesn't
 *        need to wait 15 min for the task to catch up on
 *        existing rows.
 *     4. Clear caches / opcache so the new module + task + acp
 *        menu entry load on the next request.
 *
 * NO schema change (all needed columns already exist).
 * NO CanonicalTemplates::ensure() call.
 * Rule #79: upg_10054 removed, exactly one upg dir per app.
 */

namespace IPS\gddeals\setup\upg_10055;

use function defined;
use function function_exists;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$now = time();

		/* 1. Seed the auto-expire setting default. */
		try
		{
			\IPS\Db::i()->replace( 'core_sys_conf_settings', [
				'conf_key'     => 'gddeals_coupon_auto_expire',
				'conf_value'   => '1',
				'conf_default' => '1',
				'conf_app'     => 'gddeals',
				'conf_report'  => 'full',
			] );
		}
		catch ( \Throwable $e )
		{
			try
			{
				\IPS\Db::i()->replace( 'core_sys_conf_settings', [
					'conf_key'   => 'gddeals_coupon_auto_expire',
					'conf_value' => '1',
				] );
			}
			catch ( \Throwable $e2 ) { try { \IPS\Log::log( 'gddeals upg_10055 setting seed: ' . $e2->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }
		}

		/* 2. Re-seed lang strings across every lang_id (Rule #43/#44 —
		     6-column core_sys_lang_words shape, per-row try/catch). */
		$strings = [
			'menu__gddeals_deals_coupons'     => 'Coupons',
			'gddeals_settings_coupons'        => 'Coupons',
			'gddeals_coupon_auto_expire'      => 'Auto-expire coupons past their end date',
			'gddeals_coupon_auto_expire_desc' => 'When ON, a background task (runs every 15 min) flips <code>expired=1</code> on any coupon whose <code>expires_at</code> is in the past. Front-end widgets ALSO defensively check <code>expires_at</code> directly, so turning this OFF still hides date-expired coupons from customers &mdash; this toggle only disables the batch flag update, not query-time visibility.',
			'gddeals_cp_title'                => 'Title',
			'gddeals_cp_retailer_name'        => 'Retailer',
			'gddeals_cp_promo_code'           => 'Code',
			'gddeals_cp_discount_pct'         => 'Discount',
			'gddeals_cp_expires_at'           => 'Expires',
			'gddeals_cp_show_source'          => 'Source',
			'gddeals_cp_featured'             => 'Featured',
			'gddeals_cp_expire'               => 'Mark expired',
			'gddeals_cp_unexpire'             => 'Un-expire',
			'gddeals_cp_tab_active'           => 'Active',
			'gddeals_cp_tab_expired'          => 'Expired',
			'gddeals_cp_tab_pending'          => 'Pending',
			'gddeals_cp_tab_all'              => 'All',
			'task__gddeals_ExpireCoupons'     => 'Expire coupons past their end date',
		];
		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $strings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gddeals',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e ) { try { \IPS\Log::log( 'gddeals upg_10055 lang ' . $key . ': ' . $e->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }
				}
			}
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gddeals upg_10055 lang loop: ' . $e->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }

		/* 3. One-time backfill so existing already-past-expiry coupons
		     don't have to wait 15 min for the task to fire. Same
		     WHERE as the task's UPDATE. */
		try
		{
			\IPS\Db::i()->update(
				'gd_deal_posts',
				[ 'expired' => 1, 'updated' => $now ],
				[ "post_type='coupon' AND ( expired=0 OR expired IS NULL ) AND expires_at IS NOT NULL AND expires_at > 0 AND expires_at < ?", $now ]
			);
		}
		catch ( \Throwable $e ) { try { \IPS\Log::log( 'gddeals upg_10055 coupon backfill: ' . $e->getMessage(), 'gddeals' ); } catch ( \Throwable ) {} }

		/* 4. Cache / datastore clear so the new module, task, ACP
		     menu entry, and settings row load next request. */
		try { unset( \IPS\Data\Store::i()->modules_admin ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules_front ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); }       catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->extensions ); }         catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings ); }           catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpNotifications ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->acpMenuNumbers ); }     catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }                  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }                  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
