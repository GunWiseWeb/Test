<?php

namespace IPS\gdloadout\extensions\core\Sitemap;

use IPS\Db;
use IPS\Http\Url;
use IPS\Member;
use IPS\Sitemap;
use function count;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Loadouts
{
	protected const MAX_PER_FILE = 500;

	public function settings(): array
	{
		return [];
	}

	public function saveSettings( array $values ): void
	{
	}

	public function getFilenames(): array
	{
		try
		{
			$count = (int) Db::i()->select( 'COUNT(*)', 'gd_loadouts', [ 'visibility=?', 'public' ] )->first();
		}
		catch ( \Throwable )
		{
			return [];
		}

		if ( $count < 1 )
		{
			return [];
		}

		$files  = [];
		$blocks = (int) ceil( $count / static::MAX_PER_FILE );
		for ( $i = 1; $i <= $blocks; $i++ )
		{
			$files[] = 'sitemap_gdloadout_loadouts_' . $i;
		}

		return $files;
	}

	public function generateSitemap( string $filename, Sitemap $sitemap ): ?int
	{
		$entries = [];
		$lastId  = 0;

		$exploded = explode( '_', $filename );
		$block    = (int) array_pop( $exploded );
		$offset   = ( $block - 1 ) * static::MAX_PER_FILE;

		$where = [ [ 'visibility=?', 'public' ] ];

		try
		{
			$prevFile = implode( '_', $exploded ) . '_' . ( $block - 1 );
			$prevLastId = (int) Db::i()->select( 'last_id', 'core_sitemap', [ [ 'sitemap=?', $prevFile ] ] )->first();
			if ( $prevLastId > 0 )
			{
				$where[] = [ 'id > ?', $prevLastId ];
				$offset  = 0;
			}
		}
		catch ( \Throwable ) {}

		try
		{
			foreach ( Db::i()->select( '*', 'gd_loadouts', $where, 'id ASC', [ $offset, static::MAX_PER_FILE ] ) as $row )
			{
				$owner = Member::load( (int) $row['member_id'] );
				if ( !$owner->member_id )
				{
					continue;
				}

				$ownerName = $owner->name ?? 'Unknown';
				$url = (string) Url::internal(
					'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( (string) ( $row['slug'] ?? '' ) ),
					'front',
					'gdloadout_view'
				);

				$lastMod = (int) ( $row['updated_at'] ?? $row['created_at'] ?? 0 );

				$entry = [
					'url'      => $url,
					'priority' => 0.6,
				];

				if ( $lastMod > 0 )
				{
					$entry['lastmod'] = $lastMod;
				}

				$entries[] = $entry;
				$lastId    = (int) $row['id'];
			}
		}
		catch ( \Throwable ) {}

		if ( count( $entries ) > 0 )
		{
			$sitemap->buildSitemapFile( $filename, $entries, $lastId );
		}

		return $lastId;
	}
}

class Loadouts extends _Loadouts {}
