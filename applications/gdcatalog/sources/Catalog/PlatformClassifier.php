<?php
/**
 * @brief  GD Catalog — Platform Classifier (v1.0.113)
 *
 * Tiered title-based classifier for category-1 (Handguns) products
 * that are actually rifles or shotguns. Structure mirrors the
 * gdcompliance/sources/Lowers.php pattern Derrick already approved:
 * curated overrides win, then decisive signals, then a conservative
 * review-queue fallback (NEVER guess-reassign an ambiguous product).
 *
 * All structured fields (action_type / receiver_type / gun_type /
 * product_type / stock_type) are EMPTY on the affected rows, so
 * title text + brand + caliber are the only classification signals.
 *
 * Layer order (first confident hit wins):
 *   1. Curated gd_catalog_platform_overrides (pattern substring match)
 *   2. Handgun override signals (checked FIRST as a gate)
 *      — explicit "Pistol" as a product word (NOT "Pistol Grip")
 *      — "Revolver", "Derringer", "Single Action Army"/"SAA"
 *      — "Arm Brace" + short barrel
 *      — cylinder round-count + "Grips" combo
 *      → HANDGUN (stays in cat 1)
 *   3. Decisive SHOTGUN — gauge tokens (12 Gauge, .410, 20ga, etc.)
 *      → SHOTGUN cat 16
 *   4. Decisive RIFLE — rifle-only calibers OR rifle-action-language
 *      OR brand containing "Rifles"
 *      → RIFLE cat 7
 *   5. Everything else → REVIEW (routed to gd_catalog_platform_review)
 *
 * Layer 2 checked FIRST so a handgun with an ambiguous caliber can't
 * be false-classified as a rifle. Same for the "Pistol Grip" false
 * positive on shotguns/rifles — Layer 2 does NOT match "Pistol Grip",
 * so a Maverick 88 Cruiser with "12 Gauge ... Pistol Grip Stock"
 * correctly hits Layer 3 as SHOTGUN, not Layer 2 as HANDGUN.
 *
 * classify() return shape:
 *   [
 *     'verdict'             => 'reclassify'|'review'|'stay',
 *     'target_category_id'  => int (1|7|16) — null if verdict='review'
 *     'source'              => 'curated'|'handgun-override'|'shotgun-gauge'|'rifle-caliber'|'rifle-action'|'rifle-brand'|'ambiguous',
 *     'signal'              => string  — the matched token, for the audit log
 *     'reason_hint'         => string  — human-readable, for the review-queue UI
 *   ]
 *
 * Rule #34: never concat user input into raw SQL — the curated
 * pattern lookup uses preparedQuery with `?` binds; the reclass
 * UPDATE goes through IPS's ->update() helper.
 */

namespace IPS\gdcatalog\Catalog;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _PlatformClassifier
{
	const CAT_HANDGUN = 1;
	const CAT_RIFLE   = 7;
	const CAT_SHOTGUN = 16;

	/**
	 * Handgun override signals. Presence of ANY of these means the
	 * product IS a handgun regardless of caliber/brand suggesting
	 * otherwise. Checked FIRST as a gate against Layer 3/4 false
	 * positives (e.g. rifle-caliber pistols like AR-pistols).
	 *
	 * IMPORTANT: none of these can accidentally match a "Pistol Grip"
	 * stock/grip descriptor on a rifle/shotgun. Explicit "Pistol"
	 * detection uses a negative lookahead for " Grip" — see
	 * hasHandgunOverride().
	 */
	const HANDGUN_SIGNAL_PATTERNS = [
		/* Handgun product-type words */
		'revolver',
		'derringer',
		'single action army',
		'\bsaa\b',
		'wheelgun',

		/* Handgun-specific model families / trademarks */
		'\bmp15 pistol\b',   /* explicit-pistol variant of M&P15 rifle line */
		'\bm&p15 pistol\b',
	];

