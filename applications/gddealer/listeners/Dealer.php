<?php
/**
 * @brief    GD Dealer — Member Listener: auto-sync subscription_tier from IPS groups
 * @package  GD Dealer Manager
 * @since    14 Jun 2026
 */
namespace IPS\gddealer\listeners;

use IPS\Events\ListenerType\MemberListenerType;
use IPS\Member;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class Dealer extends MemberListenerType
{
    /**
     * Member profile updated — if their groups changed, re-sync the dealer tier.
     *
     * @param Member $member  The member
     * @param array  $changes Changed fields
     * @return void
     */
    public function onProfileUpdate( Member $member, array $changes ) : void
    {
        if ( !isset( $changes['member_group_id'] ) && !isset( $changes['mgroup_others'] ) )
        {
            return;
        }
        try
        {
            \IPS\gddealer\Dealer\Dealer::syncTierFromGroups( $member );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( $e, 'gddealer_tier_sync' ); } catch ( \Throwable $e2 ) {}
        }
    }

    /**
     * New account created — sync tier in case created directly into a tier group.
     *
     * @param Member $member  The member
     * @return void
     */
    public function onCreateAccount( Member $member ) : void
    {
        try
        {
            \IPS\gddealer\Dealer\Dealer::syncTierFromGroups( $member );
        }
        catch ( \Throwable $e )
        {
            try { \IPS\Log::log( $e, 'gddealer_tier_sync' ); } catch ( \Throwable $e2 ) {}
        }
    }
}
