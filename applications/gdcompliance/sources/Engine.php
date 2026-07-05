<?php
/**
 * @brief  GD Compliance — Engine
 *
 * Reads gd_catalog + gd_categories READ-ONLY; writes only to the
 * gd_compliance_* tables this app owns.
 *
 *  - buildTypeMap()   walks gd_categories from each row's category_id up
 *                     parent_id to the top-level (id 1 Handguns, 7 Rifles,
 *                     16 Shotguns) and caches the mapping per run.
 *  - parseCapacity()  extracts the leading integer from gd_catalog.capacity
 *                     ("15+1" → 15, "10rd" → 10, "6" → 6, "4- 2.75\"" → 4).
 *  - computeFlags()   streams firearm products, applies every ACTIVE rule
 *                     (enabled + effective/expires window), writes
 *                     gd_compliance_flags. dryRun=true returns a preview
 *                     payload (counts + sample rows + unparsed tally)
 *                     and skips all writes.
 *
 * Disabling this app removes flag display with zero impact on gd_catalog.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Engine
{
	/* Category top-level → firearm type. Anything else is non-firearm and
	   gets skipped (ammo, accessories, holsters, optics, etc.). */
	const TOP_LEVEL_TYPES = [
		1  => 'handgun',
		7  => 'rifle',
		16 => 'shotgun',
	];

	const VALID_TYPES = [ 'handgun', 'rifle', 'shotgun', 'all' ];

	/* Sample size returned by dryRun previews (per state). */
	const SAMPLE_LIMIT = 50;

	/**
	 * Build a complete category_id → firearm_type map by walking parent_id
	 * up to the top-level. Built once per run. Categories that don't roll
	 * up to a known firearm top-level (1/7/16) map to NULL → skip.
	 *
	 * @return array<int, string|null>
	 */
	public static function buildTypeMap(): array
	{
		$out      = [];
		$parents  = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'id, parent_id', 'gd_categories' ) as $row )
			{
				$parents[ (int) $row['id'] ] = (int) ( $row['parent_id'] ?? 0 );
			}
		}
		catch ( \Throwable ) { return $out; }

		foreach ( array_keys( $parents ) as $catId )
		{
			$id      = (int) $catId;
			$visited = [];
			$top     = $id;
			while ( $id > 0 && !isset( $visited[ $id ] ) )
			{
				$visited[ $id ] = true;
				$top            = $id;
				$id             = (int) ( $parents[ $id ] ?? 0 );
			}
			$out[ (int) $catId ] = self::TOP_LEVEL_TYPES[ $top ] ?? null;
		}

		return $out;
	}

	/**
	 * Parse a gd_catalog.capacity string → integer magazine count.
	 *
	 *   "15+1"           → 15  (the +1 chambered round doesn't count toward mag limits)
	 *   "10+1"           → 10
	 *   "10rd"           → 10
	 *   "30 round"       → 30
	 *   "6"              → 6
	 *   "4- 2.75\" Shells" → 4 (shotgun shells: leading int)
	 *
	 * Returns null for unparseable / empty values so the caller can log
	 * them and skip the product cleanly.
	 */
	public static function parseCapacity( ?string $cap ): ?int
	{
		if ( $cap === null ) { return null; }
		$cap = trim( $cap );
		if ( $cap === '' ) { return null; }
		if ( preg_match( '/^\s*(\d+)/', $cap, $m ) )
		{
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * v1.6.10 — classify a bare magazine's firearm class from its
	 * `caliber` (and title as a fallback). Returns 'shotgun' | 'pistol'
	 * | 'rifle' | 'ambiguous'. Order matters: shotgun gauges are
	 * checked FIRST because a plain "12" would otherwise fail every
	 * later test. Ambiguous → the caller picks the higher of rifle/
	 * handgun limits so we never falsely trip on a state's shotgun
	 * limit (fixes the v1.6.9 IL-5 bug).
	 */
	public static function classifyMagClass( string $caliber, string $title = '' ): string
	{
		$cal = strtolower( trim( $caliber ) );
		$ttl = strtolower( trim( $title ) );

		/* Shotgun gauges (never in the rifle/pistol families) — must be
		   surrounded by a non-digit or line boundary to keep "12ga" and
		   ".410" apart from "1.223" and "410 s&w"-style noise. */
		$shotgunTokens = [ '12ga', '20ga', '410', '410ga', '16ga', '28ga', '10ga',
			'12 gauge', '20 gauge', '410 bore', '16 gauge', '28 gauge', '10 gauge',
			'.410', '.410 bore' ];
		foreach ( $shotgunTokens as $t )
		{
			if ( ( $cal !== '' && strpos( $cal, $t ) !== false )
			  || ( $ttl !== '' && strpos( $ttl, $t ) !== false ) )
			{
				return 'shotgun';
			}
		}

		/* Handgun cartridges (typed by a compact set that covers 95% of
		   pistol mags on the market). '.38' would false-positive against
		   '.380' if bare, so the '.38' entries include a specific space
		   suffix or 'spec'/'special' anchor. */
		$handgunTokens = [
			'9mm', '9 mm', '9x19', '9 x 19', 'luger',
			'.40 s&w', '40 s&w', '.40sw', '40sw', '10mm',
			'.45 acp', '45 acp', '.45acp', '45acp', '.45 gap', '45 gap',
			'.380', '380 acp', '.380 acp', '.380acp',
			'.357 sig', '357 sig', '.357sig',
			'.38 special', '38 special', '.38 spl', '38 spl',
			'.32 acp', '32 acp',
			'.25 acp', '25 acp',
			'.44 mag', '44 mag', '.44 magnum', '44 magnum',
			'.500 s&w', '500 s&w',
			'.454 casull', '454 casull',
		];
		foreach ( $handgunTokens as $t )
		{
			if ( ( $cal !== '' && strpos( $cal, $t ) !== false )
			  || ( $ttl !== '' && strpos( $ttl, $t ) !== false ) )
			{
				return 'handgun';
			}
		}

		/* Title fallback for pistol/handgun mags without a caliber field. */
		if ( strpos( $ttl, 'pistol mag' ) !== false
		  || strpos( $ttl, 'handgun mag' ) !== false
		  || strpos( $ttl, 'pistol magazine' ) !== false )
		{
			return 'handgun';
		}

		/* Common centerfire rifle cartridges. */
		$rifleTokens = [
			'5.56', '5.56x45', '5.56 nato',
			'.223', '.223 rem', '223 rem',
			'.308', '.308 win', '308 win',
			'7.62', '7.62x39', '7.62x51', '7.62 nato',
			'6.5', '6.5 creedmoor', '6.5 grendel', '6.5 prc',
			'.300', '300 blk', '300 blackout', '.300blk', '.300 wsm', '.300 win',
			'.224', '224 valkyrie',
			'6.8', '6.8 spc',
			'.458', '458 socom',
			'.350', '350 legend',
			'.30-06', '30-06', '.30 06',
			'.30-30', '30-30',
			'.243', '243 win',
			'.270', '270 win',
			'.22-250', '22-250',
			'.204', '204 ruger',
			'.17 remington', '17 rem',
		];
		foreach ( $rifleTokens as $t )
		{
			if ( ( $cal !== '' && strpos( $cal, $t ) !== false )
			  || ( $ttl !== '' && strpos( $ttl, $t ) !== false ) )
			{
				return 'rifle';
			}
		}

		/* Title fallback for rifle mags. */
		if ( strpos( $ttl, 'ar-15' ) !== false || strpos( $ttl, 'ar15' ) !== false
		  || strpos( $ttl, 'ar-10' ) !== false || strpos( $ttl, 'ar10' ) !== false
		  || strpos( $ttl, 'ak-47' ) !== false || strpos( $ttl, 'ak47' ) !== false
		  || strpos( $ttl, 'rifle mag' ) !== false )
		{
			return 'rifle';
		}

		return 'ambiguous';
	}

	/**
	 * Pull every ACTIVE rule from gd_compliance_rules:
	 *   enabled=1
	 *   AND (effective_date IS NULL OR effective_date <= today)
	 *   AND (expires_date   IS NULL OR expires_date   >= today)
	 *
	 * @return array<int, array{id:int,state_code:string,firearm_type:string,max_capacity:int,rule_type:string,source_note:?string}>
	 */
	public static function activeRules( ?string $today = null ): array
	{
		$today = $today ?: date( 'Y-m-d' );
		$rules = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_rules',
				[ 'enabled=1 AND (effective_date IS NULL OR effective_date<=?) AND (expires_date IS NULL OR expires_date>=?)', $today, $today ]
			) as $r )
			{
				$rules[] = $r;
			}
		}
		catch ( \Throwable ) {}
		return $rules;
	}

	/**
	 * Compute flags from gd_catalog × gd_compliance_rules.
	 *
	 * dryRun=true   → no writes; returns full preview (per-state counts,
	 *                 sample flagged rows, unparsed-capacity tally).
	 * dryRun=false  → clears gd_compliance_flags + gd_compliance_unparsed,
	 *                 writes the new flag set, then mirrors the same return
	 *                 payload for the ACP done-screen.
	 *
	 * @return array{
	 *   processed:int,
	 *   firearms:int,
	 *   flags:int,
	 *   per_state:array<string,int>,
	 *   per_state_type:array<string,array<string,int>>,
	 *   unparsed:array<string,int>,
	 *   sample:array<int,array<string,mixed>>,
	 *   dry_run:bool
	 * }
	 */
	public static function computeFlags( bool $dryRun = false ): array
	{
		/* v1.6.4: raise limits before the 58k-row scan begins.
		   set_time_limit(0) lifts the PHP web-request cap so the ACP
		   "Run compute" button stops dying at 30s (root cause of the
		   "Maximum execution time" error in Db.php). ignore_user_abort
		   keeps compute going if the admin's browser drops the request.
		   memory_limit bumped for the in-memory flag stage. Every call
		   is @-prefixed so a locked-down PHP configuration doesn't
		   throw — the scan will just run within whatever limits apply. */
		@set_time_limit( 0 );
		@ignore_user_abort( true );
		@ini_set( 'memory_limit', '512M' );

		/* v1.6.6 PERF ROOT CAUSE: the compute accumulates very large
		   forward-only arrays that all stay alive for the whole loop —
		   $flags (→ ~32k rows), $result['review_queue'] (→ ~11.7k rows),
		   and the buffered 58k-row catalog result (with descriptions) held
		   in memory simultaneously. PHP's cycle-collecting garbage collector
		   fires whenever its root buffer reaches 10,000 roots; with hundreds
		   of thousands of live array zvals building up, every collection
		   cycle re-scans an ever-growing live set, and that cost compounds
		   as the loop advances. This is invisible in every isolated
		   component test (small N → GC never meaningfully fires) and is NOT
		   mitigated by a bigger memory_limit (GC cadence is tied to the
		   root-buffer count, not the memory ceiling — which is exactly why
		   the memory_limit=2G re-run stayed slow). It also explains why the
		   582s appeared the instant the roster pass was fixed to actually
		   run: that is when review_queue + roster flags began accumulating,
		   pushing the live-zval count past the GC threshold. These arrays
		   contain no reference cycles, so cycle collection buys us nothing
		   here. Disable it for the duration and restore the prior state
		   before every return. */
		$gcWasEnabled = \gc_enabled();
		\gc_disable();

		$result = [
			'processed'      => 0,
			'firearms'       => 0,
			'flags'          => 0,
			'row_errors'     => 0,
			'per_state'      => [],
			'per_state_type' => [],
			'unparsed'       => [],
			'sample'         => [],
			'awb'            => [ 'tier1' => 0, 'tier2' => 0, 'review' => 0 ],
			'roster'         => [
				/* Phase 2 — CA roster outcome counts. Surfaced in the ACP
				   preview/run summary so Derrick sees the on/off/review split
				   before committing. */
				'on'     => 0,
				'off'    => 0,
				'review' => 0,
				'skipped_no_roster' => 0,
			],
			'review_queue'   => [],
			'dry_run'        => $dryRun,
			'duration_ms'    => 0,
		];
		$computeStart = microtime( true );

		$typeMap = self::buildTypeMap();
		$rules   = self::activeRules();

		/* Roster check is independent of capacity rules — a handgun can be
		   both off-roster AND over-capacity (multiple flags). Prime the
		   cache once; only states with actual data run the per-handgun
		   pass (Roster::availableStates filters by what's loaded). DC is
		   derived from CA+MA+MD when gdcompliance_dc_derive is on. */
		try { \IPS\gdcompliance\Roster::primeCache(); } catch ( \Throwable ) {}
		$rosterStates = [];
		try { $rosterStates = \IPS\gdcompliance\Roster::availableStates(); } catch ( \Throwable ) {}
		$dcDerive = (int) ( \IPS\Settings::i()->gdcompliance_dc_derive ?? 1 ) === 1;

		/* v1.6.4 preload: enabledStates() walks the ruleCache; result is
		   stable across all rows. Preload once and hand into the loop
		   below via captured locals — saves ~58k redundant function
		   calls per firearm-class. Also warms the AWB rule + model
		   caches so the per-row match() calls are O(1) hash reads. */
		$awbRifleStates  = [];
		$awbPistolStates = [];
		try
		{
			$awbRifleStates  = \IPS\gdcompliance\AwbModels::enabledStates( 'rifle' );
			$awbPistolStates = \IPS\gdcompliance\AwbModels::enabledStates( 'pistol' );
		}
		catch ( \Throwable ) {}
		/* Clear any stale per-product memoization from a prior compute
		   run (e.g. re-running back-to-back in the same request). */
		try { \IPS\gdcompliance\AwbModels::clearCache(); \IPS\gdcompliance\AwbModels::enabledStates( 'rifle' ); } catch ( \Throwable ) {}
		try { $awbRifleStates  = \IPS\gdcompliance\AwbModels::enabledStates( 'rifle' ); }  catch ( \Throwable ) {}
		try { $awbPistolStates = \IPS\gdcompliance\AwbModels::enabledStates( 'pistol' ); } catch ( \Throwable ) {}

		/* v1.6.9 — reset Lowers per-request memo before the sweep. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Lowers.php';
			\IPS\gdcompliance\Lowers::clearCache();
		}
		catch ( \Throwable ) {}

		/* v1.6.17 — reset Advisories per-request memo before the sweep. */
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Advisories.php';
			\IPS\gdcompliance\Advisories::clearCache();
		}
		catch ( \Throwable ) {}

		/* v1.6.19 — reset MeltingPoint per-request memo + preload the
		   enabled per-state rules once per compute. */
		$mpStateRules = [];
		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/MeltingPoint.php';
			\IPS\gdcompliance\MeltingPoint::clearCache();
			$mpStateRules = \IPS\gdcompliance\MeltingPoint::enabledRules();
		}
		catch ( \Throwable ) {}

		/* Initialize per-state counters so the summary always shows them
		   (even with zero counts), even for states without a loaded roster. */
		foreach ( [ 'CA', 'MA', 'MD', 'DC' ] as $s )
		{
			$result['roster'][ $s ] = [ 'on' => 0, 'off' => 0, 'review' => 0 ];
		}

		if ( empty( $rules ) ) {
			/* Even with no capacity rules we can still run the CA roster pass
			   on its own, so don't bail here — just skip the rule loop. */
		}

		/* Group rules by type for fast lookup per product. */
		$rulesByType = [ 'handgun' => [], 'rifle' => [], 'shotgun' => [], 'all' => [] ];
		foreach ( $rules as $r )
		{
			$type = (string) ( $r['firearm_type'] ?? 'all' );
			if ( !isset( $rulesByType[ $type ] ) ) { continue; }
			$rulesByType[ $type ][] = $r;
		}

		/* v1.6.10 — per-state, per-firearm-type magazine ceilings for the
		   bare-magazine (cat38) pass. A standalone LCM needs to compare
		   against the RIGHT state limit for its actual type (rifle mag →
		   rifle limit, pistol mag → handgun limit, shotgun mag → shotgun
		   limit). v1.6.9 used the state's minimum, which produced the IL
		   bug: a 30-rd 5.56 AR mag was compared to IL's shotgun limit
		   (5) instead of the rifle limit (10).

		   Format: $magLimitsByStateType[ $state ][ 'handgun' | 'rifle' |
		   'shotgun' ] = [ 'limit' => int, 'rule_id' => int,
		   'citation' => string ]. 'all'-typed rules cascade into every
		   sub-type. Selecting the ACTUAL limit for the mag's classified
		   class is done at flag-time, not here. */
		$magLimitsByStateType = [];
		foreach ( $rules as $r )
		{
			$state = strtoupper( (string) ( $r['state_code'] ?? '' ) );
			if ( $state === '' ) { continue; }
			$limit = (int) ( $r['max_capacity'] ?? 0 );
			if ( $limit <= 0 ) { continue; }
			$rtype = strtolower( (string) ( $r['firearm_type'] ?? 'all' ) );
			$row   = [
				'limit'    => $limit,
				'rule_id'  => (int) ( $r['id'] ?? 0 ),
				'citation' => (string) ( $r['source_note'] ?? '' ),
			];
			$applyTo = ( $rtype === 'all' ) ? [ 'handgun', 'rifle', 'shotgun' ] : [ $rtype ];
			foreach ( $applyTo as $tt )
			{
				if ( !isset( $magLimitsByStateType[ $state ][ $tt ] )
				  || $limit < $magLimitsByStateType[ $state ][ $tt ]['limit'] )
				{
					$magLimitsByStateType[ $state ][ $tt ] = $row;
				}
			}
		}

		$now    = time();
		$flags  = [];

		/* v1.6.6 PERF instrumentation — wall-clock of the main catalog loop
		   only (the region the GDCK checkpoint proved holds ~all the time). */
		$__loopStart = microtime( true );

		try
		{
			foreach ( \IPS\Db::i()->select( 'upc, category_id, capacity, brand, manufacturer, model, caliber, mpn, title, action_type, stock_material, description', 'gd_catalog' ) as $p )
			{
				$result['processed']++;
				$upc = (string) ( $p['upc'] ?? '' );
				$cat = (int)    ( $p['category_id'] ?? 0 );
				if ( $upc === '' || $cat <= 0 ) { continue; }

				/* --- v1.6.10 Phase 7A: AR/AK lower-receiver AWB pass ---
				   cat154 (Lower Receivers, clean) is now FLAGGED by
				   default; cat69 (Frames & Receivers, JUNK) is title-
				   gated inside Lowers::classify. The classifier
				   consults the curated gd_compliance_lowers table
				   FIRST (admin overrides win). Then it excludes parts
				   / uppers / MLOK / handguards, routes bolt/lever/
				   pump/rimfire hunting-rifle lowers to review, and
				   defaults every remaining cat154 row to flag.

				   Runs BEFORE the typeMap null-skip because these
				   categories don't roll up to the 1/7/16 firearm
				   top-levels. */
				if ( $cat === \IPS\gdcompliance\Lowers::CATEGORY_LOWER
				  || $cat === \IPS\gdcompliance\Lowers::CATEGORY_FRAMES_JUNK )
				{
					try
					{
						$lowerVerdict = \IPS\gdcompliance\Lowers::classify( $p );
						if ( is_array( $lowerVerdict ) )
						{
							$vr      = (string) ( $lowerVerdict['verdict'] ?? '' );
							$pattern = (string) ( $lowerVerdict['pattern'] ?? '' );
							$src     = (string) ( $lowerVerdict['source']  ?? 'auto' );

							if ( $vr === 'flag' )
							{
								/* AR/AK rifle lowers → the rifle-class AWB
								   states only. HI is a pistol-class rule
								   so it's already absent from
								   $awbRifleStates. VA is enabled=0 so it's
								   absent too. */
								foreach ( $awbRifleStates as $awbState )
								{
									try
									{
										$cite = \IPS\gdcompliance\AwbModels::citationFor( $awbState, 'rifle' );
									}
									catch ( \Throwable ) { $cite = ''; }

									$reason = sprintf(
										'AR/AK-pattern lower receiver — restricted assault-weapon component under %s law%s%s%s',
										$awbState,
										$cite !== '' ? ' (' . $cite . ')' : '',
										$pattern !== '' ? '; matched pattern: ' . $pattern : '',
										$src === 'curated' ? ' [curated]' : ''
									);

									$flags[] = [
										'upc'             => substr( $upc, 0, 50 ),
										'state_code'      => $awbState,
										'firearm_type'    => 'awb_lower',
										'parsed_capacity' => null,
										'rule_id'         => 0,
										'reason'          => substr( $reason, 0, 255 ),
										'citation'        => substr( (string) $cite, 0, 255 ),
										'computed_at'     => $now,
									];

									$result['per_state'][ $awbState ] = ( $result['per_state'][ $awbState ] ?? 0 ) + 1;
									$result['per_state_type'][ $awbState ]['awb_lower'] = ( $result['per_state_type'][ $awbState ]['awb_lower'] ?? 0 ) + 1;
									$result['awb']['lower'] = ( $result['awb']['lower'] ?? 0 ) + 1;
								}
								$result['firearms']++;
							}
							elseif ( $vr === 'review' )
							{
								/* Uncertain lower (bolt-action / rimfire /
								   curated review). Surface for human review. */
								$result['review_queue'][] = [
									'upc'              => substr( $upc, 0, 50 ),
									'roster_state'     => '',
									'review_type'      => 'lower',
									'manufacturer'     => substr( (string) ( $p['manufacturer'] ?? $p['brand'] ?? '' ), 0, 120 ),
									'model_title'      => substr( (string) ( $p['title'] ?? $p['model'] ?? '' ), 0, 255 ),
									'caliber'          => substr( (string) ( $p['caliber'] ?? '' ), 0, 60 ),
									'suggested_status' => 'lower_review',
									'created_at'       => $now,
								];
								$result['awb']['lower_review'] = ( $result['awb']['lower_review'] ?? 0 ) + 1;
							}
							elseif ( $vr === 'clear' )
							{
								/* Curated force_clear — count so ACP can
								   report; do not flag, do not queue. */
								$result['awb']['lower_cleared'] = ( $result['awb']['lower_cleared'] ?? 0 ) + 1;
							}
						}
					}
					catch ( \Throwable ) {}
					continue;
				}

				/* --- v1.6.10 Phase 7B: standalone MAGAZINE capacity pass ---
				   cat38 (Magazines) classified by CALIBER, then compared
				   against the state's limit for that firearm class.
				   Fixes the v1.6.9 IL bug where a 30-rd 5.56 AR mag was
				   compared to IL's shotgun limit (5) — it should be the
				   rifle limit (10). Uses parseCapacity (leading integer),
				   NEVER LIKE (LIKE '%15%' matches "150", "5.56x45", etc.).
				   cat58 is game calls / accessories and is NEVER treated
				   as magazines. Runs BEFORE the typeMap null-skip. */
				if ( $cat === 38 )
				{
					try
					{
						$magRaw = isset( $p['capacity'] ) ? (string) $p['capacity'] : '';
						$magCap = self::parseCapacity( $magRaw );
						if ( $magCap !== null && $magCap > 0 )
						{
							$result['firearms']++; /* accounted for in scan stats */

							/* Classify the mag's firearm class from caliber
							   (+ title fallback). Order matters — shotgun
							   gauge tokens are checked first because a
							   plain "12" would otherwise fail every other
							   test. Ambiguous → default to the HIGHER of
							   rifle/handgun limits to avoid the IL-5 bug. */
							$magClass = self::classifyMagClass(
								(string) ( $p['caliber'] ?? '' ),
								(string) ( $p['title']   ?? '' )
							);

							$hit = false;
							foreach ( $magLimitsByStateType as $magState => $byType )
							{
								/* Select the applicable limit for the mag's
								   classified class. Ambiguous mags pick
								   the HIGHER of rifle/handgun to prevent
								   false shotgun-limit hits. */
								if ( $magClass === 'shotgun' && isset( $byType['shotgun'] ) )
								{
									$lim = $byType['shotgun'];
									$lbl = 'shotgun';
								}
								elseif ( $magClass === 'handgun' && isset( $byType['handgun'] ) )
								{
									$lim = $byType['handgun'];
									$lbl = 'handgun';
								}
								elseif ( $magClass === 'rifle' && isset( $byType['rifle'] ) )
								{
									$lim = $byType['rifle'];
									$lbl = 'rifle';
								}
								else
								{
									/* Ambiguous OR class-specific rule missing —
									   pick the higher of rifle/handgun. */
									$r = $byType['rifle']   ?? null;
									$h = $byType['handgun'] ?? null;
									if ( $r === null && $h === null )
									{
										continue; /* state has no applicable rule */
									}
									if ( $r !== null && ( $h === null || (int) $r['limit'] >= (int) $h['limit'] ) )
									{
										$lim = $r;
										$lbl = 'rifle';
									}
									else
									{
										$lim = $h;
										$lbl = 'handgun';
									}
								}

								if ( $magCap > (int) $lim['limit'] )
								{
									$reason = sprintf(
										'%d-round magazine exceeds %s %s limit of %d rounds%s',
										$magCap,
										$magState,
										$lbl,
										(int) $lim['limit'],
										$lim['citation'] !== '' ? ' (' . $lim['citation'] . ')' : ''
									);
									$flags[] = [
										'upc'             => substr( $upc, 0, 50 ),
										'state_code'      => $magState,
										'firearm_type'    => 'magazine',
										'parsed_capacity' => $magCap,
										'rule_id'         => (int) $lim['rule_id'],
										'reason'          => substr( $reason, 0, 255 ),
										'citation'        => substr( (string) $lim['citation'], 0, 255 ),
										'computed_at'     => $now,
									];
									$result['per_state'][ $magState ] = ( $result['per_state'][ $magState ] ?? 0 ) + 1;
									$result['per_state_type'][ $magState ]['magazine'] = ( $result['per_state_type'][ $magState ]['magazine'] ?? 0 ) + 1;
									$hit = true;
								}
							}
							if ( $hit )
							{
								$result['mag']['flagged'] = ( $result['mag']['flagged'] ?? 0 ) + 1;
							}
							else
							{
								$result['mag']['clean'] = ( $result['mag']['clean'] ?? 0 ) + 1;
							}
						}
						elseif ( $magRaw !== '' )
						{
							$key = substr( $magRaw, 0, 100 );
							$result['unparsed'][ $key ] = ( $result['unparsed'][ $key ] ?? 0 ) + 1;
						}
					}
					catch ( \Throwable ) {}
					continue;
				}

				$type = $typeMap[ $cat ] ?? null;
				if ( $type === null ) { continue; }
				$result['firearms']++;

				/* PER-ROW GUARD (v1.6.3): one bad row must NEVER wipe the
				   whole scan. Prior versions wrapped the entire foreach in a
				   single try/catch, so a single-row exception (e.g. the
				   pseudo-variable-in-static-context crash — see rule #1 of
				   this refactor: static methods use self::, never a caller
				   instance ref) aborted the loop, log-swallowed the error,
				   and persisted whatever partial flags had been built. Now
				   every row lives inside its own try; the outer try/catch
				   remains as a safety net for iterator-level errors. */
				try {

				/* --- Phase 6 (v1.6.0): multi-state AWB pass ---
				   Loops every state with an enabled AWB rule for rifles
				   (gd_compliance_awb_rules). For each state, calls
				   AwbModels::match($p, $state) which handles that
				   state's named-model list + feature threshold + CA
				   overall-length rule + centerfire-only exclusion (fixes
				   IL .22LR over-flag from v1.5.x). Runs BEFORE the
				   capacity pass so we can per-state suppress the rifle
				   capacity flag when AWB hits — the AWB reason is the
				   correct one to surface (feature/model bans aren't
				   cured by pinning a magazine, unlike pure capacity).
				   Pin remedy on the popup gates on type='capacity' so
				   AWB rows never trigger it. */
				$awbHitStates = [];  /* [state => true] — used to suppress capacity flag */
				$awbClass = null;
				if ( $type === 'rifle' )    { $awbClass = 'rifle'; }
				elseif ( $type === 'handgun' ) { $awbClass = 'pistol'; }

				if ( $awbClass !== null )
				{
					$act = strtolower( trim( (string) ( $p['action_type'] ?? '' ) ) );
					if ( $act !== '' && strpos( $act, 'semi' ) !== false )
					{
						/* v1.6.4: use the preloaded state lists — no more
						   per-row enabledStates() call. */
						$awbStates = $awbClass === 'rifle' ? $awbRifleStates : $awbPistolStates;

						foreach ( $awbStates as $awbState )
						{
							try
							{
								$m = \IPS\gdcompliance\AwbModels::match( $p, $awbState, $awbClass );
							}
							catch ( \Throwable ) { $m = null; }

							if ( $m === null )
							{
								/* No rule for this state OR rimfire exclusion — skip. */
								continue;
							}

							$awbTier = (int) ( $m['tier'] ?? 3 );
							$feat    = !empty( $m['feature_hits'] ) ? implode( ', ', (array) $m['feature_hits'] ) : '';
							$cite    = trim( (string) ( $m['citation'] ?? '' ) );

							if ( $awbTier === 1 )
							{
								$reason = sprintf(
									'%s-listed assault weapon (%s); model: %s',
									$awbState,
									$cite !== '' ? $cite : 'state statute',
									(string) ( $m['pattern'] ?? 'unknown' )
								);
							}
							elseif ( $awbTier === 2 )
							{
								$reason = 'Likely restricted under ' . $awbState . ' assault weapons law'
									. ( $cite !== '' ? ' (' . $cite . ')' : '' )
									. ' — semi-automatic centerfire rifle'
									. ( $feat !== '' ? ' with ' . $feat : '' )
									. '; verify features';
							}
							else
							{
								/* Tier 3 — low-confidence review. Semi-auto centerfire
								   rifle that didn't meet detected features. Our
								   feature detection is a floor, so we route to
								   review rather than assume it's clean. NO flag
								   is emitted for tier 3 — only a review-queue entry. */
								$result['review_queue'][] = [
									'upc'              => substr( $upc, 0, 50 ),
									'roster_state'     => $awbState,
									'review_type'      => 'awb_firearm',
									'manufacturer'     => substr( (string) ( $p['manufacturer'] ?? $p['brand'] ?? '' ), 0, 120 ),
									'model_title'      => substr( (string) ( $p['title'] ?? $p['model'] ?? '' ), 0, 255 ),
									'caliber'          => substr( (string) ( $p['caliber'] ?? '' ), 0, 60 ),
									'suggested_status' => 'awb_review_' . strtolower( $awbState ),
									'created_at'       => $now,
								];
								$result['awb']['review'] = ( $result['awb']['review'] ?? 0 ) + 1;
								continue;
							}

							/* Emit flag for tier 1 / tier 2. */
							$awbFtype = 'awb_' . $awbClass;
							$flags[] = [
								'upc'             => substr( $upc, 0, 50 ),
								'state_code'      => $awbState,
								'firearm_type'    => $awbFtype,
								'parsed_capacity' => null,
								'rule_id'         => 0,
								'reason'          => substr( $reason, 0, 255 ),
								'citation'        => substr( $cite, 0, 255 ),
								'computed_at'     => $now,
							];

							$result['per_state'][ $awbState ] = ( $result['per_state'][ $awbState ] ?? 0 ) + 1;
							$result['per_state_type'][ $awbState ][ $awbFtype ] = ( $result['per_state_type'][ $awbState ][ $awbFtype ] ?? 0 ) + 1;
							$result['awb'][ 'tier' . $awbTier ] = ( $result['awb'][ 'tier' . $awbTier ] ?? 0 ) + 1;

							/* Tier 2 → also queue for verification. */
							if ( $awbTier === 2 )
							{
								$result['review_queue'][] = [
									'upc'              => substr( $upc, 0, 50 ),
									'roster_state'     => $awbState,
									'review_type'      => 'awb_firearm',
									'manufacturer'     => substr( (string) ( $p['manufacturer'] ?? $p['brand'] ?? '' ), 0, 120 ),
									'model_title'      => substr( (string) ( $p['title'] ?? $p['model'] ?? '' ), 0, 255 ),
									'caliber'          => substr( (string) ( $p['caliber'] ?? '' ), 0, 60 ),
									'suggested_status' => 'awb_tier2_' . strtolower( $awbState ),
									'created_at'       => $now,
								];
							}

							$awbHitStates[ $awbState ] = true;
						}
					}
				}

				/* --- v1.6.17 Phase 6b: buyer-permit ADVISORY pass ---
				   Emits advisory flags (firearm_type='advisory') for
				   states where the buyer needs a permit/card/training
				   to purchase — CO SSF (SB25-003) + MN SAMSAW
				   (§624.712). NOT a restriction; the item ships.
				   Front-end classifies these as Flag::TYPE_ADVISORY
				   and renders yellow, never in the red cannot-ship
				   banner. Runs AFTER the AWB pass so it can attach
				   to the same rifles the feature test already flagged
				   for AWB-restrict states (no conflict — CA restricts,
				   CO/MN advise; different states, different meaning). */
				try
				{
					$adv = \IPS\gdcompliance\Advisories::matchesFor( $p, $type );
					foreach ( $adv as $a )
					{
						$aState = strtoupper( (string) ( $a['state']    ?? '' ) );
						$aReason = trim( (string) ( $a['reason']   ?? '' ) );
						$aCite   = trim( (string) ( $a['citation'] ?? '' ) );
						if ( $aState === '' || $aReason === '' ) { continue; }
						$flags[] = [
							'upc'             => substr( $upc, 0, 50 ),
							'state_code'      => $aState,
							'firearm_type'    => 'advisory',
							'parsed_capacity' => null,
							'rule_id'         => 0,
							'reason'          => substr( $aReason, 0, 255 ),
							'citation'        => substr( $aCite,   0, 255 ),
							'computed_at'     => $now,
						];
						$result['per_state'][ $aState ] = ( $result['per_state'][ $aState ] ?? 0 ) + 1;
						$result['per_state_type'][ $aState ]['advisory'] = ( $result['per_state_type'][ $aState ]['advisory'] ?? 0 ) + 1;
						$result['advisory'][ $aState ] = ( $result['advisory'][ $aState ] ?? 0 ) + 1;
					}
				}
				catch ( \Throwable ) { /* per-row, non-fatal */ }

				/* --- v1.6.19 Phase 6c: melting-point HANDGUN ban ---
				   HI/IL/MD/MA/MN/NY Saturday-Night-Special bans on
				   zinc-alloy handguns. Category-gated inside
				   MeltingPoint::classify (cat1/2/3 only — the cat8
				   Hi-Point 995TS carbine correctly does NOT flag).
				   Emits gd_compliance_flags rows with
				   firearm_type='melting_point' for every enabled
				   melting-point state. Reason + citation come from
				   the per-state rule row (editable in the ACP). */
				if ( in_array( $cat, \IPS\gdcompliance\MeltingPoint::HANDGUN_CATEGORIES, true )
				  && !empty( $mpStateRules ) )
				{
					try
					{
						$mpVerdict = \IPS\gdcompliance\MeltingPoint::classify( $p );
						if ( is_array( $mpVerdict ) && ( $mpVerdict['verdict'] ?? '' ) === 'flag' )
						{
							$mpSrc  = (string) ( $mpVerdict['source'] ?? 'auto' );
							$mpHint = (string) ( $mpVerdict['reason_hint'] ?? '' );
							foreach ( $mpStateRules as $mpState => $mpRule )
							{
								$mpReason = trim( (string) ( $mpRule['reason']   ?? '' ) );
								$mpCite   = trim( (string) ( $mpRule['citation'] ?? '' ) );
								if ( $mpReason === '' )
								{
									$mpReason = sprintf(
										'Handgun with a zinc-alloy / non-homogeneous frame that fails %s\'s minimum melting-point standard — prohibited for sale. Steel-frame models from this line are exempt.',
										$mpState
									);
								}
								if ( $mpHint !== '' && $mpSrc === 'auto' )
								{
									$mpReason .= ' [' . $mpHint . ']';
								}
								if ( $mpSrc === 'curated' )
								{
									$mpReason .= ' [curated]';
								}
								$flags[] = [
									'upc'             => substr( $upc, 0, 50 ),
									'state_code'      => (string) $mpState,
									'firearm_type'    => 'melting_point',
									'parsed_capacity' => null,
									'rule_id'         => 0,
									'reason'          => substr( $mpReason, 0, 255 ),
									'citation'        => substr( $mpCite,   0, 255 ),
									'computed_at'     => $now,
								];
								$result['per_state'][ $mpState ] = ( $result['per_state'][ $mpState ] ?? 0 ) + 1;
								$result['per_state_type'][ $mpState ]['melting_point'] = ( $result['per_state_type'][ $mpState ]['melting_point'] ?? 0 ) + 1;
								$result['melting_point'][ $mpState ] = ( $result['melting_point'][ $mpState ] ?? 0 ) + 1;
							}
						}
					}
					catch ( \Throwable ) { /* per-row, non-fatal */ }
				}

				/* --- Phase 1: capacity-rule pass --- */
				$capRaw = isset( $p['capacity'] ) ? (string) $p['capacity'] : '';
				$cap    = self::parseCapacity( $capRaw );
				if ( $cap !== null )
				{
					$applicable = array_merge( $rulesByType[ $type ] ?? [], $rulesByType['all'] );
					foreach ( $applicable as $r )
					{
						$limit = (int) $r['max_capacity'];
						if ( $cap > $limit )
						{
							$state = (string) $r['state_code'];

							/* Suppress the rifle capacity flag for any state
							   where the AWB pass already hit — feature/model
							   bans are the stronger, correct reason and
							   pinning a magazine can't cure them. Per-state
							   check (was IL-only in v1.5.x). */
							if ( $type === 'rifle' && !empty( $awbHitStates[ $state ] ) )
							{
								continue;
							}

							$reason = sprintf(
								'%s mag %d > %s limit %d',
								ucfirst( $type ),
								$cap,
								$state,
								$limit
							);
							$flags[] = [
								'upc'             => substr( $upc, 0, 50 ),
								'state_code'      => $state,
								'firearm_type'    => $type,
								'parsed_capacity' => $cap,
								'rule_id'         => (int) $r['id'],
								'reason'          => substr( $reason, 0, 255 ),
								'citation'        => substr( (string) ( $r['source_note'] ?? '' ), 0, 255 ),
								'computed_at'     => $now,
							];

							$result['per_state'][ $state ] = ( $result['per_state'][ $state ] ?? 0 ) + 1;
							$result['per_state_type'][ $state ][ $type ] = ( $result['per_state_type'][ $state ][ $type ] ?? 0 ) + 1;

							if ( count( $result['sample'] ) < self::SAMPLE_LIMIT )
							{
								$result['sample'][] = [
									'upc'      => $upc,
									'state'    => $state,
									'type'     => $type,
									'capacity' => $cap,
									'limit'    => $limit,
								];
							}
						}
					}
				}
				else if ( $capRaw !== '' )
				{
					$key = substr( $capRaw, 0, 100 );
					$result['unparsed'][ $key ] = ( $result['unparsed'][ $key ] ?? 0 ) + 1;
				}

				/* --- Phase 2/3: multi-state roster pass (handguns only) ---
				   Loops every state with loaded roster data plus the DC derived
				   outcome when enabled. Off-roster → CA/MA/MD/DC flag with
				   per-state reason; unmatched → review queue tagged with
				   roster_state so resolution is per-state. */
				if ( $type === 'handgun' )
				{
					$perState = [];
					foreach ( $rosterStates as $rstate )
					{
						try
						{
							$cls = \IPS\gdcompliance\Roster::classifyHandgun( $p, $rstate );
						}
						catch ( \Throwable )
						{
							$cls = [ 'status' => 'unmatched_review', 'reason' => 'classifier error', 'confidence' => 'none', 'matched_roster_id' => null, 'candidates' => [] ];
						}
						$status = (string) ( $cls['status'] ?? 'unmatched_review' );
						$perState[ $rstate ] = $status;
						self::recordRosterOutcome( $result, $flags, $upc, $rstate, $status, $cls, $p, $now );
					}

					/* DC derived from CA+MA+MD union. Only computes when all
					   three states ran (so we don't fire DC on a partial
					   picture). */
					if ( $dcDerive && count( $perState ) === 3 )
					{
						$dc = \IPS\gdcompliance\Roster::deriveDC( $perState );
						self::recordRosterOutcome( $result, $flags, $upc, 'DC',
							(string) $dc['status'],
							[ 'reason' => $dc['reason'], 'candidates' => [] ],
							$p, $now, true
						);
					}
				}
				}
				catch ( \Throwable $rowE )
				{
					/* Isolate the poison row — count it, log ONCE per row
					   with the upc, and keep scanning. */
					$result['row_errors'] = ( $result['row_errors'] ?? 0 ) + 1;
					try { \IPS\Log::log( 'Engine::computeFlags row upc=' . $upc . ': ' . $rowE->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Engine::computeFlags scan (iterator): ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		/* v1.6.6 PERF checkpoint — proves the fix in a single run. Baseline
		   before the gc_disable change: GDCK loop-done ~582s. Target now:
		   ~10-20s with gc_runs ~0 (GC did not fire during the loop). If the
		   loop is STILL slow here, GC was not the (only) cause and the next
		   step is per-section timing (awb/capacity/roster) — but the ruled-
		   out component tests make that unlikely. One log write per compute. */
		try {
			$__loopSecs = round( microtime( true ) - $__loopStart, 1 );
			$__gcRuns   = function_exists( 'gc_status' ) ? (int) ( \gc_status()['runs'] ?? -1 ) : -1;
			$__msg = 'GDCK loop-done ' . $__loopSecs . 's; gc_runs=' . $__gcRuns
				. '; firearms=' . (int) $result['firearms'] . '; flags=' . count( $flags );
			try { \IPS\Log::log( 'Engine::computeFlags ' . $__msg, 'gdcompliance_perf' ); } catch ( \Throwable ) {}
			@error_log( $__msg );
		} catch ( \Throwable ) {}

		$result['flags'] = count( $flags );

		if ( $dryRun )
		{
			$result['duration_ms'] = (int) ( ( microtime( true ) - $computeStart ) * 1000 );
			if ( $gcWasEnabled ) { \gc_enable(); }
			return $result;
		}

		/* -----------------------------------------------------------
		 * CRASH-SAFE FLAG REBUILD
		 *
		 * Never wipe the old flag set until the new one is proven to
		 * insert cleanly. We use a per-run staging table:
		 *   (1) CREATE gd_compliance_flags_stage LIKE gd_compliance_flags
		 *   (2) INSERT all new rows into stage in chunks
		 *   (3) If ANY chunk fails → drop stage, log, keep old flags
		 *   (4) On success → atomic RENAME swap; drop the old table
		 *
		 * NEVER touches gd_compliance_rules or gd_compliance_overrides.
		 * ----------------------------------------------------------- */
		$prefix     = (string) \IPS\Db::i()->prefix;
		$stageTable = $prefix . 'gd_compliance_flags_stage';
		$oldTable   = $prefix . 'gd_compliance_flags_old';
		$mainTable  = $prefix . 'gd_compliance_flags';

		$oldFlagCount = 0;
		try { $oldFlagCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_flags' )->first(); }
		catch ( \Throwable ) {}

		/* Sanity guard: if we had ANY existing flags and the new set is
		   empty AND rules + firearms both scanned, treat as a probable
		   compute failure and keep old flags. */
		if ( $oldFlagCount > 0 && empty( $flags ) && !empty( $rules ) && $result['firearms'] > 0 )
		{
			try { \IPS\Log::log( 'Engine::computeFlags: computed zero flags but had ' . $oldFlagCount . ' pre-existing — refusing to wipe.', 'gdcompliance' ); } catch ( \Throwable ) {}
			$result['flags_skipped_wipe'] = true;
		}
		else
		{
			/* Clean up any orphan stage from a previously-interrupted run. */
			try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $stageTable ); } catch ( \Throwable ) {}

			$swapOk    = true;
			$chunkIdx  = -1;
			$totalRows = count( $flags );
			$stagedRows = 0;
			try
			{
				\IPS\Db::i()->query( "CREATE TABLE " . $stageTable . " LIKE " . $mainTable );

				if ( !empty( $flags ) )
				{
					/* v1.6.5 — bulk INSERT.
					   pre-v1.6.5: \IPS\Db::i()->insert($table, $arrayOfRows)
					   issues ONE INSERT per row, so 32k rows = 32k round-
					   trips = ~300s of wall-clock. Now each chunk is one
					   `INSERT INTO ... VALUES (?,?,...),(?,?,...),...`
					   preparedQuery — a single round-trip per 1,500 rows. */
					foreach ( array_chunk( $flags, 1500 ) as $ci => $chunk )
					{
						$chunkIdx = $ci;
						self::bulkInsert( $stageTable, $chunk );
						$stagedRows += count( $chunk );
					}
				}
			}
			catch ( \Throwable $e )
			{
				$swapOk = false;
				/* Loud log — the "compute reports N flags but writes 0" bug
				   from the 1.4.x era was invisible without a message that
				   includes the row count, the chunk index, and the error. */
				try
				{
					\IPS\Log::log(
						'Engine::computeFlags stage build FAILED at chunk ' . $chunkIdx . ' (staged ' . $stagedRows . ' of ' . $totalRows . ' rows): ' . $e->getMessage(),
						'gdcompliance'
					);
				}
				catch ( \Throwable ) {}
				try { \IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $stageTable ); } catch ( \Throwable ) {}
				$result['flags_skipped_wipe']  = true;
				$result['flags_stage_error']   = substr( $e->getMessage(), 0, 500 );
				$result['flags_staged_before'] = $stagedRows;
			}

			if ( $swapOk )
			{
				try
				{
					/* Atomic swap: old data disappears only when new data
					   is fully in place. */
					\IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $oldTable );
					\IPS\Db::i()->query( "RENAME TABLE " . $mainTable . " TO " . $oldTable . ", " . $stageTable . " TO " . $mainTable );
					\IPS\Db::i()->query( "DROP TABLE IF EXISTS " . $oldTable );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'Engine::computeFlags swap: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
					/* Attempt to restore if only half the RENAME committed. */
					try
					{
						$mainExists = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.TABLES',
							[ 'TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', 'gd_compliance_flags' ] )->first();
						$oldExists = (bool) \IPS\Db::i()->select( 'COUNT(*)', 'information_schema.TABLES',
							[ 'TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', 'gd_compliance_flags_old' ] )->first();
						if ( !$mainExists && $oldExists )
						{
							\IPS\Db::i()->query( "RENAME TABLE " . $oldTable . " TO " . $mainTable );
						}
					}
					catch ( \Throwable ) {}
					$result['flags_skipped_wipe'] = true;
				}
			}
		}

		/* Refresh review queue — preserve previously RESOLVED rows (those are
		   Derrick's manual decisions) per-(upc, roster_state) pair, so a
		   handgun resolved for CA stays resolved while MA can still be
		   re-classified independently. */
		try { \IPS\Db::i()->delete( 'gd_compliance_review', [ 'resolved=0' ] ); } catch ( \Throwable ) {}
		if ( !empty( $result['review_queue'] ) )
		{
			$existing = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'upc, roster_state', 'gd_compliance_review', [ 'resolved=1' ] ) as $row )
				{
					$existing[ (string) ( $row['upc'] ?? '' ) . '|' . (string) ( $row['roster_state'] ?? '' ) ] = true;
				}
			}
			catch ( \Throwable ) {}

			$fresh = array_filter( $result['review_queue'], function( $r ) use ( $existing ) {
				$key = (string) ( $r['upc'] ?? '' ) . '|' . (string) ( $r['roster_state'] ?? '' );
				return empty( $existing[ $key ] );
			} );
			foreach ( array_chunk( array_values( $fresh ), 1500 ) as $chunk )
			{
				try { self::bulkInsert( $prefix . 'gd_compliance_review', $chunk ); }
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'Engine::computeFlags review insert: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
				}
			}
		}

		/* Refresh unparsed-capacity tally. Wipe + insert. */
		try { \IPS\Db::i()->delete( 'gd_compliance_unparsed' ); } catch ( \Throwable ) {}
		foreach ( $result['unparsed'] as $val => $count )
		{
			try
			{
				\IPS\Db::i()->insert( 'gd_compliance_unparsed', [
					'capacity_value' => (string) $val,
					'count'          => (int) $count,
					'updated_at'     => $now,
				] );
			}
			catch ( \Throwable ) {}
		}

		/* Phase 4 — apply manual overrides LAST so human decisions
		   always win over rule-computed outcomes. Overrides live in
		   their own permanent table (gd_compliance_overrides); each
		   force_restrict inserts / replaces its flag row, each
		   force_clear wipes any flag row for that (upc, state). This
		   step runs on every real compute, so overrides survive
		   recomputes regardless of how the rule set evolves. */
		try
		{
			$oc = \IPS\gdcompliance\Override::applyAll();
			$result['overrides'] = $oc;
		}
		catch ( \Throwable $e )
		{
			$result['overrides'] = [ 'restrict' => 0, 'clear' => 0 ];
			try { \IPS\Log::log( 'Engine::computeFlags overrides: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		try { \IPS\Settings::i()->changeValues( [
			'gdcompliance_last_run'    => date( 'Y-m-d H:i:s', $now ),
			'gdcompliance_last_counts' => json_encode( [
				'processed' => $result['processed'],
				'firearms'  => $result['firearms'],
				'flags'     => $result['flags'],
				'per_state' => $result['per_state'],
				'overrides' => $result['overrides'] ?? [],
			] ),
		] ); } catch ( \Throwable ) {}

		$result['duration_ms'] = (int) ( ( microtime( true ) - $computeStart ) * 1000 );

		try { \IPS\Log::log( 'Engine::computeFlags complete: ' . json_encode( [
			'flags' => $result['flags'], 'per_state' => $result['per_state'], 'duration_ms' => $result['duration_ms'], 'row_errors' => $result['row_errors'] ?? 0,
		] ), 'gdcompliance' ); } catch ( \Throwable ) {}

		if ( $gcWasEnabled ) { \gc_enable(); }
		return $result;
	}

	/**
	 * Update result counters + per-state buckets + flag list for one
	 * roster-state outcome on one product.
	 *
	 * Shared by the live state pass (CA/MA/MD) AND the DC derived pass so
	 * the bookkeeping logic exists in one place. $isDerived=true marks DC
	 * outcomes in the reason text.
	 */
	/**
	 * Bulk multi-row parameterized INSERT (v1.6.5).
	 *
	 * Pre-v1.6.5, the stage-build loop called
	 *   \IPS\Db::i()->insert( $table, $arrayOfRows )
	 * which IPS interprets as an array-of-rows and issues ONE INSERT
	 * per row internally. 32,000 flags therefore cost 32,000 network
	 * round-trips to MySQL ≈ 300 seconds of wall-clock. That was 92%
	 * of a 323-second compute.
	 *
	 * This helper emits ONE
	 *   INSERT INTO `<table>` (col1, col2, ...) VALUES (?,?,...),(?,?,...),(...)
	 * preparedQuery per chunk. Values are parameterized so we stay
	 * injection-safe. Column list comes from array_keys() of the first
	 * row — every row MUST have the same keys (which they do inside
	 * compute; a flag or review row is built from a single struct).
	 *
	 * Returns the number of rows sent. Throws on DB error so the caller's
	 * existing catch can drop the stage and roll back.
	 *
	 * @param string $fullyQualifiedTable  Full table name including prefix (e.g. from $prefix . 'gd_compliance_flags_stage')
	 * @param array<int, array<string, mixed>> $rows
	 */
	protected static function bulkInsert( string $fullyQualifiedTable, array $rows ): int
	{
		if ( empty( $rows ) ) { return 0; }
		$first = reset( $rows );
		if ( !is_array( $first ) || empty( $first ) ) { return 0; }

		$cols     = array_keys( $first );
		$colCount = count( $cols );

		/* Backtick-quote the column names — cheap sanitation. Field
		   names come from static config in this code, but the pattern
		   is defensive. */
		$colList = implode( ',', array_map( fn( string $c ) => '`' . str_replace( '`', '', $c ) . '`', $cols ) );

		/* One placeholder group per row. */
		$groupTemplate = '(' . implode( ',', array_fill( 0, $colCount, '?' ) ) . ')';
		$groups        = implode( ',', array_fill( 0, count( $rows ), $groupTemplate ) );

		/* Flatten values in column order, per row. */
		$flat = [];
		foreach ( $rows as $row )
		{
			foreach ( $cols as $c )
			{
				$flat[] = $row[ $c ] ?? null;
			}
		}

		$sql = 'INSERT INTO ' . $fullyQualifiedTable . ' (' . $colList . ') VALUES ' . $groups;
		\IPS\Db::i()->preparedQuery( $sql, $flat );

		return count( $rows );
	}

	/**
	 * Per-state statute reference for roster off-listing flags. Populated
	 * inline on the flag row so Flag::forUpc surfaces it in the frontend
	 * popup's Citation line without a rules-table lookup (roster flags
	 * have rule_id=0 by design — they're outside the capacity rule set).
	 */
	protected static function rosterCitationFor( string $state ): string
	{
		return match ( strtoupper( $state ) ) {
			'CA'    => 'CA Pen Code §32000 (Roberti-Roos, DOJ handgun roster)',
			'MA'    => 'MGL c.140 §131¾ (MA Approved Firearms Roster)',
			'MD'    => 'MD Public Safety §5-405 (Handgun Roster Board)',
			'DC'    => 'Derived from CA/MA/MD roster union',
			default => '',
		};
	}

	protected static function recordRosterOutcome(
		array &$result,
		array &$flags,
		string $upc,
		string $rstate,
		string $status,
		array $cls,
		array $p,
		int $now,
		bool $isDerived = false
	): void
	{
		if ( !isset( $result['roster'][ $rstate ] ) )
		{
			$result['roster'][ $rstate ] = [ 'on' => 0, 'off' => 0, 'review' => 0 ];
		}

		if ( $status === 'on_roster' )
		{
			$result['roster'][ $rstate ]['on']++;
			return;
		}
		if ( $status === 'off_roster' )
		{
			$result['roster'][ $rstate ]['off']++;
			$prefix = $isDerived
				? "Not on {$rstate} roster (derived from CA+MA+MD)"
				: "Not on {$rstate} roster";
			$flags[] = [
				'upc'             => substr( $upc, 0, 50 ),
				'state_code'      => $rstate,
				'firearm_type'    => 'handgun',
				'parsed_capacity' => null,
				'rule_id'         => 0,
				'reason'          => substr( $prefix . ' — ' . ( $cls['reason'] ?? '' ), 0, 255 ),
				'citation'        => substr( self::rosterCitationFor( $rstate ), 0, 255 ),
				'computed_at'     => $now,
			];
			$result['per_state'][ $rstate ] = ( $result['per_state'][ $rstate ] ?? 0 ) + 1;
			$result['per_state_type'][ $rstate ]['handgun_roster'] = ( $result['per_state_type'][ $rstate ]['handgun_roster'] ?? 0 ) + 1;
			return;
		}
		/* unmatched_review */
		$result['roster'][ $rstate ]['review']++;
		$result['review_queue'][] = [
			'upc'              => substr( $upc, 0, 50 ),
			'roster_state'     => $rstate,
			'review_type'      => 'roster',
			'manufacturer'     => substr( (string) ( $p['manufacturer'] ?? '' ), 0, 120 ),
			'model_title'      => substr( (string) ( $p['title'] ?? $p['model'] ?? '' ), 0, 255 ),
			'caliber'          => substr( (string) ( $p['caliber'] ?? '' ), 0, 60 ),
			'suggested_status' => 'unmatched_review',
			'candidates_json'  => json_encode( $cls['candidates'] ?? [] ),
			'resolved'         => 0,
			'resolved_status'  => null,
			'resolved_by'      => null,
			'resolved_at'      => null,
			'created_at'       => $now,
		];
	}

	/* Clear all flags without recomputing — useful before disabling the app
	   or when Derrick wants a clean slate. Preserves the gd_compliance_review
	   queue (those rows are Derrick's manual decisions) and the roster table. */
	public static function clearFlags(): array
	{
		$counts = [ 'flags' => 0, 'unparsed' => 0 ];
		try { $counts['flags']    = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_flags' )->first(); }    catch ( \Throwable ) {}
		try { $counts['unparsed'] = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_unparsed' )->first(); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'gd_compliance_flags' ); }    catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'gd_compliance_unparsed' ); } catch ( \Throwable ) {}
		return $counts;
	}
}

class Engine extends _Engine {}