	/**
	 * Rifle-only (or near-rifle-only) calibers. Presence alone is a
	 * strong signal but NOT decisive on its own — must be combined
	 * with rifle-action-language OR long barrel OR rifle-brand.
	 * .223/5.56 and .300 BLK are DELIBERATELY excluded because they
	 * commonly appear on AR-pistols too.
	 *
	 * @var string[]
	 */
	const RIFLE_CALIBER_PATTERNS = [
		'6\.5\s*creedmoor',
		'6\.5\s*prc',
		'6\.5\s*grendel',
		'6\.5\s*swedish',
		'6\.8\s*spc',
		'\.243\s*win',
		'\.30-06',
		'30-06',
		'\.30\s*carbine',
		'\.308\s*win',
		'\.270\s*win',
		'\.270\s*wsm',
		'\.300\s*win\s*mag',
		'\.300\s*wm',
		'\.300\s*prc',
		'\.300\s*wsm',
		'\.338\s*lapua',
		'\.338\s*win\s*mag',
		'\.350\s*legend',
		'\.360\s*buckhammer',
		'\.375\s*h&h',
		'\.416\s*rigby',
		'\.450\s*bushmaster',
		'\.458\s*socom',
		'\.50\s*bmg',
		'\.17\s*hmr',
		'\.17\s*wsm',
		'\.22\s*wmr',
		'\.22\s*mag',
		'\.22\s*hornet',
		'\.22-250',
		'22-250',
		'\.204\s*ruger',
		'\.223\s*wssm',
		'6mm\s*arc',
		'6mm\s*creedmoor',
		'7mm\s*rem\s*mag',
		'7mm-08',
		'7\.62x39',
		'7\.62x51',
		'7\.62x54',
		'5\.7x28',
		'\.257\s*weatherby',
	];

	/**
	 * Rifle-action language. Combined with EITHER a rifle-caliber
	 * hit OR a barrel length ≥16" (in the title) as the decisive
	 * rifle signal.
	 *
	 * @var string[]
	 */
	const RIFLE_ACTION_PATTERNS = [
		'bolt[\s-]?action',
		'lever[\s-]?action',
		'straight[\s-]?pull',
		'\bvarmint',
		'\bvarminter',
		'\bcarbine\b',
		'\bhunter\b',
		'\bhunting\s+rifle',
		'\bprecision\s+rifle',
		'\btactical\s+rifle',
	];

	/**
	 * Brand tokens where the brand name itself contains "Rifles" —
	 * a direct rifle signal (Bergara Rifles, etc.).
	 */
	const RIFLE_BRAND_PATTERNS = [
		'\brifles\b',
	];

	/**
	 * Shotgun gauge tokens. Any hit is a decisive shotgun signal
	 * PROVIDED the handgun override gate hasn't already fired.
	 *
	 * @var string[]
	 */
	const SHOTGUN_GAUGE_PATTERNS = [
		'\d+\s*gauge\b',
		'\d+\s*ga\b',
		'\.410',
		'410\s*bore',
		'\b410ga\b',
	];

