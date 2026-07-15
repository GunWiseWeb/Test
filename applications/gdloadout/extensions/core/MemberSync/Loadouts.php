<?php
/**
 * @brief  GD Loadout — MemberSync onDelete hook
 *
 * When a member is deleted, cascade-delete every loadout they own
 * (and every child row across items / votes / comments / follows /
 * suggestions / forum_posts) so no orphaned rows are left behind.
 * Every operation is guarded — a failure here MUST NOT block the
 * member-deletion flow (that would trap admins).
 *
 * v1.0.74 initial cut. Registered in data/extensions.json under
 * core.MemberSync.Loadouts.
 */

namespace IPS\gdloadout\extensions\core\MemberSync;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Loadouts
{
	/**
	 * Called when a member is deleted. Purge all of their loadouts
	 * plus every child row via the shared cascade helper.
	 *
	 * @param \IPS\Member $member  the member being deleted
	 */
	public function onDelete( \IPS\Member $member ): void
	{
		$mid = (int) $member->member_id;
		if ( $mid <= 0 ) { return; }
		try
		{
			\IPS\gdloadout\Loadout\Loadout::deleteAllForMember( $mid );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdloadout MemberSync::onDelete member=' . $mid . ': ' . $e->getMessage(), 'gdloadout' ); } catch ( \Throwable ) {}
		}
	}

	/* Other MemberSync hooks (onCreateAccount, onProfileUpdate,
	   onValidate, onUnregister, etc.) are unused by this app —
	   no-op stubs so the extension can be safely instantiated
	   by IPS with the full interface it expects. */

	public function onCreateAccount( \IPS\Member $member ): void {}
	public function onProfileUpdate( \IPS\Member $member, array $changes ): void {}
	public function onSetAsSpammer( \IPS\Member $member ): void {}
	public function onUnSetAsSpammer( \IPS\Member $member ): void {}
	public function onMerge( \IPS\Member $member, \IPS\Member $otherMember ): void {}
	public function onValidate( \IPS\Member $member ): void {}
	public function onLogin( \IPS\Member $member ): void {}
	public function onLogout( \IPS\Member $member ): void {}
}

class Loadouts extends _Loadouts {}
