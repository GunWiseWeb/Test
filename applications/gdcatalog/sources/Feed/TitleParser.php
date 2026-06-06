<?php
namespace IPS\gdcatalog\Feed;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class TitleParser
{
	const CALIBER_PATTERNS = [
		'/\b(\.50\s*BMG)\b/i',
		'/\b(\.338\s*Lapua(?:\s*Mag(?:num)?)?)\b/i',
		'/\b(\.300\s*(?:Win(?:chester)?\s*)?Mag(?:num)?)\b/i',
		'/\b(\.308\s*Win(?:chester)?)\b/i',
		'/\b(\.30-06(?:\s*Springfield)?)\b/i',
		'/\b(7\.62(?:\s*[xX\x{00d7}]\s*51(?:mm)?)?(?:\s*NATO)?)\b/iu',
		'/\b(7\.62(?:\s*[xX\x{00d7}]\s*39(?:mm)?)?)\b/iu',
		'/\b(6\.5(?:\s*Creedmoor)?)\b/i',
		'/\b(6mm\s*Creedmoor)\b/i',
		'/\b(\.223\s*Rem(?:ington)?)\b/i',
		'/\b(5\.56(?:\s*[xX\x{00d7}]\s*45(?:mm)?)?(?:\s*NATO)?)\b/iu',
		'/\b(\.22\s*LR)\b/i',
		'/\b(\.22\s*WMR)\b/i',
		'/\b(17\s*HMR)\b/i',
		'/\b(9\s*mm(?:\s*Luger)?|9mm\s*Luger)\b/i',
		'/\b(\.45\s*ACP)\b/i',
		'/\b(\.40\s*S(?:&|and)W)\b/i',
		'/\b(\.380\s*ACP)\b/i',
		'/\b(10\s*mm(?:\s*Auto)?)\b/i',
		'/\b(\.357\s*Mag(?:num)?)\b/i',
		'/\b(\.44\s*Mag(?:num)?)\b/i',
		'/\b(\.38\s*Spl?(?:ecial)?)\b/i',
		'/\b(\.45\s*Colt)\b/i',
		'/\b(\.410(?:\s*Bore)?)\b/i',
		'/\b(12\s*Gauge?)\b/i',
		'/\b(20\s*Gauge?)\b/i',
		'/\b(28\s*Gauge?)\b/i',
		'/\b(10\s*Gauge?)\b/i',
		'/\b(16\s*Gauge?)\b/i',
		'/\b(24\s*Gauge?)\b/i',
		'/\b(32\s*Gauge?)\b/i',
	];

	const ACTION_PATTERNS = [
		'/\bBolt[\s-]?Action\b/i'             => 'Bolt Action',
		'/\bSemi[\s-]?Auto(?:matic)?\b/i'     => 'Semi-Automatic',
		'/\bPump[\s-]?Action\b/i'             => 'Pump Action',
		'/\bLever[\s-]?Action\b/i'            => 'Lever Action',
		'/\bSingle[\s-]?Action\b/i'           => 'Single Action',
		'/\bDouble[\s-]?Action\b/i'           => 'Double Action',
		'/\bDA\/SA\b/i'                       => 'DA/SA',
		'/\bStrikerFire|Striker[\s-]Fire\b/i' => 'Striker Fire',
		'/\bBreak[\s-]?Action\b/i'            => 'Break Action',
		'/\bRevolver\b/i'                     => 'Revolver',
	];

	const CAPACITY_PATTERNS = [
		'/\b(\d+\+1)\b/'              => '$1',
		'/\b(\d+)\s*[Rr](?:oun)?ds?\b/' => '$1',
		'/\b(\d+)\s*[Rr][Dd]\b/'      => '$1',
		'/\b(\d+)\s*[Cc]ap(?:acity)?\b/i' => '$1',
	];

	public static function parse( string $title, string $description, int $categoryId, array $existing = [] ): array
	{
		$text    = $title . ' ' . $description;
		$results = [];

		if ( empty( $existing['caliber'] ) )
		{
			foreach ( self::CALIBER_PATTERNS as $pattern )
			{
				if ( preg_match( $pattern, $text, $m ) )
				{
					$results['caliber'] = $m[1];
					break;
				}
			}
		}

		if ( empty( $existing['action_type'] ) )
		{
			foreach ( self::ACTION_PATTERNS as $pattern => $value )
			{
				if ( preg_match( $pattern, $text ) )
				{
					$results['action_type'] = $value;
					break;
				}
			}
		}

		$firearmCats = [1, 2, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 31, 58, 64, 96];
		if ( empty( $existing['barrel_length'] ) && in_array( $categoryId, $firearmCats, true ) )
		{
			if ( preg_match( '/\b(\d{1,2}(?:\.\d{1,2})?)["\x{2033}]\s*(?:Bbl|BBL|[Bb]arrel|[Tt]hreaded)/u', $text, $m ) )
			{
				$results['barrel_length'] = $m[1] . '"';
			}
			elseif ( preg_match( '/(?:Bbl|BBL|[Bb]arrel)[:\s]+(\d{1,2}(?:\.\d{1,2})?)["\x{2033}]?/u', $text, $m ) )
			{
				$results['barrel_length'] = $m[1] . '"';
			}
		}

		if ( empty( $existing['capacity'] ) )
		{
			foreach ( self::CAPACITY_PATTERNS as $pattern => $replacement )
			{
				if ( preg_match( $pattern, $text, $m ) )
				{
					$results['capacity'] = $m[1];
					break;
				}
			}
		}

		if ( empty( $existing['gauge'] ) && in_array( $categoryId, [16, 17, 18, 19, 20, 21, 22], true ) )
		{
			if ( preg_match( '/\b(12|20|28|\.410)\s*[Gg]a(?:uge)?\b/', $text, $m ) )
			{
				$results['gauge'] = $m[1] . ' Gauge';
			}
		}

		$ammoCats = [23, 24, 25, 26, 27, 28, 29, 30];
		if ( in_array( $categoryId, $ammoCats, true ) )
		{
			if ( empty( $existing['grain'] ) )
			{
				$grain = self::grainFromTitle( $text );
				if ( $grain !== null )
				{
					$results['grain'] = $grain;
				}
			}

			if ( empty( $existing['case_type'] ) )
			{
				$casing = self::casingFromTitle( $text );
				if ( $casing !== null )
				{
					$results['case_type'] = $casing;
				}
			}

			if ( empty( $existing['bullet_type'] ) )
			{
				$bullet = self::bulletTypeFromTitle( $text );
				if ( $bullet !== null )
				{
					$results['bullet_type'] = $bullet;
				}
			}
		}

		return $results;
	}