	/**
	 * @param array{upc:string, title:string, brand?:string, caliber?:string, mpn?:string, model?:string, barrel_length?:string|float|null, category_id?:int} $product
	 * @return array{verdict:string, target_category_id:?int, source:string, signal:string, reason_hint:string}
	 */
	public static function classify( array $product ): array
	{
		$title   = strtolower( trim( (string) ( $product['title']   ?? '' ) ) );
		$brand   = strtolower( trim( (string) ( $product['brand']   ?? '' ) ) );
		$caliber = strtolower( trim( (string) ( $product['caliber'] ?? '' ) ) );
		$mpn     = strtolower( trim( (string) ( $product['mpn']     ?? '' ) ) );
		$model   = strtolower( trim( (string) ( $product['model']   ?? '' ) ) );
		$upc     = trim( (string) ( $product['upc'] ?? '' ) );
		$barrel  = (float) ( $product['barrel_length'] ?? 0 );

		/* Haystack combines all title-ish scalars so pattern matching
		   catches signals wherever they live in the row. */
		$haystack = trim( $title . ' ' . $brand . ' ' . $caliber . ' ' . $mpn . ' ' . $model );

		if ( $haystack === '' )
		{
			return self::verdict( 'review', null, 'ambiguous', '', 'empty title/brand/caliber' );
		}

		/* Layer 1 — curated overrides (admin corrections win). */
		$curated = self::curatedOverride( $haystack, $upc );
		if ( $curated !== null )
		{
			return $curated;
		}

		/* Layer 2 — handgun override signals. Checked BEFORE
		   shotgun/rifle so a handgun with an ambiguous caliber
		   (AR-pistol in .300 Blackout, S&W M&P15 Pistol) can't
		   be misclassified. */
		$hg = self::hasHandgunOverride( $haystack, $title, $barrel );
		if ( $hg !== null )
		{
			return self::verdict( 'stay', self::CAT_HANDGUN, 'handgun-override', $hg, 'confirmed handgun: ' . $hg );
		}

		/* Layer 3 — decisive shotgun signal (gauge token). */
		foreach ( self::SHOTGUN_GAUGE_PATTERNS as $pat )
		{
			if ( preg_match( '/' . $pat . '/i', $haystack, $m ) )
			{
				return self::verdict(
					'reclassify',
					self::CAT_SHOTGUN,
					'shotgun-gauge',
					(string) $m[0],
					'gauge token: ' . $m[0]
				);
			}
		}

		/* Layer 4 — decisive rifle signal. Multiple gates:
		     (a) brand explicitly contains "Rifles" (Bergara Rifles, etc.)
		     (b) rifle-EXCLUSIVE caliber alone (calibers with no pistol
		         equivalent — 6.5 Creedmoor, .308 Win, .30-06, .350
		         Legend, .450 Bushmaster, etc.) is decisive; the
		         Savage 110 case relies on this gate.
		     (c) rifle-action language alone + long barrel (>=16")
		     (d) any-rifle-caliber + rifle-action language (redundant
		         confirmation for calibers that ALSO appear in pistols)
		   Handgun override (Layer 2) has already excluded true handguns
		   before we reach here — so an AR-pistol in a rifle caliber
		   was caught upstream. */
		$rifleCaliberHit = self::firstMatch( $haystack, self::RIFLE_CALIBER_PATTERNS );
		$rifleActionHit  = self::firstMatch( $haystack, self::RIFLE_ACTION_PATTERNS );
		$rifleBrandHit   = self::firstMatch( $brand,    self::RIFLE_BRAND_PATTERNS );
		$longBarrel      = ( $barrel >= 16.0 );

		if ( $rifleBrandHit !== null )
		{
			return self::verdict( 'reclassify', self::CAT_RIFLE, 'rifle-brand', $rifleBrandHit, 'brand contains "Rifles"' );
		}
		if ( $rifleCaliberHit !== null )
		{
			$extras = [];
			if ( $rifleActionHit !== null ) { $extras[] = $rifleActionHit; }
			if ( $longBarrel )              { $extras[] = sprintf( '%.1f" barrel', $barrel ); }
			$sig = $rifleCaliberHit . ( $extras ? ' + ' . implode( ' + ', $extras ) : '' );
			return self::verdict( 'reclassify', self::CAT_RIFLE, 'rifle-caliber', $sig, 'rifle-exclusive caliber: ' . $sig );
		}
		if ( $rifleActionHit !== null && $longBarrel )
		{
			$sig = $rifleActionHit . sprintf( ' + %.1f" barrel', $barrel );
			return self::verdict( 'reclassify', self::CAT_RIFLE, 'rifle-action', $sig, 'rifle action + long barrel: ' . $sig );
		}

		/* Layer 5 — ambiguous → REVIEW. */
		$hint = 'no confident signal';
		if ( $rifleActionHit !== null ) { $hint = 'rifle action (' . $rifleActionHit . ') but no caliber/long-barrel confirm'; }

		return self::verdict( 'review', null, 'ambiguous', '', $hint );
	}

