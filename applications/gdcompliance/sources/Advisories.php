<?php
/**
 * @brief  GD Compliance — buyer-permit ADVISORY classifier (v1.6.17)
 *
 * Advisories are NOT restrictions. Every existing gd_compliance_flags
 * row means "cannot ship to {state}". An advisory row means: the item
 * CAN ship; the buyer must complete a state-specific permit / card /
 * training step at the FFL before purchase. Rendered YELLOW/AMBER on
 * the storefront, never in the red "cannot ship to:" banner.
 *
 * Verified law (July 2026 — do NOT deviate):
 *
 *   CO — SB25-003 "Specified Semiautomatic Firearm" (SSF) — effective
 *        2026-08-01. Buyer must complete a safety course and hold a
 *        sheriff-issued eligibility card to purchase a semi-auto rifle
 *        or shotgun with detachable magazine, OR a gas-operated semi-
 *        auto handgun with detachable magazine. Exemptions: .22 rimfire
 *        (unless a separate lower/upper), manual actions (bolt / pump /
 *        lever), fixed magazine ≤15, recoil-operated handguns.
 *
 *   MN — Minn. Stat. § 624.712 "Semiautomatic Military-Style Assault
 *        Weapon" (SAMSAW) — in effect since 2023-08-01. Buyer must
 *        hold a Permit to Purchase or Permit to Carry; 30-day dealer
 *        waiting period may apply. Scope ≈ AR/AK-pattern rifles + a
 *        statutory named list; overlaps closely with what the existing
 *        AWB engine detects for rifles.
 *
 * Neither is a sale ban. NEVER emit a restrict flag for these states
 * via the advisory path; that's a customer-facing lie.
 *
 * Storage: gd_compliance_flags with firearm_type='advisory'. Flows
 * through the crash-safe stage/swap flag rebuild; Flag::forUpc()
 * classifies these as TYPE_ADVISORY so the storefront can split them
 * from restrict rows.
 *
 * Per-state config: gd_compliance_advisory_rules (state_code +
 * firearm_class, enabled, reason text, citation, effective_date).
 * Reason text is editable in the ACP.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Advisories
{
	/**
	 * Rimfire caliber tokens. If ANY appears in caliber (or title
	 * fallback), the row is rimfire and DOES NOT trigger a CO advisory
	 * (SB25-003 exempts rimfire; a separate rimfire lower/upper still
	 * would but that's not detectable at scale — conservative skip).
	 *
	 * @var string[]
	 */
	const RIMFIRE_TOKENS = [
		'.22 lr', '.22lr', '22 lr', '22lr', '.22 long', '22 long',
		'.17 hmr', '17 hmr', '.17hmr',
		'.22 wmr', '22 wmr', '.22 magnum', '22 magnum',
		'.17 wsm', '17 wsm',
		'rimfire',
	];

	/**
	 * Manual-action tokens. Any hit → NOT a semi-auto SSF; skip
	 * advisory.
	 *
	 * @var string[]
	 */
	const MANUAL_ACTION_TOKENS = [
		'bolt action', 'bolt-action', 'boltaction',
		'lever action', 'lever-action',
		'pump action', 'pump-action',
		'single shot', 'single-shot',
		'break action', 'break-action',
	];

	/**
	 * Fixed / internal / tube magazine tokens. Any hit → not a
	 * detachable-mag SSF; skip advisory.
	 *
	 * @var string[]
	 */
	const FIXED_MAG_TOKENS = [
		'tube magazine', 'tubular magazine', 'tube mag ',
		'fixed magazine', 'fixed mag ',
		'internal magazine', 'internal mag ',
		'en bloc',
	];

	/** Per-request rule cache, keyed by state_code[firearm_class]. */
	protected static ?array $ruleCache = null;

	/**
	 * All enabled advisory rules keyed by state_code + firearm_class.
	 * Silent on any DB error (missing table on a partial upgrade →
	 * empty cache, no advisories emitted).
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	protected static function loadRules(): array
	{
		if ( static::$ruleCache !== null ) { return static::$ruleCache; }
		static::$ruleCache = [];
		try
		{
			$today = date( 'Y-m-d' );
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_advisory_rules', [ 'enabled=?', 1 ] ) as $row )
			{
				$sc  = strtoupper( (string) ( $row['state_code']    ?? '' ) );
				$cls = strtolower( (string) ( $row['firearm_class'] ?? '' ) );
				if ( $sc === '' || $cls === '' ) { continue; }
				$eff = (string) ( $row['effective_date'] ?? '' );
				if ( $eff !== '' && $eff > $today ) { continue; }
				static::$ruleCache[ $sc ][ $cls ] = $row;
			}
		}
		catch ( \Throwable ) { /* table missing → empty */ }
		return static::$ruleCache;
	}

	/**
	 * States (uppercased) with any enabled advisory rule that is
	 * currently in effect.
	 *
	 * @return string[]
	 */
	public static function enabledStates(): array
	{
		$rules = self::loadRules();
		return array_keys( $rules );
	}

	/**
	 * The advisory rule row for a state + firearm class, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function ruleFor( string $stateCode, string $firearmClass ): ?array
	{
		$rules = self::loadRules();
		return $rules[ strtoupper( $stateCode ) ][ strtolower( $firearmClass ) ] ?? null;
	}

	/**
	 * Detect whether a haystack contains ANY of the given tokens.
	 */
	protected static function anyToken( string $haystack, array $tokens ): bool
	{
		foreach ( $tokens as $t )
		{
			if ( $t !== '' && strpos( $haystack, strtolower( $t ) ) !== false )
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * Match a product against every enabled advisory rule and return
	 * an array of hits. Each hit: [ 'state' => 'CO', 'reason' => '...',
	 * 'citation' => '...' ].
	 *
	 * Detection rules:
	 *   RIFLE class (CO SB25-003, MN §624.712, and any future
	 *     rifle-class rules): action_type contains 'semi', NOT
	 *     rimfire, NOT manual action, NOT fixed-mag / tube / internal.
	 *     Excludes handguns for now — CO's gas-operated-handgun subset
	 *     is not reliably detectable from catalog metadata.
	 *
	 *   AMMO class (v1.6.47): applies to EVERY product that classifies
	 *     as top-level cat 23 (Ammunition). No product-specific gating —
	 *     state ammo rules (IL FOID, CA Prop 63, CT permit, NY SAFE,
	 *     etc.) apply to all ammunition sold in-state. The per-state
	 *     rule row carries the buyer-facing "verify current law"
	 *     language.
	 *
	 *   KNIFE class (v1.6.47): applies to EVERY product that classifies
	 *     as top-level cat 138 (Knives). Automatic / switchblade /
	 *     balisong restrictions vary by state; the rule row's reason
	 *     text points buyers at their own state's statute.
	 *
	 * Shotgun rifles: skipped for now (fixed-tube-mag is the norm;
	 * false positives outweigh true positives without careful title
	 * parse). Derrick can layer in shotguns via a curated list later.
	 *
	 * @param array<string, mixed> $p  catalog row
	 * @param string               $firearmType  'handgun' | 'rifle' | 'shotgun' | 'ammo' | 'knife'
	 * @return array<int, array{state:string,firearm_class:string,reason:string,citation:string}>
	 */
	public static function matchesFor( array $p, string $firearmType ): array
	{
		$out = [];

		/* Ammo and knife: every product of the class carries the
		   state advisory. No product-specific gates (state ammo /
		   knife statutes apply broadly to the class). One row per
		   state that has an enabled rule for the class. */
		if ( $firearmType === 'ammo' || $firearmType === 'knife' )
		{
			$rules = self::loadRules();
			if ( empty( $rules ) ) { return $out; }
			foreach ( $rules as $state => $byClass )
			{
				$row = $byClass[ $firearmType ] ?? null;
				if ( !is_array( $row ) ) { continue; }
				$out[] = [
					'state'         => (string) $state,
					'firearm_class' => $firearmType,
					'reason'        => (string) ( $row['reason']   ?? '' ),
					'citation'      => (string) ( $row['citation'] ?? '' ),
				];
			}
			return $out;
		}

		if ( $firearmType !== 'rifle' ) { return $out; } /* firearm classes: rifles only for now */

		$rules = self::loadRules();
		if ( empty( $rules ) ) { return $out; }

		/* Semi-auto gate. */
		$act = strtolower( trim( (string) ( $p['action_type'] ?? '' ) ) );
		if ( strpos( $act, 'semi' ) === false ) { return $out; }

		/* Haystack for token checks (title + caliber + description). */
		$title = strtolower( (string) ( $p['title'] ?? '' ) );
		$cal   = strtolower( (string) ( $p['caliber'] ?? '' ) );
		$desc  = strtolower( (string) ( $p['description'] ?? '' ) );
		$hay   = $title . ' | ' . $cal . ' | ' . $desc;

		/* Rimfire exclusion (CO §, MN by default rifle-class). */
		if ( self::anyToken( $hay, self::RIMFIRE_TOKENS ) ) { return $out; }

		/* Manual-action exclusion — action_type sometimes reads
		   "semi" for the family and the title clarifies "bolt". */
		if ( self::anyToken( $hay, self::MANUAL_ACTION_TOKENS ) ) { return $out; }

		/* Fixed / tube / internal magazine — a rifle with these is
		   not a detachable-mag SSF. */
		if ( self::anyToken( $hay, self::FIXED_MAG_TOKENS ) ) { return $out; }

		/* Passed all filters — emit an advisory for every state with
		   an enabled rifle rule. */
		foreach ( $rules as $state => $byClass )
		{
			$row = $byClass['rifle'] ?? null;
			if ( !is_array( $row ) ) { continue; }
			$out[] = [
				'state'         => (string) $state,
				'firearm_class' => 'rifle',
				'reason'        => (string) ( $row['reason']   ?? '' ),
				'citation'      => (string) ( $row['citation'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Reset the rule cache — called from install / upgrade / admin
	 * edits so a per-request lookup picks up fresh data.
	 */
	public static function clearCache(): void
	{
		static::$ruleCache = null;
	}
}

class Advisories extends _Advisories {}