	/**
	 * Detect a shotgun gauge in a title/description. Returns a canonical gauge
	 * string ("16 Gauge", ".410 Bore") or NULL. Covers all standard gauges and
	 * compact forms ("16Gauge", "20Ga", "12 GA"). Used to OVERRIDE caliber for
	 * shotguns/shotshells, since the gauge in the title is authoritative.
	 */
	public static function grainFromTitle( string $text ): ?int
	{
		if ( preg_match( '/\b(\d{2,4})\s*(?:gr(?:ain)?s?|grn)\b/i', $text, $m ) )
		{
			$val = (int) $m[1];
			if ( $val >= 10 && $val <= 800 )
			{
				return $val;
			}
		}
		return null;
	}

	public static function casingFromTitle( string $text ): ?string
	{
		$map = [
			'/\bBrass\b/i'            => 'Brass',
			'/\bNickel[\s-]?Plated\b/i' => 'Nickel-Plated',
			'/\bSteel(?:\s*Case)?\b/i' => 'Steel',
			'/\bAluminum\b/i'         => 'Aluminum',
			'/\bPolymer(?:\s*(?:Tip|Case))?\b/i' => 'Polymer',
		];
		foreach ( $map as $pattern => $label )
		{
			if ( preg_match( $pattern, $text ) )
			{
				return $label;
			}
		}
		return null;
	}

	public static function bulletTypeFromTitle( string $text ): ?string
	{
		$map = [
			'/\bFMJ\b/i'                                  => 'FMJ',
			'/\bFull\s*Metal\s*Jacket\b/i'                => 'FMJ',
			'/\bJHP\b/i'                                  => 'JHP',
			'/\bJacket(?:ed)?\s*Hollow\s*Point\b/i'       => 'JHP',
			'/\bHST\b/i'                                  => 'HST',
			'/\bV[\s-]?Max\b/i'                           => 'V-Max',
			'/\bHollow\s*Point\b/i'                       => 'HP',
			'/\bSoft\s*Point\b/i'                         => 'SP',
			'/\bJSP\b/i'                                  => 'JSP',
			'/\bBallistic\s*Tip\b/i'                      => 'Ballistic Tip',
			'/\bOTM\b/i'                                  => 'OTM',
			'/\bOpen\s*Tip\s*Match\b/i'                   => 'OTM',
			'/\bBTHP\b/i'                                 => 'BTHP',
			'/\bBoat[\s-]?Tail\s*Hollow\s*Point\b/i'      => 'BTHP',
			'/\bTMJ\b/i'                                  => 'TMJ',
			'/\bTotal\s*Metal\s*Jacket\b/i'               => 'TMJ',
			'/\bLRN\b/i'                                  => 'LRN',
			'/\bLead\s*Round\s*Nose\b/i'                  => 'LRN',
			'/\bWad[\s-]?Cutter\b/i'                      => 'Wadcutter',
			'/\bSWC\b/i'                                  => 'SWC',
			'/\bSemi[\s-]?Wad[\s-]?Cutter\b/i'           => 'SWC',
			'/\bFlexLock\b/i'                             => 'FlexLock',
			'/\bXTP\b/i'                                  => 'XTP',
			'/\bGold\s*Dot\b/i'                           => 'Gold Dot',
		];
		foreach ( $map as $pattern => $label )
		{
			if ( preg_match( $pattern, $text ) )
			{
				return $label;
			}
		}
		return null;
	}

	public static function gaugeFromTitle( string $text ): ?string
	{
		if ( preg_match( '/\.410\b/i', $text ) || preg_match( '/\b410\s*(?:bore|gauge|ga)\b/i', $text ) )
		{
			return '.410 Bore';
		}
		if ( preg_match( '/\b(10|12|16|20|24|28|32)\s*(?:gauge|ga)\b/i', $text, $m ) )
		{
			return $m[1] . ' Gauge';
		}
		return null;
	}
}
