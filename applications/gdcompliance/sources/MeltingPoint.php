<?php
/**
 * @brief  GD Compliance — Saturday-Night-Special / melting-point handgun
 *         classifier (v1.6.19)
 *
 * Several states (HI, IL, MD, MA, MN, NY) prohibit the SALE of handguns
 * whose frame material fails a minimum melting-point standard — the
 * "Saturday Night Special" ban targeting cheap zinc-alloy pot-metal
 * handguns. Thresholds: 800°F (HI/IL/MD/MA/NY) or 1000°F (MN, plus
 * tensile / density). The effect is the same for matching purposes:
 * pot-metal / zinc-alloy handguns are banned.
 *
 * This is a RESTRICT flag (red "cannot ship to:" banner), NOT an
 * advisory. Emitted via gd_compliance_flags with
 * firearm_type='melting_point'.
 *
 * Verified catalog facts (v1.6.19, do NOT deviate):
 *   - Handgun categories are cat1 (Handguns) + cat2 (Pistols) + cat3
 *     (Revolvers). cat8 = Semi-Auto Rifles/Carbines — Hi-Point 995TS
 *     lives there and MUST NOT flag as a handgun ban.
 *   - Match on the EXACT `brand` field, NOT the title. Title-matching
 *     'Heritage' hits "Heritage Cases" cases; title-matching 'Cobra'
 *     hits rail accessories; title-matching 'Hi-Point' hits ProMag
 *     mags that "Fit Hi-Point". All false positives.
 *   - Only Hi-Point (41 handguns) and Heritage Mfg (82 handguns) are
 *     present in the current catalog. Seed rules for other named
 *     pot-metal brands anyway for future stock.
 *   - Hi-Point titles may say "Black Steel" — that's the SLIDE, not
 *     the frame. Hi-Point frames are Zamak-3 zinc alloy → ALWAYS
 *     banned. Never clear Hi-Point on "steel" in title.
 *   - Heritage Rough Rider: title contains 'steel' → STEEL FRAME =
 *     LEGAL (clear). No 'steel' → ALLOY FRAME = banned (flag).
 *     The steel-title CLEAR rule applies to HERITAGE MFG ONLY.
 *
 * Layer order (first hit wins):
 *   1. Category gate — cat1/2/3 only. Anything else → null (skip).
 *   2. Curated gd_compliance_melting rows (substring over the same
 *      haystack the auto matcher uses). force_clear / force_flag /
 *      review — admin edits win.
 *   3. Auto brand match:
 *      3a. Heritage Mfg with 'steel' in title → clear.
 *      3b. Any banned brand (exact match on `brand`, case-insensitive)
 *          → flag.
 *   4. Default → null (skip). No "route to review" for the auto path;
 *      ambiguous cases live in the curated list.
 *
 * Verdicts returned by classify():
 *   ['verdict'=>'flag',   'source'=>'auto'|'curated', 'reason_hint'=>...]
 *   ['verdict'=>'clear',  'source'=>'auto'|'curated', 'reason_hint'=>...]
 *   ['verdict'=>'review', 'source'=>'curated']  (curated review action only)
 *   null   — not a handgun / no maker match — skip
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _MeltingPoint
{
	/** Handgun category ids — cat1 Handguns, cat2 Pistols, cat3 Revolvers. */
	const HANDGUN_CATEGORIES = [ 1, 2, 3 ];

	/**
	 * Named pot-metal / zinc-alloy handgun makers. Match on the EXACT
	 * `brand` field, case-insensitive. Do NOT title-match these — that
	 * flags gun cases, magazines, and rail accessories.
	 *
	 * @var string[]
	 */
	const BANNED_BRANDS = [
		'Hi-Point',
		'Heritage Mfg',
		'Phoenix Arms',
		'Jimenez',
		'Jimenez Arms',
		'Lorcin',
		'Lorcin Engineering',
		'Raven Arms',
		'Davis Industries',
		'Bryco',
		'Bryco Arms',
		'Jennings',
		'Jennings Firearms',
		'Sundance',
		'Sundance Industries',
		'Cobra Enterprises',
	];

	/**
	 * Brands whose title-'steel' means STEEL FRAME → clear. Applied
	 * only to the brand key here. Do NOT extend this to Hi-Point.
	 *
	 * @var string[]
	 */
	const STEEL_TITLE_BRANDS = [
		'Heritage Mfg',
	];

	/** Per-request memoization keyed by upc. */
	protected static array $cache = [];

	/**
	 * Curated override list (from gd_compliance_melting). Primed on
	 * first classify() call; reset via clearCache(). Each row:
	 *   [ 'pattern_lc' => string, 'action' => 'force_flag'|'force_clear'|'review',
	 *     'note' => string ]
	 *
	 * @var array<int, array{pattern_lc:string,action:string,note:string}>|null
	 */
	protected static ?array $curatedList = null;

	protected static function primeCuratedList(): void
	{
		if ( static::$curatedList !== null ) { return; }
		static::$curatedList = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_melting' ) as $row )
			{
				$pattern = trim( (string) ( $row['pattern'] ?? '' ) );
				$action  = strtolower( (string) ( $row['action'] ?? '' ) );
				if ( $pattern === '' ) { continue; }
				if ( !in_array( $action, [ 'force_flag', 'force_clear', 'review' ], true ) ) { continue; }
				static::$curatedList[] = [
					'pattern_lc' => strtolower( $pattern ),
					'action'     => $action,
					'note'       => (string) ( $row['note'] ?? '' ),
				];
			}
		}
		catch ( \Throwable ) { /* table missing on partial upgrade → empty */ }
	}

	/**
	 * Classify a catalog row. Category-gated: non-handgun categories
	 * short-circuit to null. Curated matches win; auto rules follow.
	 *
	 * Expected $p keys: upc, category_id, title, brand, model, mpn
	 * (any missing key → empty string).
	 *
	 * @param array<string, mixed> $p
	 * @return array{verdict:string, source:string, reason_hint?:string, note?:string}|null
	 */
	public static function classify( array $p ): ?array
	{
		$upc = (string) ( $p['upc'] ?? '' );
		if ( $upc !== '' && isset( static::$cache[ $upc ] ) )
		{
			return static::$cache[ $upc ];
		}

		$cat = (int) ( $p['category_id'] ?? 0 );
		if ( !in_array( $cat, self::HANDGUN_CATEGORIES, true ) )
		{
			return static::$cache[ $upc ] = null;
		}

		$title    = (string) ( $p['title'] ?? '' );
		$titleLC  = strtolower( $title );
		$brand    = trim( (string) ( $p['brand'] ?? '' ) );
		$brandLC  = strtolower( $brand );

		/* Curated haystack — includes upc + title + brand + model + mpn
		   so a curated pattern can pin one UPC, one model, or an entire
		   brand line. */
		$haystack = strtolower( implode( ' | ', array_filter( [
			$upc,
			$title,
			$brand,
			(string) ( $p['model'] ?? '' ),
			(string) ( $p['mpn']   ?? '' ),
		], 'strlen' ) ) );

		/* ----- Layer 1: curated overrides win over auto logic ----- */
		self::primeCuratedList();
		if ( !empty( static::$curatedList ) )
		{
			foreach ( static::$curatedList as $c )
			{
				if ( $c['pattern_lc'] === '' ) { continue; }
				if ( strpos( $haystack, $c['pattern_lc'] ) !== false )
				{
					$verdict = match( $c['action'] ) {
						'force_clear' => 'clear',
						'review'      => 'review',
						default       => 'flag',
					};
					$out = [
						'verdict'     => $verdict,
						'source'      => 'curated',
						'reason_hint' => 'curated ' . $c['action'],
						'note'        => $c['note'],
					];
					return static::$cache[ $upc ] = $out;
				}
			}
		}

		if ( $brand === '' )
		{
			return static::$cache[ $upc ] = null;
		}

		/* ----- Layer 2: auto brand match ------
		   Exact case-insensitive brand match, NOT title. Prevents
		   "Heritage Cases" gun cases, ProMag "Fits Hi-Point"
		   magazines, and Cobra rail accessories from false-flagging. */
		$matched = null;
		foreach ( self::BANNED_BRANDS as $bb )
		{
			if ( strcasecmp( $brand, $bb ) === 0 )
			{
				$matched = $bb;
				break;
			}
		}

		if ( $matched === null )
		{
			return static::$cache[ $upc ] = null;
		}

		/* ----- Layer 3: Heritage steel-title clear —
		   applies to HERITAGE MFG ONLY. Hi-Point 'Black Steel' refers
		   to the SLIDE; Hi-Point frames are zinc → always flag. */
		if ( in_array( $matched, self::STEEL_TITLE_BRANDS, true )
		  && strpos( $titleLC, 'steel' ) !== false )
		{
			$out = [
				'verdict'     => 'clear',
				'source'      => 'auto',
				'reason_hint' => 'steel frame per title',
			];
			return static::$cache[ $upc ] = $out;
		}

		$out = [
			'verdict'     => 'flag',
			'source'      => 'auto',
			'reason_hint' => 'brand: ' . $matched,
		];
		return static::$cache[ $upc ] = $out;
	}

	/**
	 * All enabled melting-point rules keyed by state_code, in a
	 * cache. Silent on any DB error (missing table on a partial
	 * upgrade → empty cache, no flags emitted).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function enabledRules(): array
	{
		static $ruleCache = null;
		if ( $ruleCache !== null ) { return $ruleCache; }
		$ruleCache = [];
		try
		{
			$today = date( 'Y-m-d' );
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_melting_rules', [ 'enabled=?', 1 ] ) as $row )
			{
				$sc = strtoupper( (string) ( $row['state_code'] ?? '' ) );
				if ( $sc === '' ) { continue; }
				$eff = (string) ( $row['effective_date'] ?? '' );
				if ( $eff !== '' && $eff > $today ) { continue; }
				$ruleCache[ $sc ] = $row;
			}
		}
		catch ( \Throwable ) {}
		return $ruleCache;
	}

	/** Reset per-request caches — install / upgrade / admin edits. */
	public static function clearCache(): void
	{
		static::$cache       = [];
		static::$curatedList = null;
	}
}

class MeltingPoint extends _MeltingPoint {}
