<?php
namespace IPS\gdcatalog\setup\upg_10060;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		$catMapping = json_encode([
			5 => 23, 6 => 23, 26 => 2, 64 => 3, 96 => 4,
			40 => 8, 58 => 8, 94 => 15,
			48 => 16, 59 => 16,
			49 => 23, 70 => 23, 71 => 23, 72 => 23, 86 => 23,
			90 => 23, 91 => 23, 36 => 23, 12 => 23, 4 => 23,
			18 => 38,
			9 => 44, 10 => 44, 32 => 44, 39 => 44, 41 => 44,
			46 => 44, 50 => 44, 52 => 44, 55 => 44, 78 => 44,
			79 => 44, 80 => 44,
			8 => 58, 23 => 58, 37 => 58, 63 => 58, 66 => 58,
			67 => 58, 74 => 58, 77 => 58, 81 => 58,
			28 => 72, 84 => 72,
			15 => 83, 44 => 83,
			17 => 94, 87 => 94,
			31 => 103, 51 => 103, 53 => 103, 75 => 103,
			83 => 103, 25 => 103,
			7 => 114, 19 => 114, 21 => 114, 22 => 114,
			27 => 114, 45 => 114,
			93 => 31,
			20 => 130, 62 => 130,
			1 => 58, 2 => 58, 13 => 58,
		]);

		try
		{
			\IPS\Db::i()->update(
				'gd_distributor_feeds',
				[ 'category_mapping' => $catMapping ],
				[ 'distributor=?', 'sports_south' ]
			);
		}
		catch ( \Throwable ) {}

		return TRUE;
	}

	public function step2(): bool
	{
		$catMapping = [
			5 => 23, 6 => 23, 26 => 2, 64 => 3, 96 => 4,
			40 => 8, 58 => 8, 94 => 15,
			48 => 16, 59 => 16,
			49 => 23, 70 => 23, 71 => 23, 72 => 23, 86 => 23,
			90 => 23, 91 => 23, 36 => 23, 12 => 23, 4 => 23,
			18 => 38,
			9 => 44, 10 => 44, 32 => 44, 39 => 44, 41 => 44,
			46 => 44, 50 => 44, 52 => 44, 55 => 44, 78 => 44,
			79 => 44, 80 => 44,
			8 => 58, 23 => 58, 37 => 58, 63 => 58, 66 => 58,
			67 => 58, 74 => 58, 77 => 58, 81 => 58,
			28 => 72, 84 => 72,
			15 => 83, 44 => 83,
			17 => 94, 87 => 94,
			31 => 103, 51 => 103, 53 => 103, 75 => 103,
			83 => 103, 25 => 103,
			7 => 114, 19 => 114, 21 => 114, 22 => 114,
			27 => 114, 45 => 114,
			93 => 31,
			20 => 130, 62 => 130,
			1 => 58, 2 => 58, 13 => 58,
		];

		foreach ( $catMapping as $ssCatId => $canonicalId )
		{
			try
			{
				\IPS\Db::i()->update(
					'gd_catalog',
					[ 'category_id' => $canonicalId ],
					[ 'category_id=? AND primary_source=?', $ssCatId, 'sports_south' ]
				);
			}
			catch ( \Throwable ) {}
		}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
