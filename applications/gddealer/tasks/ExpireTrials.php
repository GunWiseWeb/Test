<?php
/**
 * @brief       GD Dealer Manager — Expire Trials Scheduled Task
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       16 Apr 2026
 *
 * Runs daily (P1D). Two passes:
 *   1. Suspend dealers whose trial has ended (trial_expires_at <= NOW()
 *      AND active = 1). Sets listings to suspended, deactivates the feed
 *      config, removes the member from the Dealers group, sends
 *      trialExpired email.
 *   2. Send a 7-day warning email to dealers whose trial expires in
 *      exactly 7 days.
 *
 * Every DB operation is wrapped in its own try/catch so one bad row
 * cannot stop the whole task.
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

		/* ---- Pass 1: Expire dealers whose trial ended ---- */
		try
		{
			$rows = \IPS\Db::i()->select(
				'*', 'gd_dealer_feed_config',
				[ 'trial_expires_at IS NOT NULL AND trial_expires_at <= NOW() AND active=?', 1 ]
			);
		}
		catch ( \Exception )
		{
			$rows = [];
		}

		$allDealerGroupIds = \IPS\gddealer\Dealer\Dealer::allDealerGroupIds();
		$subscribeUrl      = (string) ( \IPS\Settings::i()->gddealer_subscribe_url ?: 'https://gunrack.deals/dealers/join' );
		$contactEmail      = (string) ( \IPS\Settings::i()->gddealer_help_contact ?: 'dealers@gunrack.deals' );

		foreach ( $rows as $row )
		{
			$dealerId = (int) $row['dealer_id'];

			/* Suspend all listings */
			try
			{
				\IPS\Db::i()->update(
					'gd_dealer_listings',
					[ 'listing_status' => 'suspended' ],
					[ 'dealer_id=? AND listing_status<>?', $dealerId, 'suspended' ]
				);
			}
			catch ( \Exception ) {}

			/* Deactivate feed config */
			try
			{
				\IPS\Db::i()->update(
					'gd_dealer_feed_config',
					[ 'active' => 0, 'suspended' => 1 ],
					[ 'dealer_id=?', $dealerId ]
				);
			}
			catch ( \Exception ) {}

			/* Remove from all Dealer groups */
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
			catch ( \Exception ) {}

			/* Send trialExpired email */
			try
			{
				$member = \IPS\Member::load( $dealerId );
				if ( $member->member_id )
				{
					\IPS\Email::buildFromTemplate( 'gddealer', 'trialExpired', [
						'name'          => $member->name,
						'subscribe_url' => $subscribeUrl,
						'contact_email' => $contactEmail,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $member );
				}
			}
			catch ( \Exception ) {}

			$expired++;
		}

		/* ---- Pass 2: Send 7-day warning ---- */
		try
		{
			$warningRows = \IPS\Db::i()->select(
				'*', 'gd_dealer_feed_config',
				[ 'DATE(trial_expires_at) = DATE(DATE_ADD(NOW(), INTERVAL 7 DAY)) AND active=?', 1 ]
			);
		}
		catch ( \Exception )
		{
			$warningRows = [];
		}

		foreach ( $warningRows as $row )
		{
			try
			{
				$member = \IPS\Member::load( (int) $row['dealer_id'] );
				if ( $member->member_id )
				{
					$expiryDate = date( 'F j, Y', strtotime( (string) $row['trial_expires_at'] ) );

					\IPS\Email::buildFromTemplate( 'gddealer', 'trialExpiringSoon', [
						'name'          => $member->name,
						'days_left'     => '7',
						'expiry_date'   => $expiryDate,
						'subscribe_url' => $subscribeUrl,
						'contact_email' => $contactEmail,
					], \IPS\Email::TYPE_TRANSACTIONAL )->send( $member );
				}
			}
			catch ( \Exception ) {}

			$warnings++;
		}

		if ( $expired > 0 || $warnings > 0 )
		{
			return "Expired {$expired} trial(s), sent {$warnings} 7-day warning(s)";
		}

		return null;
	}
}

class ExpireTrials extends _ExpireTrials {}
