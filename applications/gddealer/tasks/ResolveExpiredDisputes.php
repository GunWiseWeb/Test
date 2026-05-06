<?php
/**
 * @brief       GD Dealer Manager — Resolve Expired Disputes Task
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       16 Apr 2026
 *
 * Runs daily (P1D). Finds every gd_dealer_ratings row where
 * dispute_status = 'pending_customer' and dispute_deadline has passed.
 * Each matching row is auto-resolved in the dealer's favor:
 *   - dispute_status      = 'resolved_dealer'
 *   - dispute_outcome     = 'auto_resolved'
 *   - dispute_resolved_at = NOW()
 * A notification email is sent to the customer.
 *
 * Every row is processed inside its own try/catch so one failure cannot
 * stop the whole task.
 */

namespace IPS\gddealer\tasks;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ResolveExpiredDisputes extends \IPS\Task
{
	/**
	 * @return string|null
	 */
	public function execute(): mixed
	{
		$resolved = 0;

		try
		{
			$rows = \IPS\Db::i()->select(
				'*', 'gd_dealer_ratings',
				[ 'dispute_status=? AND dispute_deadline IS NOT NULL AND dispute_deadline <= NOW()', 'pending_customer' ]
			);
		}
		catch ( \Exception )
		{
			return null;
		}

		$contactEmail = (string) ( \IPS\Settings::i()->gddealer_help_contact ?: 'dealers@gunrack.deals' );

		foreach ( $rows as $row )
		{
			try
			{
				\IPS\Db::i()->update( 'gd_dealer_ratings', [
					'dispute_status'      => 'resolved_dealer',
					'dispute_outcome'     => 'auto_resolved',
					'dispute_resolved_at' => date( 'Y-m-d H:i:s' ),
				], [ 'id=?', (int) $row['id'] ] );
			}
			catch ( \Exception )
			{
				continue;
			}

			$memberId = (int) ( $row['member_id'] ?? 0 );
			$dealerName = '';
			try
			{
				$dealerName = (string) \IPS\Db::i()->select( 'dealer_name', 'gd_dealer_feed_config', [ 'dealer_id=?', (int) $row['dealer_id'] ] )->first();
			}
			catch ( \Exception ) {}

			if ( $memberId > 0 )
			{
				try
				{
					$member = \IPS\Member::load( $memberId );
					if ( $member->member_id )
					{
						\IPS\Email::buildFromTemplate( 'gddealer', 'disputeAutoResolved', [
							'name'          => $member->name,
							'dealer_name'   => $dealerName,
							'contact_email' => $contactEmail,
						], \IPS\Email::TYPE_TRANSACTIONAL )->send( $member );
					}
				}
				catch ( \Exception ) {}
			}

			$resolved++;
		}

		if ( $resolved > 0 )
		{
			return "Auto-resolved {$resolved} expired dispute(s) in dealer's favor";
		}

		return null;
	}
}

class ResolveExpiredDisputes extends _ResolveExpiredDisputes {}
