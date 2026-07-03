<?php
/**
 * @brief  GD Compliance — AR/AK lower-receiver classifier
 *
 * A stripped or assembled lower receiver is the SERIALIZED firearm and,
 * for AR/AK-pattern rifles, is treated as an assault-weapon component
 * in the rifle-class AWB states. Complete-firearm feature detection
 * doesn't apply — there's no stock/grip/barrel to inspect — so this
 * classifier is a pure title/category match with a hard exclusion list
 * to keep parts (handguards, rails, uppers) from false-flagging.
 *
 * Verified category facts (v1.6.9):
 *   cat154  Lower Receivers      — clean primary source (135 items, ~84 AR/AK)
 *   cat69   Frames & Receivers   — JUNK (509 items, mostly handguards / quad
 *                                  rails / MLOK forends). Title-gated only —
 *                                  never category-matched wholesale.
 *   cat153  Upper Receivers      — NOT the serialized firearm. Excluded.
 *
 * Verdicts returned by qualify():
 *   'flag'      — high confidence AR/AK-pattern serialized lower →
 *                 emit awb_lower flag for every enabled rifle-class AWB
 *                 state.
 *   'review'    — cat154 lower with no platform keyword hit (~51 of 135
 *                 known non-AR/AK on-disk). Route to review queue for
 *                 Derrick to confirm, don't hard-restrict.
 *   null        — not a lower / excluded (parts, uppers, cat69 non-lower
 *                 titles, etc.). Skip.
 *
 * The pattern set lives in code so a plugin re-install always ships a
 * working matcher; per-instance additions/overrides may be layered on
 * top via the (optional) gd_compliance_lowers table.
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
	 * AR/AK-pattern platform keywords. Case-insensitive substring match
	 * against a normalized "haystack" (title + brand + manufacturer +
	 * model + mpn joined). Order matters ONLY for the returned
	 * `matched_pattern` label — the first hit wins for readability.
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
		'MSR', /* Multi-Caliber Semi Rifle — Savage MSR family, etc. */

		/* AK family */
		'AK-47', 'AK47', 'AK 47',
		'AK-74', 'AK74', 'AK 74',
		'AKM',
		'AK Pattern', 'AK-Pattern', 'AKS',

		/* Generic descriptors */
		'AR Pattern', 'AR-Pattern',
	];

	/**
	 * Anti-match tokens. If the haystack contains ANY of these, the row
	 * is NOT a lower even if a platform pattern also matched. These
	 * cover the noise that pollutes cat69 (handguards, rails, MLOK
	 * forends) and prevent uppers/parts from being classified as
	 * lowers. Applied before the platform pattern list.
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
	 * Cat69 title-gate: only category 69 items whose title contains
	 * ONE of these phrases qualify for lower-receiver evaluation. This
	 * keeps 509 mostly-parts rows from being wholesale evaluated as
	 * lowers.
	 *
	 * @var string[]
	 */
	const LOWER_TITLE_GATE_KEYWORDS = [
		'lower receiver',
		'stripped lower',
		'assembled lower',
		'complete lower',
	];

	/** Per-request memoization keyed by upc. */
	protected static array $cache = [];

	/**
	 * Classify a catalog row. Returns:
	 *   ['verdict' => 'flag',   'pattern' => 'AR-15']
	 *   ['verdict' => 'review', 'pattern' => null]
	 *   null   (not a lower / excluded — skip)
	 *
	 * Expected $p keys: upc, category_id, title, brand, manufacturer,
	 * model, mpn, description (any missing key defaults to empty).
	 *
	 * @param array<string, mixed> $p
	 * @return array{verdict:string, pattern:?string}|null
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

		$title = (string) ( $p['title'] ?? '' );
		$titleLC = strtolower( $title );

		/* cat69 gate — only proceed if title indicates a real receiver. */
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

		/* Build a normalized haystack across identity fields. Empty
		   fields collapse to nothing — the join separator prevents
		   accidental token merges (e.g. brand "MSR" + model "15"
		   would otherwise fuse into "MSR15" and match). */
		$haystack = strtolower( implode( ' | ', array_filter( [
			$title,
			(string) ( $p['brand']        ?? '' ),
			(string) ( $p['manufacturer'] ?? '' ),
			(string) ( $p['model']        ?? '' ),
			(string) ( $p['mpn']          ?? '' ),
		], 'strlen' ) ) );

		/* Exclusion sweep. If ANY exclude token hits, this is a part
		   / upper / accessory — even if a platform token also hit. */
		foreach ( self::LOWER_EXCLUDE_PATTERNS as $bad )
		{
			if ( strpos( $haystack, strtolower( $bad ) ) !== false )
			{
				return static::$cache[ $upc ] = null;
			}
		}

		/* Platform-pattern sweep. First hit wins for the label. */
		$matched = null;
		foreach ( self::LOWER_PLATFORM_PATTERNS as $pat )
		{
			if ( strpos( $haystack, strtolower( $pat ) ) !== false )
			{
				$matched = $pat;
				break;
			}
		}

		if ( $matched !== null )
		{
			$out = [ 'verdict' => 'flag', 'pattern' => $matched ];
			return static::$cache[ $upc ] = $out;
		}

		/* No platform hit — but cat154 items ARE lowers by category, so
		   the platform classification is what's uncertain (bolt-action
		   lower? .22 rimfire lower?). Route those to review. cat69
		   passed the title gate so it's a real receiver too. */
		$out = [ 'verdict' => 'review', 'pattern' => null ];
		return static::$cache[ $upc ] = $out;
	}

	/** Reset per-request cache — call from install / recompute. */
	public static function clearCache(): void
	{
		static::$cache = [];
	}
}

class Lowers extends _Lowers {}
