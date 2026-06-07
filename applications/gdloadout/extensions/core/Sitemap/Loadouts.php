<?php

namespace IPS\gdloadout\extensions\core\Sitemap;

use IPS\Db;
use IPS\Http\Url;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class Loadouts
{
	public function settings(): array
	{
		return [];
	}

	public function getFilenames(): array
	{
		return [ 'sitemap_loadouts_1' ];
	}

	public function generateSitemap( string $filename, \IPS\Sitemap $sitemap ): ?int
	{
		$entries = [];

		try
		{
			$prefix = Db::i()->prefix;
			$stmt   = Db::i()->preparedQuery(
				"SELECT l.slug, l.updated_at, l.created_at, l.member_id, m.name AS member_name
				 FROM `{$prefix}gd_loadouts` l
				 LEFT JOIN `{$prefix}core_members` m ON l.member_id = m.member_id
				 WHERE l.visibility = 'public'
				 ORDER BY COALESCE(l.updated_at, l.created_at) DESC
				 LIMIT 5000",
				[]
			);

			while ( $row = $stmt->fetch_assoc() )
			{
				$username  = $row['member_name'] ?? 'user';
				$url       = Url::internal(
					'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $username ) . '&slug=' . urlencode( $row['slug'] ),
					'front',
					'gdloadout_view'
				);

				$lastMod   = $row['updated_at'] ?: $row['created_at'];
				$lastModTs = $lastMod ? strtotime( $lastMod ) : null;

				$entries[] = [
					'url'      => $url,
					'lastmod'  => $lastModTs ? \IPS\DateTime::ts( $lastModTs ) : null,
					'priority' => 0.6,
				];
			}
		}
		catch ( \Throwable ) {}

		if ( $entries )
		{
			$sitemap->addToSitemap( $filename, $entries );
		}

		return null;
	}
}
