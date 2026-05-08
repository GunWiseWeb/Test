<?php
namespace IPS\gddealer\setup\upg_10172;

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
		/* v1.0.172 - permanent founding membership flag.
		 *
		 * Founding membership becomes a permanent attribute (separate from
		 * subscription_tier) marked by is_founding_member=1. Once founding,
		 * always founding. The subscription_tier column continues to track
		 * what the dealer is currently paying for; is_founding_member tracks
		 * permanent identity.
		 *
		 * Existing dealers with subscription_tier='founding' get auto-migrated
		 * to is_founding_member=1. Their tier is preserved (still 'founding'
		 * until they downgrade/convert).
		 *
		 * ExpireTrials still fires on founding dealers as normal - the trial
		 * expiry mechanism is unchanged. The is_founding_member flag persists
		 * across trial expiry, so when they re-subscribe to a paid tier (whether
		 * founding-exclusive packages from IPS Commerce or normal Pro/Enterprise),
		 * the founding sync time and Founder badge restore automatically.
		 */

		/* Step 1a: Add is_founding_member column. Use addColumn() pattern that
		 * tolerates the column already existing (idempotent re-run). */
		try
		{
			\IPS\Db::i()->addColumn( 'gd_dealer_feed_config', [
				'name'       => 'is_founding_member',
				'type'       => 'TINYINT',
				'length'     => 1,
				'allow_null' => false,
				'default'    => '0',
				'comment'    => 'Permanent founding member status. Set on founding signup, never cleared. Separate from subscription_tier (which tracks what they currently pay for). Founding members get faster sync time (1hr) regardless of tier and a Founder badge.',
			] );
		}
		catch ( \Throwable $e )
		{
			/* Column already exists is fine - idempotent re-run */
			$msg = $e->getMessage();
			if ( !str_contains( $msg, 'Duplicate' ) && !str_contains( $msg, 'already exists' ) )
			{
				try { \IPS\Log::log( 'v1.0.172 addColumn failed: ' . $msg, 'gddealer_upg_10172' ); } catch ( \Throwable ) {}
				return FALSE;
			}
		}

		/* Step 1b: Audit log - record which dealers we're about to migrate.
		 * Defensive: if the column doesn't exist yet (rare race), the WHERE
		 * filter on subscription_tier='founding' still works, the audit just
		 * captures what's about to change. */
		try
		{
			$toMigrate = iterator_to_array( \IPS\Db::i()->select(
				'dealer_id, dealer_name, dealer_slug, subscription_tier, trial_expires_at',
				'gd_dealer_feed_config',
				[ "subscription_tier=? AND is_founding_member=?", 'founding', 0 ]
			) );

			foreach ( $toMigrate as $dealer )
			{
				$auditLine = sprintf(
					'v1.0.172 migrating dealer to is_founding_member=1: dealer_id=%d, name=%s, slug=%s, tier=%s, trial_expires_at=%s',
					(int) $dealer['dealer_id'],
					(string) $dealer['dealer_name'],
					(string) $dealer['dealer_slug'],
					(string) $dealer['subscription_tier'],
					(string) ( $dealer['trial_expires_at'] ?? 'NULL' )
				);
				try { \IPS\Log::log( $auditLine, 'gddealer_upg_10172_migration' ); } catch ( \Throwable ) {}
			}

			$count = count( $toMigrate );
			try { \IPS\Log::log( "v1.0.172 migration: {$count} founding-tier dealer(s) found for is_founding_member=1 backfill", 'gddealer_upg_10172' ); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			/* Audit query failed - non-fatal. Migration still proceeds. */
			try { \IPS\Log::log( 'v1.0.172 pre-migration audit query failed: ' . $e->getMessage(), 'gddealer_upg_10172' ); } catch ( \Throwable ) {}
		}

		/* Step 1c: The actual migration. */
		try
		{
			\IPS\Db::i()->update(
				'gd_dealer_feed_config',
				[ 'is_founding_member' => 1 ],
				[ "subscription_tier=? AND is_founding_member=?", 'founding', 0 ]
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'v1.0.172 migration UPDATE failed: ' . $e->getMessage(), 'gddealer_upg_10172' ); } catch ( \Throwable ) {}
			return FALSE;
		}

		/* Cache invalidation */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'v1.0.172 - add is_founding_member column + auto-migrate existing founding dealers';
	}
}

class upgrade extends _upgrade {}
