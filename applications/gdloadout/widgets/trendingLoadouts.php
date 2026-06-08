<?php

namespace IPS\gdloadout\widgets;

use IPS\Db;
use IPS\Http\Url;
use IPS\Member;
use IPS\Theme;
use IPS\Widget;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _trendingLoadouts extends Widget
{
	public string $key = 'trendingLoadouts';
	public string $app = 'gdloadout';

	public function render(): string
	{
		$loadouts = [];
		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadouts', [ 'visibility=?', 'public' ], 'upvotes DESC, view_count DESC', [ 0, 4 ] ) as $row )
			{
				$ownerName = 'Unknown';
				try { $ownerName = Member::load( (int) $row['member_id'] )->name; } catch ( \Throwable ) {}
				$row['owner_name'] = $ownerName;
				$row['view_url'] = (string) Url::internal(
					'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $row['slug'] ),
					'front',
					'gdloadout_view'
				);
				$loadouts[] = $row;
			}
		}
		catch ( \Throwable ) {}

		return Theme::i()->getTemplate( 'widgets', 'gdloadout', 'front' )->trendingLoadouts( $loadouts );
	}
}

class trendingLoadouts extends _trendingLoadouts {}
