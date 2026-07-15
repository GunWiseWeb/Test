<?php

namespace IPS\gdloadout\Loadout;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Loadout
{
	/**
	 * v1.0.74 — Cascade-delete a loadout AND every child row across
	 * all tables that reference it. Single source of truth for
	 * loadout deletion so the frontend delete(), the new ACP
	 * delete, and the member-delete hook cannot drift out of sync
	 * again (frontend was missing gd_loadout_comments and
	 * gd_loadout_suggestions, leaving orphaned child rows).
	 *
	 * Per-table try/catch so a missing table on a partial upgrade
	 * (rare) or a locked row cannot abort the cascade — every
	 * table gets its chance. Never throws.
	 *
	 * @param int $loadoutId  the gd_loadouts.id to purge
	 */
	public static function deleteCascade( int $loadoutId ): void
	{
		if ( $loadoutId <= 0 ) { return; }

		$childTables = [
			'gd_loadout_items',
			'gd_loadout_votes',
			'gd_loadout_comments',
			'gd_loadout_follows',
			'gd_loadout_suggestions',
			'gd_loadout_forum_posts',
		];
		foreach ( $childTables as $t )
		{
			try
			{
				\IPS\Db::i()->delete( $t, [ 'loadout_id=?', $loadoutId ] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'Loadout::deleteCascade ' . $t . ' id=' . $loadoutId . ': ' . $e->getMessage(), 'gdloadout' ); } catch ( \Throwable ) {}
			}
		}

		try
		{
			\IPS\Db::i()->delete( 'gd_loadouts', [ 'id=?', $loadoutId ] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Loadout::deleteCascade gd_loadouts id=' . $loadoutId . ': ' . $e->getMessage(), 'gdloadout' ); } catch ( \Throwable ) {}
		}
	}

	/**
	 * v1.0.74 — Cascade-delete every loadout owned by a member.
	 * Called from the MemberSync onDelete hook so a deleted user
	 * doesn't leave orphaned loadouts behind. Guarded so any
	 * failure logs but never blocks the member-deletion flow.
	 *
	 * @param int $memberId  the deleted member's id
	 */
	public static function deleteAllForMember( int $memberId ): void
	{
		if ( $memberId <= 0 ) { return; }
		try
		{
			foreach ( \IPS\Db::i()->select( 'id', 'gd_loadouts', [ 'member_id=?', $memberId ] ) as $row )
			{
				$lid = (int) ( is_array( $row ) ? ( $row['id'] ?? 0 ) : $row );
				if ( $lid > 0 ) { self::deleteCascade( $lid ); }
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Loadout::deleteAllForMember member=' . $memberId . ': ' . $e->getMessage(), 'gdloadout' ); } catch ( \Throwable ) {}
		}
	}

	public static function slugify( string $name ): string
	{
		$slug = mb_strtolower( trim( $name ) );
		$slug = preg_replace( '/[^a-z0-9\s\-]/', '', $slug );
		$slug = preg_replace( '/[\s\-]+/', '-', $slug );
		$slug = trim( $slug, '-' );
		return $slug ?: 'loadout';
	}

	public static function canSuggest( \IPS\Member $member, array $loadout ): bool
	{
		if ( !$member->member_id ) return false;
		if ( (int) $loadout['member_id'] === (int) $member->member_id ) return false;
		if ( !\in_array( $loadout['visibility'] ?? '', [ 'public', 'unlisted' ], true ) ) return false;

		$mode = 'anyone';
		try { $mode = (string) ( \IPS\Settings::i()->gdloadout_suggest_mode ?: 'anyone' ); } catch ( \Throwable ) {}

		switch ( $mode )
		{
			case 'group':
				$groups = [];
				try { $groups = array_filter( array_map( 'intval', explode( ',', (string) \IPS\Settings::i()->gdloadout_suggest_groups ) ) ); } catch ( \Throwable ) {}
				if ( !$groups ) return true;
				return $member->inGroup( $groups );

			case 'threshold':
				$minPosts = 0;
				$minRep   = 0;
				try { $minPosts = (int) \IPS\Settings::i()->gdloadout_suggest_min_posts; } catch ( \Throwable ) {}
				try { $minRep   = (int) \IPS\Settings::i()->gdloadout_suggest_min_rep; } catch ( \Throwable ) {}
				$okPosts = (int) $member->member_posts >= $minPosts;
				$rep = isset( $member->pp_reputation_points ) ? (int) $member->pp_reputation_points : 0;
				$okRep = $rep >= $minRep;
				return $okPosts && $okRep;

			case 'owner_toggle':
				return (int) ( $loadout['suggestions_open'] ?? 1 ) === 1;

			default:
				return true;
		}
	}
}

class Loadout extends _Loadout {}
