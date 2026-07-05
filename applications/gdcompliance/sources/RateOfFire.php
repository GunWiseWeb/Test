<?php
/**
 * @brief  GD Compliance — rate-of-fire enhancer classifier (v1.6.21)
 *
 * Binary triggers, forced-reset triggers (FRTs), bump stocks, trigger
 * cranks — many states bundle these under one "rate-of-fire enhancer"
 * / "multiburst trigger activator" / "rapid-fire device" statute.
 * RESTRICT flag (red "cannot ship to" banner), NOT an advisory.
 * Emitted via gd_compliance_flags with firearm_type='rate_of_fire'.
 *
 * Banned states (14 as of v1.6.21):
 *   CA, CT, DE, HI, IL, MD, MA, NJ, NY, RI, WA, DC, OR, NV.
 * ⚠️  MINNESOTA is NOT banned — MN's binary-trigger ban was struck
 *     down by the MN Court of Appeals on 2026-05-26 (Minn. Gun
 *     Owners Caucus v. Walz, single-subject clause). Federal status
 *     is legal (Cargill, FRT settlement). DO NOT flag MN.
 *
 * ⚠️⚠️ CRITICAL — loose keyword matching is a disaster here. VERIFIED
 *     false positives that MUST NOT trigger:
 *   - "Rare Breed"   → Primos TURKEY CALLS (cat115) — not FRTs.
 *   - "BFS"          → CobraTec KNIVES (cat138), Wilson Combat
 *                       CQBFS PISTOLS ("CQB Full Size"), Springfield
 *                       KUNA — not binary triggers.
 *   - "FRT"          → Night Fision / FAB / Samson / TacFire
 *                       "FRONT" sights — not forced-reset triggers.
 *   - Bare "binary"  → hits configuration knives, etc.
 *
 * Matcher rules (v1.6.21 MVP):
 *
 *   1. Curated gd_compliance_rof rows (substring over
 *      upc+title+brand+model+MPN) win. force_flag / force_clear /
 *      review — admin edits win.
 *
 *   2. Franklin Armory BFS / binary trigger (cross-category rule):
 *      brand === 'Franklin Armory' AND title contains 'binary' OR
 *      'BFS' OR 'BFSIII' → FLAG regardless of category. Catches the
 *      standalone triggers (cat58/60) AND complete Franklin Armory
 *      rifles that ship with a binary installed (cat8).
 *
 *   3. Category-gated safe-phrase rules (cat58 Parts & Accessories +
 *      cat60 Triggers & Trigger Groups):
 *      - 'bump stock' → FLAG (safe: no false positives observed)
 *      - 'trigger crank' → FLAG (safe: no false positives observed)
 *      NEVER 'binary' / 'FRT' / 'BFS' / 'rare breed' alone — those
 *      require a brand qualifier.
 *
 *   4. Additional named makers arrive via the curated table (Fix 3):
 *      Rare Breed Triggers, Fostech Echo, Wide Open Trigger,
 *      Autodynamics — matched by EXACT brand OR specific model
 *      string, never bare title token.
 *
 * Default → null (skip). Never auto-flag on a bare token or non-
 * gated category.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _RateOfFire
{
	/** Cat58 = Parts & Accessories, Cat60 = Triggers & Trigger Groups. */
	const GATED_CATEGORIES = [ 58, 60 ];

	/**
	 * Franklin Armory binary-trigger tokens. Case-insensitive; requires
	 * brand='Franklin Armory' to fire. 'BFS' / 'BFSIII' alone would
	 * false-hit CobraTec knives / Wilson Combat CQBFS pistols — never
	 * matched without the brand.
	 *
	 * @var string[]
	 */
	const FRANKLIN_BINARY_TOKENS = [
		'binary',
		'bfs',
		'bfsiii',
	];

	/**
	 * Safe title phrases — no verified false positives. Only checked
	 * inside GATED_CATEGORIES.
	 *
	 * @var string[]
	 */
	const SAFE_PHRASE_TOKENS = [
		'bump stock',
		'trigger crank',
	];

	/** Per-request memoization. */
	protected static array $cache = [];

	/**
	 * Curated override list (from gd_compliance_rof).
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
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_rof' ) as $row )
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
		catch ( \Throwable ) { /* table missing → empty */ }
	}

	/**
	 * Classify a catalog row. Never auto-flags on bare tokens; requires
	 * either a brand qualifier (Franklin Armory + binary/BFS) or an
	 * unambiguous safe phrase inside cat58/60.
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

		$cat     = (int) ( $p['category_id'] ?? 0 );
		$title   = (string) ( $p['title'] ?? '' );
		$titleLC = strtolower( $title );
		$brand   = trim( (string) ( $p['brand'] ?? '' ) );

		/* Curated haystack — UPC + title + brand + model + MPN. */
		$haystack = strtolower( implode( ' | ', array_filter( [
			$upc, $title, $brand,
			(string) ( $p['model'] ?? '' ),
			(string) ( $p['mpn']   ?? '' ),
		], 'strlen' ) ) );

		/* ----- Layer 1: curated overrides win over auto logic. ----- */
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

		/* ----- Layer 2: Franklin Armory BFS / binary — cross-category.
		         Brand qualifier is required so 'BFS' knives / 'CQBFS'
		         pistols never match. */
		if ( strcasecmp( $brand, 'Franklin Armory' ) === 0 )
		{
			foreach ( self::FRANKLIN_BINARY_TOKENS as $t )
			{
				if ( strpos( $titleLC, $t ) !== false )
				{
					$out = [
						'verdict'     => 'flag',
						'source'      => 'auto-franklin',
						'reason_hint' => 'Franklin Armory binary/BFS trigger (' . $t . ')',
					];
					return static::$cache[ $upc ] = $out;
				}
			}
		}

		/* ----- Layer 3: category-gated safe-phrase rules. Only inside
		         cat58 Parts & Accessories or cat60 Triggers & Trigger
		         Groups. Bare 'binary' / 'FRT' / 'BFS' / 'rare breed'
		         are NEVER matched here — those require a brand
		         qualifier (curated list, Layer 1). */
		if ( in_array( $cat, self::GATED_CATEGORIES, true ) )
		{
			foreach ( self::SAFE_PHRASE_TOKENS as $t )
			{
				if ( strpos( $titleLC, $t ) !== false )
				{
					$out = [
						'verdict'     => 'flag',
						'source'      => 'auto-phrase',
						'reason_hint' => $t,
					];
					return static::$cache[ $upc ] = $out;
				}
			}
		}

		return static::$cache[ $upc ] = null;
	}

	/**
	 * Enabled state rules keyed by state_code. Silent on any DB error.
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
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_rof_rules', [ 'enabled=?', 1 ] ) as $row )
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

	public static function clearCache(): void
	{
		static::$cache       = [];
		static::$curatedList = null;
	}
}

class RateOfFire extends _RateOfFire {}