	/**
	 * "Pistol" is the trickiest handgun signal — it must fire on
	 * "S&W M&P15 Pistol", "Ruger Charger Pistol", etc., but NOT
	 * on "Maverick 88 ... Pistol Grip Stock". Uses a negative
	 * lookahead: "pistol" NOT followed by whitespace + "grip".
	 *
	 * Other handgun signals are unambiguous — plain substring/regex.
	 */
	protected static function hasHandgunOverride( string $haystack, string $title, float $barrel ): ?string
	{
		/* "Pistol" — negative-lookahead against "Pistol Grip", also
		   guards against " Pistol Grip Stock" / " Pistol-Grip". */
		if ( preg_match( '/\bpistol\b(?!\s*[-]?\s*grip)/i', $haystack ) )
		{
			return 'pistol';
		}

		foreach ( self::HANDGUN_SIGNAL_PATTERNS as $pat )
		{
			if ( preg_match( '/' . $pat . '/i', $haystack, $m ) )
			{
				return (string) $m[0];
			}
		}

		/* "Arm Brace" + short barrel is a strong AR-pistol signal —
		   "short" = <12" here (AR-pistol threshold, roomy). */
		if ( preg_match( '/\barm\s*brace\b/i', $haystack ) && $barrel > 0 && $barrel < 12.0 )
		{
			return sprintf( 'arm brace + %.1f" barrel', $barrel );
		}

		/* Cylinder round-count (5rd/6rd/etc.) combined with "Grips"
		   (NOT "Stock") is a black-powder / cap-and-ball revolver
		   signal — catches the Traditions BP6001 case. */
		if ( preg_match( '/\b[3-9]rd\b/i', $haystack )
			&& preg_match( '/\bgrips\b/i', $haystack )
			&& !preg_match( '/\bstock\b/i', $haystack ) )
		{
			return 'cylinder round-count + grips (no stock)';
		}

		return null;
	}

