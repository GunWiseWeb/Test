<?php

namespace IPS\gdloadout\Loadout;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Loadout
{
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
