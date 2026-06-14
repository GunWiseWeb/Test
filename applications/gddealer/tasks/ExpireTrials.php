<?php
/**
 * @brief       GD Dealer Manager — Expire Trials Scheduled Task
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       16 Apr 2026
 *
 * Runs daily (P1D). Two passes:
 *   1. Expire dealers whose trial has ended. Founding members keep their
 *      badge (group 7) but listings are suspended + feed deactivated.
 *      Non-founders get fully stripped (groups removed, primary reset).
 *   2. Send 30/7/1-day reminder emails (exact-day match = natural dedup).
 */

namespace IPS\gddealer\tasks;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ExpireTrials extends \IPS\Task
{
	/**
	 * @return string|null
	 */
	public function execute(): mixed
	{
		$expired  = 0;
		$warnings = 0;

		$subscribeUrl = (string) ( \IPS\Settings::i()->gddealer_subscribe_url ?: 'https://gunrack.deals/dealers/join' );
		$contactEmail = (string) ( \IPS\Settings::i()->gddealer_help_contact ?: 'dealers@gunrack.deals' );

		/* ---- Pass 1: Expire dealers whose trial ended ---- */
		try
		{
			$rows = \IPS\Db::i()->select(
				'*', 'gd_dealer_feed_config',
				[ 'trial_expires_at IS NOT NULL AND trial_expires_at <= NOW() AND active=?', 1 ]
			);
		}
		catch ( \Throwable )
		{
			$rows = [];
		}

		$allDealerGroupIds = \IPS\gddealer\Dealer\Dealer::allDealerGroupIds();
		$foundingGroupId   = (int) ( \IPS\Settings::i()->gddealer_group_founding ?? 0 );

		foreach ( $rows as $row )
		{
			$dealerId = (int) $row['dealer_id'];

			$isFounder = false;
			try { $isFounder = \IPS\gddealer\Dealer\Dealer::load( $dealerId )->isFoundingMember(); } catch ( \Throwable ) {}

			/* Suspend all listings (everyone — founders too) */
			try
			{
				\IPS\Db::i()->update(
					'gd_dealer_listings',
					[ 'listing_status' => 'suspended' ],
					[ 'dealer_id=? AND listing_status<>?', $dealerId, 'suspended' ]
				);
			}
			catch ( \Throwable ) {}

			/* Deactivate feed config (everyone) */
			try
			{
				\IPS\Db::i()->update(
					'gd_dealer_feed_config',
					[ 'active' => 0, 'suspended' => 1 ],
					[ 'dealer_id=?', $dealerId ]
				);
			}
			catch ( \Throwable ) {}

			/* Group strip + primary reset — NON-founders only */
			if ( !$isFounder )
			{
				try
				{
					$member = \IPS\Member::load( $dealerId );
					if ( $member->member_id && !empty( $allDealerGroupIds ) )
					{
						$others = $member->mgroup_others
							? array_filter( array_map( 'intval', explode( ',', (string) $member->mgroup_others ) ) )
							: [];
						$others = array_values( array_diff( $others, $allDealerGroupIds ) );
						$member->mgroup_others = implode( ',', $others );

						if ( in_array( (int) $member->member_group_id, $allDealerGroupIds, true ) )
						{
							$member->member_group_id = 3;
						}

						$member->save();
					}
				}
				catch ( \Throwable ) {}
			}

			/* Email — founder-specific or generic */
			try
			{
				$member = \IPS\Member::load( $dealerId );
				if ( $member->member_id )
				{
					$tpl = $isFounder ? 'foundingTrialEnded' : 'trialExpired';
					\IPS\Email::buildFromTemplate( 'gddealer', $tpl, [
						'name'          => $member->name,
						'subscribe_url' => $subscribeUrl,
						'contact_email' => $contactEmail,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $member );
				}
			}
			catch ( \Throwable ) {}

			$expired++;
		}

		/* ---- Pass 2: Send 30/7/1-day reminders ---- */
		foreach ( [ 30 => 'trialReminder30', 7 => 'trialReminder7', 1 => 'trialReminder1' ] as $days => $tpl )
		{
			try
			{
				$reminderRows = \IPS\Db::i()->select(
					'*', 'gd_dealer_feed_config',
					[ 'DATE(trial_expires_at) = DATE(DATE_ADD(NOW(), INTERVAL ? DAY)) AND active=?', $days, 1 ]
				);
			}
			catch ( \Throwable )
			{
				$reminderRows = [];
			}

			foreach ( $reminderRows as $row )
			{
				$member = null;
				try { $member = \IPS\Member::load( (int) $row['dealer_id'] ); } catch ( \Throwable ) {}
				if ( !$member || !$member->member_id ) { continue; }

				$expiryDate = date( 'F j, Y', strtotime( (string) $row['trial_expires_at'] ) );

				/* Email — own try/catch (rule #25) */
				try
				{
					\IPS\Email::buildFromTemplate( 'gddealer', $tpl, [
						'name'          => $member->name,
						'days'          => (string) $days,
						'days_left'     => (string) $days,
						'expiry_date'   => $expiryDate,
						'subscribe_url' => $subscribeUrl,
						'contact_email' => $contactEmail,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $member );
				}
				catch ( \Throwable ) {}

				$warnings++;
			}
		}

		if ( $expired > 0 || $warnings > 0 )
		{
			return "Expired {$expired} trial(s), sent {$warnings} reminder(s)";
		}

		return null;
	}
}

class ExpireTrials extends _ExpireTrials {}
