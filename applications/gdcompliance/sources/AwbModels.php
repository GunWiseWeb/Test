<?php
/**
 * @brief  GD Compliance — multi-state Assault-Weapons-Ban framework
 *
 * Generalizes the IL-only PICA class into a per-state AWB engine.
 * Reads two editable tables:
 *
 *   gd_compliance_awb_models   — per-state named-model list (Tier-1)
 *   gd_compliance_awb_rules    — per-state feature-test config
 *                                (threshold, centerfire-only, CA length rule)
 *
 * IL PICA (720 ILCS 5/24-1.9), CA (Pen 30510/30515), and NY (Penal
 * 265.00 SAFE Act) are seeded enabled; MA/MD/NJ/CT/WA/DE/DC/HI seeded
 * or disabled depending on statute state; RI auto-activates via
 * effective_date 2026-07-01; VA is seeded disabled (in legal flux).
 *
 * match($product, $stateCode) returns:
 *   ['tier'=>1, 'pattern'=>..., 'citation'=>..., 'feature_hits'=>[]]
 *     — named-model hit for that state
 *   ['tier'=>2, 'pattern'=>null, 'citation'=>..., 'feature_hits'=>[...]]
 *     — feature test met (detected features >= state threshold)
 *   ['tier'=>3, 'pattern'=>null, 'citation'=>..., 'feature_hits'=>[...]]
 *     — semi-auto centerfire rifle but detected features below threshold;
 *       route to REVIEW (our feature detection is incomplete, so we can't
 *       be sure it doesn't meet the test in reality)
 *   null — not AWB (rimfire, or feature test doesn't apply)
 *
 * Backward-compat: sources/PicaModels.php is a thin subclass that
 * pre-scopes match() to state_code='IL'.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _AwbModels
{
	/** @var array<string, array<int, array{pattern:string,pattern_norm:string,platform_group:string,citation:string}>>  per-request cache keyed by state */
	protected static array $modelCache = [];

	/** @var array<string, array<string, array<string, mixed>>>|null  per-request cache: state → class → rule row */
	protected static ?array $ruleCache = null;

	/* Per-PRODUCT memoization (v1.6.4). isCenterfire and detectFeatures
	   are state-independent, but pre-v1.6.4 they ran once per (product,
	   state) — so a rifle went through 10 identical regex passes over
	   its title+description for the 10 rifle-AWB states. Memoizing by
	   product upc drops that 10× overhead to 1×. detectFeatures dominated
	   perf because it's 6 preg_match calls on ~3KB of description text.
	   Bounded LRU-ish flush at 8k entries so the cache doesn't grow
	   unbounded across a 58k-row scan. */
	/** @var array<string, bool> */
	protected static array $centerfireCache = [];
	/** @var array<string, array<int, string>> */
	protected static array $featuresCache = [];
	/** @var array<string, ?float> */
	protected static array $oalCache = [];

	/**
	 * Aggressive normalizer: lowercase + strip every non-alphanumeric.
	 * "M&P15" / "M&P-15" / "MP15" all collide to "mp15".
	 */
	public static function normalize( string $s ): string
	{
		$s = strtolower( $s );
		return (string) preg_replace( '/[^a-z0-9]/', '', $s );
	}

	/**
	 * Cheap centerfire check — rimfire caliber excluded from every state's
	 * AWB per (a)(2)-style rimfire exemptions. Matches the common rimfire
	 * calibers Derrick's catalog uses.
	 */
	public static function isCenterfire( array $product ): bool
	{
		/* Per-product memoization (v1.6.4). See $centerfireCache docblock. */
		$upc = (string) ( $product['upc'] ?? '' );
		if ( $upc !== '' && isset( static::$centerfireCache[ $upc ] ) )
		{
			return static::$centerfireCache[ $upc ];
		}

		$cal = strtolower( trim( (string) ( $product['caliber'] ?? '' ) ) );
		$chamber = strtolower( trim( (string) ( $product['chamber'] ?? '' ) ) );
		$hay = $cal . ' ' . $chamber;
		$result = true;
		if ( $hay !== ' ' && preg_match( '/(^|\s|\.)(22\s?(lr|wmr|short|long|mag)?|22 ?magnum|17\s?(hmr|hm2|mach|wsm)|5\s?mm ?rem|22 ?win ?mag|22 ?rf)\b/i', $hay ) )
		{
			$result = false;
		}

		if ( $upc !== '' )
		{
			if ( count( static::$centerfireCache ) >= 8000 ) { static::$centerfireCache = []; }
			static::$centerfireCache[ $upc ] = $result;
		}
		return $result;
	}

	/**
	 * Parse overall-length inches from a free-text catalog field.
	 * Returns float inches or null. Handles "28.75", "28.75\"", "28 in",
	 * "28 3/4\"", etc. Conservative — returns null on ambiguity.
	 */
	public static function parseOverallLengthIn( ?string $raw ): ?float
	{
		if ( $raw === null ) { return null; }
		$raw = strtolower( trim( $raw ) );
		if ( $raw === '' ) { return null; }

		/* Memoize on the RAW string (product-independent, small key). */
		if ( isset( static::$oalCache[ $raw ] ) ) { return static::$oalCache[ $raw ]; }
		if ( count( static::$oalCache ) >= 8000 ) { static::$oalCache = []; }

		if ( preg_match( '/(\d+)\s+(\d+)\s*\/\s*(\d+)/', $raw, $m ) )
		{
			$w = (int) $m[1]; $n = (int) $m[2]; $d = (int) $m[3];
			return static::$oalCache[ $raw ] = ( $d > 0 ? $w + ( $n / $d ) : (float) $w );
		}
		if ( preg_match( '/(\d+(?:\.\d+)?)/', $raw, $m ) )
		{
			return static::$oalCache[ $raw ] = (float) $m[1];
		}
		return static::$oalCache[ $raw ] = null;
	}

	/**
	 * Feature hits from the thin data we have. Returns a list like
	 * ['folding/telescoping stock', 'pistol grip / AR platform', ...].
	 * This is a FLOOR — we can't detect every statutory feature (no
	 * structured field for flash suppressor, threaded barrel, etc.),
	 * so real feature count may be higher.
	 *
	 * @return string[]
	 */
	public static function detectFeatures( array $product ): array
	{
		/* Per-product memoization (v1.6.4). detectFeatures runs 6 regex
		   passes over ~3KB of description text and is the dominant per-
		   row cost. Before memoization it ran once per (product, state)
		   — a rifle in 10 AWB states cost 10× the work. */
		$upc = (string) ( $product['upc'] ?? '' );
		if ( $upc !== '' && isset( static::$featuresCache[ $upc ] ) )
		{
			return static::$featuresCache[ $upc ];
		}

		$hits = [];
		$title = (string) ( $product['title'] ?? '' );
		$desc  = substr( (string) ( $product['description'] ?? '' ), 0, 3000 );
		$stock = strtolower( trim( (string) ( $product['stock_material'] ?? '' ) ) );
		$stkty = strtolower( trim( (string) ( $product['stock_type']     ?? '' ) ) );
		$hay   = strtolower( $title . ' ' . $desc );

		/* Folding / telescoping / collapsible stock. */
		if ( $stock === 'folding' || strpos( $stock, 'fold' ) !== false
			|| strpos( $stkty, 'fold' ) !== false
			|| strpos( $stkty, 'telescop' ) !== false
			|| strpos( $stkty, 'collaps' ) !== false
			|| preg_match( '/\b(fold(ing|able|er)?|collaps(ible|ing)?|telescop(ing|ic)|adjustable stock)\b/i', $hay ) )
		{
			$hits[] = 'folding/telescoping stock';
		}

		/* Thumbhole stock. */
		if ( strpos( $stkty, 'thumbhole' ) !== false || preg_match( '/\bthumbhole\b/i', $hay ) )
		{
			$hits[] = 'thumbhole stock';
		}

		/* Pistol grip / protruding grip. Weak proxy: AR/AK/MSR platforms
		   always ship with a pistol grip. Explicit "pistol grip" text
		   also counts. */
		if ( preg_match( '/\b(pistol grip|protrud(ing|ed) grip|ar[- ]?15|ar[- ]?10|ak[- ]?47|ak[- ]?74|akm|scar|tavor|galil|msr|m4|m16)\b/i', $hay ) )
		{
			$hits[] = 'pistol grip / AR/AK platform';
		}

		/* Flash suppressor / muzzle device / threaded barrel. */
		if ( preg_match( '/\b(flash (hider|suppress(or|ion))|muzzle (brake|device|comp(ensator)?)|threaded (barrel|muzzle)|a2 (comp|flash)|birdcage)\b/i', $hay ) )
		{
			$hits[] = 'flash suppressor / muzzle device / threaded barrel';
		}

		/* Forward grip / vertical grip. */
		if ( preg_match( '/\b(forward grip|foregrip|vertical grip|angled foregrip)\b/i', $hay ) )
		{
			$hits[] = 'forward / vertical grip';
		}

		/* Grenade launcher lugs / barrel shroud — rare in catalog data
		   but harmless to check. */
		if ( preg_match( '/\b(grenade launcher|barrel shroud|shrouded barrel)\b/i', $hay ) )
		{
			$hits[] = 'barrel shroud / grenade launcher';
		}

		$out = array_values( array_unique( $hits ) );
		if ( $upc !== '' )
		{
			if ( count( static::$featuresCache ) >= 8000 ) { static::$featuresCache = []; }
			static::$featuresCache[ $upc ] = $out;
		}
		return $out;
	}

	/**
	 * Pull the awb_rules row for (state, class). Returns null if the
	 * state has no rule row for that class (→ AWB doesn't apply).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function ruleFor( string $stateCode, string $firearmClass ): ?array
	{
		if ( static::$ruleCache === null )
		{
			static::$ruleCache = [];
			try
			{
				foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_awb_rules', [ 'enabled=?', 1 ] ) as $row )
				{
					$s = strtoupper( (string) ( $row['state_code'] ?? '' ) );
					$c = strtolower( (string) ( $row['firearm_class'] ?? '' ) );
					if ( $s === '' || $c === '' ) { continue; }

					/* Date gate: skip rules whose effective_date is in the future,
					   or whose expires_date has passed. */
					$today = date( 'Y-m-d' );
					$eff   = (string) ( $row['effective_date'] ?? '' );
					$exp   = (string) ( $row['expires_date']   ?? '' );
					if ( $eff !== '' && $eff > $today ) { continue; }
					if ( $exp !== '' && $exp < $today ) { continue; }

					static::$ruleCache[ $s ][ $c ] = $row;
				}
			}
			catch ( \Throwable ) {}
		}

		return static::$ruleCache[ strtoupper( $stateCode ) ][ strtolower( $firearmClass ) ] ?? null;
	}

	/**
	 * States (uppercased) that currently have an ENABLED AWB rule for
	 * the given firearm class.
	 *
	 * @return string[]
	 */
	public static function enabledStates( string $firearmClass = 'rifle' ): array
	{
		/* Force cache warm. */
		self::ruleFor( 'IL', $firearmClass );

		$out = [];
		foreach ( (array) static::$ruleCache as $s => $classes )
		{
			if ( isset( $classes[ strtolower( $firearmClass ) ] ) )
			{
				$out[] = $s;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Load enabled patterns for a state (Tier-1 named list). Cached.
	 * @return array<int, array{pattern:string,pattern_norm:string,platform_group:string,citation:string}>
	 */
	protected static function loadPatterns( string $stateCode ): array
	{
		$stateCode = strtoupper( $stateCode );
		if ( isset( static::$modelCache[ $stateCode ] ) ) { return static::$modelCache[ $stateCode ]; }

		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'pattern, pattern_norm, platform_group, citation',
				'gd_compliance_awb_models',
				[ 'state_code=? AND enabled=1', $stateCode ]
			) as $row )
			{
				$norm = (string) ( $row['pattern_norm'] ?? '' );
				if ( strlen( $norm ) < 3 ) { continue; }
				$out[] = [
					'pattern'        => (string) ( $row['pattern'] ?? '' ),
					'pattern_norm'   => $norm,
					'platform_group' => (string) ( $row['platform_group'] ?? '' ),
					'citation'       => (string) ( $row['citation'] ?? '' ),
				];
			}
		}
		catch ( \Throwable ) {}

		return static::$modelCache[ $stateCode ] = $out;
	}

	/**
	 * Run the AWB test for a product against ONE state's config.
	 * The Engine calls this in a loop over enabledStates('rifle') for
	 * rifles and enabledStates('pistol') for handguns.
	 *
	 * Caller must have already gated on:
	 *   - firearm type = 'rifle' / 'handgun' (buildTypeMap) matching
	 *     $firearmClass
	 *   - action_type contains 'semi'
	 * This method itself handles the centerfire exclusion and rule lookup.
	 *
	 * @return array{tier:int,pattern:?string,citation:string,feature_hits:array<int,string>}|null
	 */
	public static function match( array $product, string $stateCode, string $firearmClass = 'rifle' ): ?array
	{
		$rule = self::ruleFor( $stateCode, $firearmClass );
		if ( $rule === null ) { return null; }

		/* Centerfire-only exclusion (fixes IL .22LR regression). */
		$centerfireOnly = (int) ( $rule['centerfire_only'] ?? 1 ) === 1;
		if ( $centerfireOnly && ! self::isCenterfire( $product ) )
		{
			return null;
		}

		$citation = trim( (string) ( $rule['citation'] ?? '' ) );

		/* ---- Tier 1: named model list ---- */
		$parts = [
			(string) ( $product['title']        ?? '' ),
			(string) ( $product['brand']        ?? '' ),
			(string) ( $product['manufacturer'] ?? '' ),
			(string) ( $product['model']        ?? '' ),
			substr( (string) ( $product['description'] ?? '' ), 0, 2000 ),
		];
		$text = self::normalize( implode( ' ', $parts ) );

		if ( $text !== '' )
		{
			foreach ( self::loadPatterns( $stateCode ) as $p )
			{
				if ( strpos( $text, $p['pattern_norm'] ) !== false )
				{
					return [
						'tier'         => 1,
						'pattern'      => $p['pattern'],
						'citation'     => $p['citation'] !== '' ? $p['citation'] : $citation,
						'feature_hits' => [],
					];
				}
			}
		}

		/* ---- CA extra: overall_length < max_overall_length_in ---- */
		$maxOal = $rule['max_overall_length_in'];
		if ( $maxOal !== null )
		{
			$oal = self::parseOverallLengthIn( (string) ( $product['overall_length'] ?? '' ) );
			if ( $oal !== null && $oal < (float) $maxOal )
			{
				return [
					'tier'         => 2,
					'pattern'      => null,
					'citation'     => $citation,
					'feature_hits' => [ sprintf( 'overall length %.2f\" < %s\" (state rule)', $oal, (string) $maxOal ) ],
				];
			}
		}

		/* ---- Feature test ---- */
		$threshold = max( 1, (int) ( $rule['feature_count_threshold'] ?? 1 ) );
		$features  = self::detectFeatures( $product );
		$count     = count( $features );

		if ( $count >= $threshold )
		{
			return [
				'tier'         => 2,
				'pattern'      => null,
				'citation'     => $citation,
				'feature_hits' => $features,
			];
		}

		/* Semi-auto centerfire rifle that DIDN'T meet our detected feature
		   count — but we know our detection is incomplete. Route to
		   review so Derrick can verify (tier 3 = LOW-confidence review). */
		return [
			'tier'         => 3,
			'pattern'      => null,
			'citation'     => $citation,
			'feature_hits' => $features,
		];
	}

	/**
	 * Seed the per-state named-model lists. Called from install +
	 * upg_10600. Non-destructive — inserts only pairs (state, pattern_norm)
	 * that don't already exist. Existing rows preserved.
	 *
	 * @return array{inserted:int, skipped:int, failed:int}
	 */
	public static function seedMissingModels(): array
	{
		$counts = [ 'inserted' => 0, 'skipped' => 0, 'failed' => 0 ];
		$now    = time();

		foreach ( self::statutorySeed() as $rule )
		{
			$pattern = trim( (string) ( $rule['pattern'] ?? '' ) );
			$state   = strtoupper( trim( (string) ( $rule['state_code'] ?? '' ) ) );
			if ( $pattern === '' || $state === '' ) { $counts['failed']++; continue; }

			$norm = self::normalize( $pattern );
			if ( $norm === '' || strlen( $norm ) < 3 ) { $counts['failed']++; continue; }

			try
			{
				$exists = (int) \IPS\Db::i()->select(
					'COUNT(*)',
					'gd_compliance_awb_models',
					[ 'state_code=? AND pattern_norm=?', $state, $norm ]
				)->first();
				if ( $exists > 0 ) { $counts['skipped']++; continue; }

				\IPS\Db::i()->insert( 'gd_compliance_awb_models', [
					'state_code'     => $state,
					'pattern'        => substr( $pattern, 0, 120 ),
					'pattern_norm'   => substr( $norm, 0, 120 ),
					'platform_group' => substr( (string) ( $rule['platform_group'] ?? '' ), 0, 40 ),
					'citation'       => substr( (string) ( $rule['citation'] ?? '' ), 0, 255 ),
					'enabled'        => (int) ( $rule['enabled'] ?? 1 ),
					'updated_at'     => $now,
				] );
				$counts['inserted']++;
			}
			catch ( \Throwable $e )
			{
				$counts['failed']++;
				try { \IPS\Log::log( 'AwbModels::seed ' . $state . '/' . $pattern . ': ' . $e->getMessage(), 'gdcompliance_seed' ); } catch ( \Throwable ) {}
			}
		}

		try { self::$modelCache = []; } catch ( \Throwable ) {}
		return $counts;
	}

	/**
	 * Non-destructive per-state feature-test config seed. Existing rows
	 * (including any Derrick edits) are preserved.
	 *
	 * @return array{inserted:int, skipped:int, failed:int}
	 */
	public static function seedMissingRules(): array
	{
		$counts = [ 'inserted' => 0, 'skipped' => 0, 'failed' => 0 ];
		$now    = time();

		foreach ( self::rulesetSeed() as $rule )
		{
			$state = strtoupper( trim( (string) ( $rule['state_code']    ?? '' ) ) );
			$class = strtolower( trim( (string) ( $rule['firearm_class'] ?? '' ) ) );
			if ( $state === '' || $class === '' ) { $counts['failed']++; continue; }

			try
			{
				$exists = (int) \IPS\Db::i()->select(
					'COUNT(*)',
					'gd_compliance_awb_rules',
					[ 'state_code=? AND firearm_class=?', $state, $class ]
				)->first();
				if ( $exists > 0 ) { $counts['skipped']++; continue; }

				\IPS\Db::i()->insert( 'gd_compliance_awb_rules', $rule + [ 'updated_at' => $now ] );
				$counts['inserted']++;
			}
			catch ( \Throwable $e )
			{
				$counts['failed']++;
				try { \IPS\Log::log( 'AwbModels::seedRule ' . $state . '/' . $class . ': ' . $e->getMessage(), 'gdcompliance_seed' ); } catch ( \Throwable ) {}
			}
		}

		try { self::$ruleCache = null; } catch ( \Throwable ) {}
		return $counts;
	}

	/**
	 * Per-state AWB feature-test config (mid-2026 verified). Each row is
	 * ONE (state, firearm_class) pair — that's the idempotency key.
	 *
	 * State thresholds (1 = one-feature, 2 = two-feature) are the
	 * research-verified statutory current for each state.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rulesetSeed(): array
	{
		$mkRifle = fn ( string $state, int $thresh, string $cite, ?string $eff = null, int $enabled = 1, ?float $maxOal = null, ?string $notes = null ) => [
			'state_code'              => $state,
			'firearm_class'           => 'rifle',
			'feature_count_threshold' => $thresh,
			'centerfire_only'         => 1,
			'max_overall_length_in'   => $maxOal,
			'min_capacity_fixed'      => 10,
			'citation'                => substr( $cite, 0, 255 ),
			'effective_date'          => $eff,
			'expires_date'            => null,
			'enabled'                 => $enabled,
			'notes'                   => $notes ? substr( $notes, 0, 255 ) : null,
		];

		$mkPistol = fn ( string $state, int $thresh, string $cite, ?string $eff = null, int $enabled = 1, ?string $notes = null ) => [
			'state_code'              => $state,
			'firearm_class'           => 'pistol',
			'feature_count_threshold' => $thresh,
			'centerfire_only'         => 1,
			'max_overall_length_in'   => null,
			'min_capacity_fixed'      => 10,
			'citation'                => substr( $cite, 0, 255 ),
			'effective_date'          => $eff,
			'expires_date'            => null,
			'enabled'                 => $enabled,
			'notes'                   => $notes ? substr( $notes, 0, 255 ) : null,
		];

		return [
			/* --- ENABLED rifle AWB states (v1.6.1 activations) --- */
			$mkRifle( 'IL', 1, '720 ILCS 5/24-1.9(a)(1)(A)', null, 1, null, 'PICA one-feature test; rimfire exempt' ),
			$mkRifle( 'CA', 1, 'CA Pen Code §30510/§30515', null, 1, 30.0, 'One-feature; also <30 in OAL rule (Pen 30515)' ),
			$mkRifle( 'NY', 1, 'NY Penal §265.00(22) (SAFE Act)', null, 1, null, 'One-feature since SAFE Act (2013 as amended)' ),
			$mkRifle( 'NJ', 1, 'N.J.S.A. 2C:39-1(w)', null, 1, null, 'One-feature since S2309 amendments; thumbhole + second handgrip added' ),
			$mkRifle( 'WA', 1, 'RCW 9.41.010 (HB 1240, 2023)', null, 1, null, 'One-feature sale/transfer/manufacture ban' ),
			$mkRifle( 'DE', 1, '11 Del. C. §1466 (HB 450, 2022)', null, 1, null, 'Delaware Lethal Firearms Safety Act one-feature' ),
			$mkRifle( 'MD', 2, 'MD Crim Law §4-301 (regulated firearm list)', null, 1, null, 'Two-feature test + enumerated regulated-firearm list' ),
			$mkRifle( 'MA', 2, 'MGL c.140 §121 (Ch. 135 of Acts of 2024)', null, 1, null, 'Two-feature statutory; MA AG interpretation may be broader — verify' ),
			$mkRifle( 'DC', 1, 'DC Code §7-2501.01(3A)', null, 1, null, 'One-feature; Benson injunction flux — Derrick may need to disable if enforcement changes' ),
			$mkRifle( 'RI', 1, 'RI S 359 (2025)', '2026-07-01', 1, null, 'Effective 2026-07-01 sale/transfer only; auto-activates by date' ),

			/* --- HELD PENDING VERIFICATION --- */
			$mkRifle( 'CT', 1, 'CT Gen Stat §53-202a', null, 0, null, 'CT threshold needs statute verification (2023 amendment) before enabling — sources conflict on one- vs two-feature' ),
			$mkRifle( 'VA', 1, 'VA SB 749 (2025)', '2026-07-01', 0, null, 'Effective 2026-07-01 BUT 4 lawsuits + non-enforcement statements; seeded disabled — Derrick toggles when settled' ),

			/* --- PISTOL-ONLY AWB (HI) --- */
			$mkPistol( 'HI', 1, 'HRS §134-1 (assault pistol definition)', null, 1, 'HI bans assault pistols only, not rifles; framework only evaluates pistols against this row' ),
		];
	}

	/**
	 * The statutory named-model list, tagged by state. Overlap between
	 * states is intentional — a rifle named in both CA's and IL's list
	 * gets ONE row per state.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function statutorySeed(): array
	{
		$rows = [];

		/* IL PICA (a)(1)(J) — carried forward from PicaModels v1.5.2. */
		$il = '720 ILCS 5/24-1.9(a)(1)(J)';
		$ca = 'CA Pen Code §30510/§30515';
		$ny = 'NY Penal §265.00(22)';

		$add = function ( string $state, string $cite, string $pat, string $group ) use ( &$rows ) {
			$rows[] = [
				'state_code'     => $state,
				'citation'       => $cite,
				'pattern'        => $pat,
				'platform_group' => $group,
				'enabled'        => 1,
			];
		};

		/* AR/AK/named list — replicated across the three enabled AWB
		   states. IL's original v1.5.2 pattern set carries forward
		   verbatim; CA and NY inherit the same core list since their
		   copycat clauses cover functionally equivalent rifles. */
		$corePatterns = [
			/* AK-pattern rifles */
			[ 'AK47', 'AK' ], [ 'AK-47', 'AK' ], [ 'AK47S', 'AK' ], [ 'AK-74', 'AK' ], [ 'AKM', 'AK' ], [ 'AKS', 'AK' ],
			[ 'MAK90', 'AK' ], [ 'MISR', 'AK' ], [ 'NHM90', 'AK' ], [ 'NHM91', 'AK' ],
			[ 'SA85', 'AK' ], [ 'SA93', 'AK' ], [ 'VEPR', 'AK' ], [ 'WASR-10', 'AK' ], [ 'WASR10', 'AK' ], [ 'WUM', 'AK' ],
			[ 'Saiga AK', 'AK' ], [ 'MAADI', 'AK' ],
			[ 'Norinco 56S', 'AK' ], [ 'Norinco 84S', 'AK' ], [ 'Norinco 86S', 'AK' ], [ 'Poly Tech AK', 'AK' ],
			[ 'SKS Detachable', 'SKS' ],

			/* AR-15 family + named copies */
			[ 'AR-10', 'AR-15' ], [ 'AR10', 'AR-15' ], [ 'AR-15', 'AR-15' ], [ 'AR15', 'AR-15' ],
			[ 'Alexander Arms Overmatch', 'AR-15' ], [ 'Armalite M15', 'AR-15' ], [ 'Barrett REC7', 'AR-15' ],
			[ 'Beretta AR-70', 'AR-15' ], [ 'Beretta AR70', 'AR-15' ], [ 'Black Rain Recon Scout', 'AR-15' ],
			[ 'Bushmaster ACR', 'AR-15' ], [ 'Bushmaster Carbon 15', 'AR-15' ], [ 'Bushmaster MOE', 'AR-15' ], [ 'Bushmaster XM15', 'AR-15' ],
			[ 'Chiappa MFour', 'AR-15' ], [ 'Colt Match Target', 'AR-15' ],
			[ 'CORE15', 'AR-15' ], [ 'CORE 15', 'AR-15' ],
			[ 'Daniel Defense M4A1', 'AR-15' ], [ 'Devil Dog 15', 'AR-15' ], [ 'Diamondback DB15', 'AR-15' ],
			[ 'DoubleStar AR', 'AR-15' ], [ 'DPMS Tactical', 'AR-15' ], [ 'DSA ZM-4', 'AR-15' ], [ 'DSA ZM4', 'AR-15' ],
			[ 'HK MR556', 'AR-15' ], [ 'HK-MR556', 'AR-15' ], [ 'High Standard HSA-15', 'AR-15' ], [ 'HSA-15', 'AR-15' ],
			[ 'Jesse James Nomad', 'AR-15' ], [ "Knight's SR-15", 'AR-15' ], [ 'SR-15', 'AR-15' ], [ 'SR15', 'AR-15' ],
			[ 'Lancer L15', 'AR-15' ], [ 'MGI Hydra', 'AR-15' ], [ 'Mossberg MMR Tactical', 'AR-15' ],
			[ 'Noreen BN36', 'AR-15' ], [ 'Olympic Arms', 'AR-15' ], [ 'POF P415', 'AR-15' ], [ 'Precision Firearms AR', 'AR-15' ],
			[ 'Remington R-15', 'AR-15' ], [ 'Remington R15', 'AR-15' ], [ 'Rhino Arms AR', 'AR-15' ],
			[ 'Rock River LAR-15', 'AR-15' ], [ 'Rock River LAR-47', 'AR-15' ], [ 'LAR-15', 'AR-15' ], [ 'LAR-47', 'AR-15' ],
			[ 'SIG SIG516', 'AR-15' ], [ 'SIG 516', 'AR-15' ], [ 'SIG516', 'AR-15' ], [ 'SIG MCX', 'AR-15' ], [ 'MCX', 'AR-15' ],
			[ 'Smith & Wesson M&P15', 'AR-15' ], [ 'M&P15', 'AR-15' ], [ 'MP15', 'AR-15' ],
			[ 'Stag Arms AR', 'AR-15' ], [ 'Ruger SR556', 'AR-15' ], [ 'SR556', 'AR-15' ], [ 'SR-556', 'AR-15' ],
			[ 'Ruger AR-556', 'AR-15' ], [ 'AR-556', 'AR-15' ], [ 'AR556', 'AR-15' ],
			[ 'Uselton Air-Lite M-4', 'AR-15' ], [ 'Windham Weaponry AR', 'AR-15' ], [ 'WMD Big Beast', 'AR-15' ],
			[ 'YHM-15', 'AR-15' ], [ 'YHM15', 'AR-15' ],

			/* .50 BMG */
			[ 'Barrett M107A1', '.50 BMG' ], [ 'M107A1', '.50 BMG' ],
			[ 'Barrett M82A1', '.50 BMG' ], [ 'M82A1', '.50 BMG' ],

			/* Other named rifles */
			[ 'Beretta CX4 Storm', 'Other Named' ], [ 'CX4 Storm', 'Other Named' ], [ 'Calico Liberty', 'Other Named' ],
			[ 'CETME Sporter', 'Other Named' ],
			[ 'Daewoo K1', 'Other Named' ], [ 'Daewoo K2', 'Other Named' ], [ 'Daewoo Max', 'Other Named' ], [ 'Daewoo AR100', 'Other Named' ], [ 'Daewoo AR110C', 'Other Named' ],
			[ 'FN FAL', 'Other Named' ], [ 'FN LAR', 'Other Named' ], [ 'FN FNC', 'Other Named' ], [ 'FN L1A1', 'Other Named' ], [ 'FN PS90', 'Other Named' ], [ 'FN SCAR', 'Other Named' ], [ 'FN FS2000', 'Other Named' ],
			[ 'SCAR', 'Other Named' ], [ 'PS90', 'Other Named' ], [ 'FS2000', 'Other Named' ],
			[ 'Feather AT-9', 'Other Named' ], [ 'Galil AR', 'Other Named' ], [ 'Galil ARM', 'Other Named' ],
			[ 'Hi-Point Carbine', 'Other Named' ],
			[ 'HK-91', 'Other Named' ], [ 'HK 91', 'Other Named' ], [ 'HK-93', 'Other Named' ], [ 'HK 93', 'Other Named' ], [ 'HK-94', 'Other Named' ], [ 'HK 94', 'Other Named' ], [ 'HK-PSG-1', 'Other Named' ], [ 'PSG-1', 'Other Named' ], [ 'HK USC', 'Other Named' ],
			[ 'IWI Tavor', 'Other Named' ], [ 'Tavor', 'Other Named' ], [ 'IWI Galil ACE', 'Other Named' ], [ 'Galil ACE', 'Other Named' ],
			[ 'Kel-Tec Sub-2000', 'Other Named' ], [ 'Sub-2000', 'Other Named' ], [ 'Kel-Tec SU-16', 'Other Named' ], [ 'SU-16', 'Other Named' ], [ 'Kel-Tec RFB', 'Other Named' ], [ 'RFB', 'Other Named' ],
			[ 'SIG AMT', 'Other Named' ], [ 'SIG PE-57', 'Other Named' ], [ 'SIG SG550', 'Other Named' ], [ 'SG550', 'Other Named' ], [ 'SIG SG551', 'Other Named' ], [ 'SG551', 'Other Named' ],
			[ 'Springfield SAR-48', 'Other Named' ], [ 'SAR-48', 'Other Named' ],
			[ 'Steyr AUG', 'Other Named' ],
			[ 'Ruger Mini-14 Tactical', 'Other Named' ], [ 'Mini-14 Tactical', 'Other Named' ], [ 'Mini-14/20CF', 'Other Named' ], [ 'M-14/20CF', 'Other Named' ],
			[ 'Thompson', 'Other Named' ],
			[ 'UMAREX UZI', 'Other Named' ], [ 'UZI Carbine', 'Other Named' ], [ 'Vector UZI', 'Other Named' ],
			[ 'Valmet M62S', 'Other Named' ], [ 'Valmet M71S', 'Other Named' ], [ 'Valmet M78', 'Other Named' ],
			[ 'Weaver Nighthawk', 'Other Named' ], [ 'Wilkinson Linda', 'Other Named' ],
		];

		/* Replicate the shared AR/AK/named core across EVERY enabled AWB
		   rifle-state. Every state's copycat clause covers functionally
		   equivalent rifles, so the core patterns are common. Per-state
		   additions can be layered on via ACP or later prompts. */
		$stateCites = [
			'IL' => $il,
			'CA' => $ca,
			'NY' => $ny,
			'NJ' => 'N.J.S.A. 2C:39-1(w)',
			'WA' => 'RCW 9.41.010 (HB 1240, 2023)',
			'DE' => '11 Del. C. §1466 (HB 450, 2022)',
			'MD' => 'MD Crim Law §4-301',
			'MA' => 'MGL c.140 §121',
			'DC' => 'DC Code §7-2501.01(3A)',
			'RI' => 'RI S 359 (2025)',
			/* CT + VA get seeded so that a future toggle-to-enabled doesn't
			   require another prompt to seed patterns. */
			'CT' => 'CT Gen Stat §53-202a',
			'VA' => 'VA SB 749 (2025)',
		];
		foreach ( $stateCites as $state => $cite )
		{
			foreach ( $corePatterns as [ $pat, $group ] )
			{
				$add( $state, $cite, $pat, $group );
			}
		}

		return $rows;
	}

	/**
	 * Reset per-request caches (called from install/upgrade + rule/model edits).
	 */
	public static function clearCache(): void
	{
		static::$modelCache      = [];
		static::$ruleCache       = null;
		static::$centerfireCache = [];
		static::$featuresCache   = [];
		static::$oalCache        = [];
	}
}

class AwbModels extends _AwbModels {}
