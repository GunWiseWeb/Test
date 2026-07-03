<?php
/**
 * @brief  GD Compliance — AR/AK lower-receiver classifier (v1.6.10)
 *
 * A stripped or assembled lower receiver is the SERIALIZED firearm. For
 * AR/AK-pattern (semi-auto centerfire) rifles it is treated as an
 * assault-weapon component in the rifle-class AWB states.
 *
 * v1.6.10 broadening — cat154 IS "Lower Receivers". Prior versions only
 * flagged when a platform keyword hit the title, so plain-named lowers
 * like "SHARK COAST SCTSL-ODG STRIPPED LOWER ODG" were routed to review
 * and never flagged. That's a miss. The new default: cat154 lowers flag
 * unless there's a clear signal they're a non-AWB action (bolt/lever/
 * pump/rimfire hunting rifle).
 *
 * Layer order (first non-null wins):
 *   1. Curated gd_compliance_lowers rows — force_clear / force_flag /
 *      review. Admin edits win over auto logic.
 *   2. Non-lower exclusions (parts, uppers, handguards, MLOK, etc.) →
 *      return null (skip entirely).
 *   3. Category gate (cat154 always; cat69 title-gated).
 *   4. Non-AWB action check (bolt / lever / pump / rimfire-only) →
 *      verdict='review'.
 *   5. Default → verdict='flag' with the matched platform pattern for
 *      the reason string, or the sentinel 'semi-auto pattern lower'
 *      when the title is model-only.
 *
 * Verdicts returned by classify():
 *   ['verdict'=>'flag',   'pattern'=>'AR-15' | 'semi-auto pattern lower', 'source'=>'auto'|'curated']
 *   ['verdict'=>'review', 'pattern'=>null,    'source'=>'auto'|'curated', 'reason_hint'=>'bolt-action', ...]
 *   ['verdict'=>'clear',  'pattern'=>null,    'source'=>'curated']  (curated force_clear only)
 *   null   — not a lower / not evaluated (parts, uppers, wrong category)
 *
 * Cat facts (verified):
 *   cat154  Lower Receivers      — clean primary source, always evaluated.
 *   cat69   Frames & Receivers   — JUNK (handguards/rails). Title-gated only.
 *   cat153  Upper Receivers      — NOT lowers. Excluded.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Lowers
{
	/** Master category id for lower receivers — always category-matched. */
	const CATEGORY_LOWER = 154;

	/** Frames & Receivers junk drawer — title-gated only. */
	const CATEGORY_FRAMES_JUNK = 69;

	/**
	 * AR/AK-pattern platform keywords. Used ONLY to enrich the reason
	 * string (which pattern matched) and, in the curated-list auto
	 * matcher, as an optional filter. The default flag decision no
	 * longer depends on a platform-keyword hit — a plain cat154 lower
	 * flags without one.
	 *
	 * @var string[]
	 */
	const LOWER_PLATFORM_PATTERNS = [
		/* AR-15 family + calibers */
		'AR-15', 'AR15', 'AR 15',
		'AR-10', 'AR10', 'AR 10', '.308 AR', '308 AR',
		'AR-9',  'AR9',  'AR 9',
		'LR-308', 'LR308',
		'M4 Carbine', 'M-16', 'M16', 'M4',
		'M&P15', 'M&P-15', 'MP15', 'MP-15',
		'DPMS',
		'MSR',

		/* AK family */
		'AK-47', 'AK47', 'AK 47',
		'AK-74', 'AK74', 'AK 74',
		'AKM',
		'AK Pattern', 'AK-Pattern', 'AKS',

		/* Generic */
		'AR Pattern', 'AR-Pattern',
	];

	/**
	 * Anti-match tokens. If the haystack contains ANY of these, the row
	 * is NOT a lower even if a platform pattern also matched. These
	 * cover the noise that pollutes cat69 (handguards, rails, MLOK
	 * forends) and prevent uppers/parts from being classified as
	 * lowers. Applied before the auto-flag decision.
	 *
	 * @var string[]
	 */
	const LOWER_EXCLUDE_PATTERNS = [
		'handguard', 'hand guard',
		'quad rail', 'quadrail',
		'mlok', 'm-lok', 'm lok',
		'keymod', 'key-mod', 'key mod',
		'forend', 'fore-end', 'fore end', 'forearm',
		'upper receiver', 'upper assembly', 'complete upper',
		'barrel',
		'buffer tube', 'buffer kit', 'buffer assembly',
		'grip', /* keeps "pistol grip", "vertical grip" out */
		'rail section', 'picatinny rail', 'top rail',
		'stock kit', 'stock assembly', 'buttstock',
		'trigger', 'sear',
		'bolt carrier', 'bcg', 'charging handle',
		'gas block', 'gas tube',
		'muzzle', 'flash hider', 'compensator', 'brake',
		'sight', 'optic', 'scope', 'mount',
		'sling', 'case', 'cover', 'pouch', 'holster',
	];

	/**
	 * Cat69 title-gate — cat 69 items must contain one of these
	 * phrases to be considered for lower evaluation. Guards ~509
	 * mostly-parts rows from wholesale evaluation.
	 *
	 * @var string[]
	 */
	const LOWER_TITLE_GATE_KEYWORDS = [
		'lower receiver',
		'stripped lower',
		'assembled lower',
		'complete lower',
	];

	/**
	 * Non-AWB action signals. A cat154 lower whose title/caliber
	 * indicates a bolt-action / lever-action / pump-action or rimfire-
	 * only hunting rifle is routed to review — those aren't
	 * assault-weapon components even when serialized as receivers.
	 *
	 * @var string[]
	 */
	const NON_AWB_ACTION_KEYWORDS = [
		'bolt action', 'bolt-action', 'boltaction',
		'lever action', 'lever-action',
		'pump action', 'pump-action',
		'single shot', 'single-shot',
		'break action', 'break-action',
	];

	/**
	 * Rimfire-only calibers. Presence in caliber (any form) sends the
	 * verdict to 'review' rather than 'flag' — AWB statutes uniformly
	 * exempt rimfire rifles (Illinois PICA, CA §30510, etc.).
	 *
	 * @var string[]
	 */
	const RIMFIRE_KEYWORDS = [
		'.22 lr', '.22lr', '22 lr', '22lr', '.22 long rifle', '22 long rifle',
		'.17 hmr', '17 hmr', '.17hmr',
		'.22 wmr', '22 wmr', '.22wmr', '.22 magnum', '22 magnum',
		'17 wsm', '.17 wsm', '17wsm',
	];

	/**
	 * Bolt-rifle model families. Presence in title/model → review.
	 *
	 * @var string[]
	 */
	const BOLT_MODEL_FAMILIES = [
		'savage 110', 'savage axis', 'savage impulse',
		'remington 700', 'rem 700', 'rem-700',
		'ruger american', 'ruger precision', 'ruger hawkeye',
		'tikka t3', 'tikka t3x',
		'bergara b-14', 'bergara b14', 'bergara hmr',
		'howa 1500', 'howa m1500',
		'winchester model 70', 'winchester xpr',
		'weatherby vanguard', 'weatherby mark v',
	];

	/** Per-request memoization keyed by upc. */
	protected static array $cache = [];

	/**
	 * Curated override list (from gd_compliance_lowers). Primed on first
	 * classify() call; reset via clearCache(). Each row:
	 *   [ 'pattern_lc' => string, 'action' => 'force_flag'|'force_clear'|'review',
	 *     'note' => string, 'platform' => string ]
	 *
	 * @var array<int, array{pattern_lc:string,action:string,note:string,platform:string}>|null
	 */
	protected static ?array $curatedList = null;

	/**
	 * Load the curated list once per request. Silent on any DB error
	 * (missing table on a partial-upgrade install = empty list, safe).
	 */
	protected static function primeCuratedList(): void
	{
		if ( static::$curatedList !== null ) { return; }
		static::$curatedList = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_lowers' ) as $row )
			{
				$pattern = trim( (string) ( $row['pattern'] ?? '' ) );
				$action  = strtolower( (string) ( $row['action'] ?? '' ) );
				if ( $pattern === '' ) { continue; }
				if ( !in_array( $action, [ 'force_flag', 'force_clear', 'review' ], true ) ) { continue; }
				static::$curatedList[] = [
					'pattern_lc' => strtolower( $pattern ),
					'action'     => $action,
					'note'       => (string) ( $row['note'] ?? '' ),
					'platform'   => (string) ( $row['platform'] ?? '' ),
				];
			}
		}
		catch ( \Throwable ) { /* table may not exist yet on first-run */ }
	}

	/**
	 * Classify a catalog row.
	 *
	 * Expected $p keys: upc, category_id, title, brand, manufacturer,
	 * model, mpn, caliber, description (any missing key defaults to
	 * empty).
	 *
	 * @param array<string, mixed> $p
	 * @return array{verdict:string, pattern:?string, source:string, reason_hint?:string, note?:string}|null
	 */
	public static function classify( array $p ): ?array
	{
		$upc = (string) ( $p['upc'] ?? '' );
		if ( $upc !== '' && isset( static::$cache[ $upc ] ) )
		{
			return static::$cache[ $upc ];
		}

		$cat = (int) ( $p['category_id'] ?? 0 );
		if ( $cat !== self::CATEGORY_LOWER && $cat !== self::CATEGORY_FRAMES_JUNK )
		{
			return static::$cache[ $upc ] = null;
		}

		$title       = (string) ( $p['title'] ?? '' );
		$titleLC     = strtolower( $title );
		$calRaw      = (string) ( $p['caliber'] ?? '' );
		$calLC       = strtolower( $calRaw );

		/* Cat69 title-gate — real receivers only. */
		if ( $cat === self::CATEGORY_FRAMES_JUNK )
		{
			$gated = false;
			foreach ( self::LOWER_TITLE_GATE_KEYWORDS as $kw )
			{
				if ( strpos( $titleLC, $kw ) !== false ) { $gated = true; break; }
			}
			if ( !$gated )
			{
				return static::$cache[ $upc ] = null;
			}
		}

		/* Normalized haystack across identity fields, |-joined so
		   tokens can't fuse across fields. */
		$haystack = strtolower( implode( ' | ', array_filter( [
			$title,
			(string) ( $p['brand']        ?? '' ),
			(string) ( $p['manufacturer'] ?? '' ),
			(string) ( $p['model']        ?? '' ),
			(string) ( $p['mpn']          ?? '' ),
		], 'strlen' ) ) );

		/* ----- Layer 1: curated override list wins over auto logic ----- */
		self::primeCuratedList();
		if ( !empty( static::$curatedList ) )
		{
			foreach ( static::$curatedList as $c )
			{
				if ( $c['pattern_lc'] === '' ) { continue; }
				/* Match against the full haystack + MPN — also the exact
				   UPC so admins can pin one specific product. */
				if ( strpos( $haystack, $c['pattern_lc'] ) !== false
				  || strpos( strtolower( $upc ), $c['pattern_lc'] ) !== false )
				{
					if ( $c['action'] === 'force_clear' )
					{
						$out = [ 'verdict' => 'clear', 'pattern' => null, 'source' => 'curated', 'note' => $c['note'] ];
						return static::$cache[ $upc ] = $out;
					}
					if ( $c['action'] === 'review' )
					{
						$out = [ 'verdict' => 'review', 'pattern' => null, 'source' => 'curated', 'reason_hint' => 'curated review', 'note' => $c['note'] ];
						return static::$cache[ $upc ] = $out;
					}
					/* force_flag */
					$out = [ 'verdict' => 'flag', 'pattern' => ( $c['platform'] !== '' ? $c['platform'] : 'curated match' ), 'source' => 'curated', 'note' => $c['note'] ];
					return static::$cache[ $upc ] = $out;
				}
			}
		}

		/* ----- Layer 2: exclusion sweep. Parts / uppers / accessories. ----- */
		foreach ( self::LOWER_EXCLUDE_PATTERNS as $bad )
		{
			if ( strpos( $haystack, strtolower( $bad ) ) !== false )
			{
				return static::$cache[ $upc ] = null;
			}
		}

		/* ----- Layer 3: non-AWB action check. Bolt / lever / pump / rimfire. -----
		   Split into three signals — any one routes to review rather
		   than hard-flagging a hunting-rifle lower as an AWB component. */

		/* 3a: explicit action-word */
		foreach ( self::NON_AWB_ACTION_KEYWORDS as $kw )
		{
			if ( strpos( $haystack, $kw ) !== false )
			{
				$out = [ 'verdict' => 'review', 'pattern' => null, 'source' => 'auto', 'reason_hint' => 'non-AWB action (' . $kw . ')' ];
				return static::$cache[ $upc ] = $out;
			}
		}

		/* 3b: rimfire-only caliber (any centerfire platform token overrides
		       this — a "17 HMR"-labeled AR upper on a caliber field is not
		       decisive by itself). We check caliber first, then whether any
		       centerfire platform keyword appears in haystack to override. */
		if ( $calLC !== '' )
		{
			$isRimfire = false;
			$hitKw     = '';
			foreach ( self::RIMFIRE_KEYWORDS as $kw )
			{
				if ( strpos( $calLC, $kw ) !== false ) { $isRimfire = true; $hitKw = $kw; break; }
			}
			if ( $isRimfire )
			{
				/* Look for a centerfire platform hint to override. */
				$centerfireOverride = false;
				foreach ( self::LOWER_PLATFORM_PATTERNS as $pat )
				{
					if ( strpos( $haystack, strtolower( $pat ) ) !== false )
					{
						$centerfireOverride = true;
						break;
					}
				}
				if ( !$centerfireOverride )
				{
					$out = [ 'verdict' => 'review', 'pattern' => null, 'source' => 'auto', 'reason_hint' => 'rimfire caliber (' . $hitKw . ')' ];
					return static::$cache[ $upc ] = $out;
				}
			}
		}

		/* 3c: known bolt-rifle model families. */
		foreach ( self::BOLT_MODEL_FAMILIES as $fam )
		{
			if ( strpos( $haystack, $fam ) !== false )
			{
				$out = [ 'verdict' => 'review', 'pattern' => null, 'source' => 'auto', 'reason_hint' => 'bolt-rifle model (' . $fam . ')' ];
				return static::$cache[ $upc ] = $out;
			}
		}

		/* ----- Layer 4: default flag. cat154 IS Lower Receivers; cat69
		         passed the title gate. Nothing above excluded or routed
		         to review — treat as an AWB-pattern serialized lower. */
		$matched = null;
		foreach ( self::LOWER_PLATFORM_PATTERNS as $pat )
		{
			if ( strpos( $haystack, strtolower( $pat ) ) !== false )
			{
				$matched = $pat;
				break;
			}
		}
		$out = [
			'verdict' => 'flag',
			'pattern' => $matched ?? 'semi-auto pattern lower',
			'source'  => 'auto',
		];
		return static::$cache[ $upc ] = $out;
	}

	/**
	 * Reset per-request caches (curated list + memo). Called from
	 * install/upgrade and after Derrick edits curated rows.
	 */
	public static function clearCache(): void
	{
		static::$cache       = [];
		static::$curatedList = null;
	}
}

class Lowers extends _Lowers {}
