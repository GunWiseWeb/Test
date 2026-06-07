<?php

namespace IPS\gdloadout\widgets;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class trendingLoadouts extends \IPS\Widget
{
	public string $key = 'trendingLoadouts';
	public string $app = 'gdloadout';

	public function render(): string
	{
		$loadouts = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_loadouts', [ 'visibility=?', 'public' ], 'upvotes DESC, view_count DESC', 4 ) as $row )
			{
				$ownerName = 'Unknown';
				try { $ownerName = \IPS\Member::load( (int) $row['member_id'] )->name; } catch ( \Throwable ) {}
				$row['owner_name'] = $ownerName;
				$row['view_url']   = (string) \IPS\Http\Url::internal(
					'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $row['slug'] ),
					'front',
					'gdloadout_view'
				);
				$loadouts[] = $row;
			}
		}
		catch ( \Throwable ) {}

		return \IPS\Theme::i()->getTemplate( 'widgets', 'gdloadout', 'front' )->trendingLoadouts( $loadouts );
	}
}