	/**
	 * Curated override lookup. Rule #34: use preparedQuery with
	 * `?` binds — never concat pattern strings into raw SQL.
	 */
	protected static function curatedOverride( string $haystack, string $upc ): ?array
	{
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_catalog_platform_overrides' ) as $ov )
			{
				$pat = trim( (string) ( $ov['pattern'] ?? '' ) );
				if ( $pat === '' ) { continue; }
				$patLower = strtolower( $pat );
				if ( str_contains( $haystack, $patLower ) || $upc === $pat )
				{
					$target = (int) ( $ov['target_category_id'] ?? 0 );
					if ( $target <= 0 ) { continue; }
					$verdict = ( $target === self::CAT_HANDGUN ) ? 'stay' : 'reclassify';
					return self::verdict(
						$verdict, $target, 'curated', $pat,
						'curated override: ' . $pat
					);
				}
			}
		}
		catch ( \Throwable ) {}
		return null;
	}

	protected static function firstMatch( string $haystack, array $patterns ): ?string
	{
		foreach ( $patterns as $pat )
		{
			if ( preg_match( '/' . $pat . '/i', $haystack, $m ) )
			{
				return (string) $m[0];
			}
		}
		return null;
	}

	protected static function verdict( string $verdict, ?int $target, string $source, string $signal, string $hint ): array
	{
		return [
			'verdict'            => $verdict,
			'target_category_id' => $target,
			'source'             => $source,
			'signal'             => $signal,
			'reason_hint'        => $hint,
		];
	}

	/**
	 * Batch entry point — walk every active product in category 1
	 * and classify each. In DRY-RUN mode, returns counts + a small
	 * sample of the "would reclassify" list WITHOUT writing anything.
	 * In LIVE mode, writes the reclassifications, populates the
	 * review queue for ambiguous rows, and logs every change to
	 * gd_catalog_platform_reclass_log for auditability/rollback.
	 *
	 * @param bool $liveRun  false = report only, true = commit.
	 * @param int  $sampleN  # of "would reclassify" rows to include
	 *                       in the dry-run sample (dropped in live).
	 * @return array{
	 *   total:int,
	 *   would_reclassify_rifle:int,
	 *   would_reclassify_shotgun:int,
	 *   would_stay_handgun:int,
	 *   would_review:int,
	 *   sample:array,
	 *   errors:int,
	 *   mode:string
	 * }
	 */
	public static function runOnCategory1( bool $liveRun = false, int $sampleN = 25 ): array
	{
		$stats = [
			'total'                     => 0,
			'would_reclassify_rifle'    => 0,
			'would_reclassify_shotgun'  => 0,
			'would_stay_handgun'        => 0,
			'would_review'              => 0,
			'sample'                    => [],
			'errors'                    => 0,
			'mode'                      => $liveRun ? 'live' : 'dry-run',
		];

		try
		{
			$rows = \IPS\Db::i()->select(
				'upc, title, brand, caliber, mpn, model, barrel_length, category_id',
				'gd_catalog',
				[ 'category_id=? AND record_status=?', self::CAT_HANDGUN, 'active' ]
			);
		}
		catch ( \Throwable $e )
		{
			$stats['errors']++;
			return $stats;
		}

		$now = time();

		foreach ( $rows as $row )
		{
			$stats['total']++;

			try
			{
				$result = self::classify( (array) $row );
			}
			catch ( \Throwable $e )
			{
				$stats['errors']++;
				try { \IPS\Log::log( 'PlatformClassifier classify ' . $row['upc'] . ': ' . $e->getMessage(), 'gdcatalog_platform' ); } catch ( \Throwable ) {}
				continue;
			}

			$target = $result['target_category_id'];

			if ( $result['verdict'] === 'reclassify' && $target === self::CAT_RIFLE )   { $stats['would_reclassify_rifle']++; }
			elseif ( $result['verdict'] === 'reclassify' && $target === self::CAT_SHOTGUN ) { $stats['would_reclassify_shotgun']++; }
			elseif ( $result['verdict'] === 'stay' )                                    { $stats['would_stay_handgun']++; }
			else                                                                        { $stats['would_review']++; }

			if ( count( $stats['sample'] ) < $sampleN
				&& $result['verdict'] === 'reclassify' )
			{
				$stats['sample'][] = [
					'upc'    => $row['upc'],
					'title'  => mb_substr( (string) $row['title'], 0, 100 ),
					'target' => $target,
					'signal' => $result['signal'],
				];
			}

			if ( !$liveRun ) { continue; }

			/* LIVE MODE — commit + log. */
			if ( $result['verdict'] === 'reclassify' && $target !== null )
			{
				try
				{
					\IPS\Db::i()->update( 'gd_catalog',
						[ 'category_id' => $target, 'updated_at' => date( 'Y-m-d H:i:s', $now ) ],
						[ 'upc=?', (string) $row['upc'] ]
					);
					\IPS\Db::i()->insert( 'gd_catalog_platform_reclass_log', [
						'upc'             => (string) $row['upc'],
						'old_category_id' => self::CAT_HANDGUN,
						'new_category_id' => $target,
						'source'          => $result['source'],
						'signal'          => $result['signal'],
						'created_at'      => $now,
					] );
				}
				catch ( \Throwable $e )
				{
					$stats['errors']++;
					try { \IPS\Log::log( 'PlatformClassifier reclass ' . $row['upc'] . ': ' . $e->getMessage(), 'gdcatalog_platform' ); } catch ( \Throwable ) {}
				}
			}
			elseif ( $result['verdict'] === 'review' )
			{
				try
				{
					\IPS\Db::i()->insert( 'gd_catalog_platform_review', [
						'upc'                    => (string) $row['upc'],
						'current_category_id'    => self::CAT_HANDGUN,
						'suggested_category_id'  => null,
						'reason_hint'            => mb_substr( (string) $result['reason_hint'], 0, 255 ),
						'title_snapshot'         => mb_substr( (string) $row['title'], 0, 255 ),
						'brand_snapshot'         => mb_substr( (string) ( $row['brand'] ?? '' ), 0, 120 ),
						'resolved'               => 0,
						'created_at'             => $now,
					], TRUE );
				}
				catch ( \Throwable $e )
				{
					$stats['errors']++;
					try { \IPS\Log::log( 'PlatformClassifier review-queue ' . $row['upc'] . ': ' . $e->getMessage(), 'gdcatalog_platform' ); } catch ( \Throwable ) {}
				}
			}
			/* verdict='stay' — no write needed, row already in cat 1. */
		}

		return $stats;
	}
}
class PlatformClassifier extends _PlatformClassifier {}
