<?php
/**
 * @brief  GD Compliance — Reason string builder (v1.6.52)
 *
 * Centralizes the exact sprintf() patterns that Engine::computeFlags()
 * uses when it writes each auto-generated flag row's `reason` column.
 * The Manual Flag tool (modules/admin/compliance/lookup.php ~
 * mflag panel) calls the same methods so its manual flags are
 * byte-identical to auto-flags — admins editing a manual reason
 * see the same text auto-compute would have produced.
 *
 * Every method here is a PURE FUNCTION: no DB queries, no cache,
 * no side-effects. Given the same inputs it always returns the same
 * string. That's the point — the source of truth for reason text
 * lives in one place; Engine.php's copies (line ~519 awb_lower,
 * ~816 awb rifle Tier 1, ~825 Tier 2, ~649 magazine, ~1051 fixed-
 * mag, ~988 melting_point, ~720 rate_of_fire) can be refactored to
 * call these at their next visit without changing behavior.
 *
 * Advisory reasons come straight from gd_compliance_advisory_rules
 * (the admin-editable per-state per-class reason column), so
 * ::advisory() is a trivial passthrough.
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _ReasonBuilder
{
	/**
	 * awb_lower — Engine.php ~line 519 verbatim.
	 * "AR/AK-pattern lower receiver — restricted assault-weapon
	 *  component under {STATE} law ({cite}); matched pattern:
	 *  {pattern} [curated]"
	 */
	public static function awbLower( string $state, string $cite, string $pattern, bool $curated = false ): string
	{
		return sprintf(
			'AR/AK-pattern lower receiver — restricted assault-weapon component under %s law%s%s%s',
			$state,
			$cite    !== '' ? ' (' . $cite . ')'               : '',
			$pattern !== '' ? '; matched pattern: ' . $pattern : '',
			$curated ? ' [curated]' : ''
		);
	}

	/**
	 * awb rifle Tier 1 (roster-matched complete rifle) —
	 * Engine.php ~line 816 verbatim.
	 * "{STATE}-listed assault weapon ({cite}); model: {pattern}"
	 */
	public static function awbRifleTier1( string $state, string $cite, string $pattern ): string
	{
		return sprintf(
			'%s-listed assault weapon (%s); model: %s',
			$state,
			$cite !== '' ? $cite : 'state statute',
			$pattern !== '' ? $pattern : 'unknown'
		);
	}

	/**
	 * awb rifle Tier 2 (likely / feature-partial match) —
	 * Engine.php ~line 825 verbatim.
	 */
	public static function awbRifleTier2( string $state, string $cite, string $features = '' ): string
	{
		return 'Likely restricted under ' . $state . ' assault weapons law'
			. ( $cite !== '' ? ' (' . $cite . ')' : '' )
			. ' — semi-automatic centerfire rifle'
			. ( $features !== '' ? ' with ' . $features : '' )
			. '; verify features';
	}

	/**
	 * magazine (standalone LCM in cat38) — Engine.php ~line 649
	 * verbatim.
	 * "{cap}-round magazine exceeds {STATE} {class} limit of
	 *  {limit} rounds ({cite})"
	 */
	public static function magazine( int $cap, string $state, string $class, int $limit, string $cite = '' ): string
	{
		return sprintf(
			'%d-round magazine exceeds %s %s limit of %d rounds%s',
			$cap,
			$state,
			$class,
			$limit,
			$cite !== '' ? ' (' . $cite . ')' : ''
		);
	}

	/**
	 * fixed-mag firearm (rifle/handgun/shotgun w/ built-in
	 * over-limit magazine) — Engine.php ~line 1051 verbatim.
	 * "{Type} mag {cap} > {STATE} limit {limit}"
	 */
	public static function fixedMag( string $type, int $cap, string $state, int $limit ): string
	{
		return sprintf(
			'%s mag %d > %s limit %d',
			ucfirst( $type ),
			$cap,
			$state,
			$limit
		);
	}

	/**
	 * melting_point (zinc-alloy handgun ban) — Engine.php ~line 988.
	 * Uses the per-state rule's own reason text when non-empty;
	 * falls back to a generic template that names the state. Appends
	 * hint (auto source only) and [curated] provenance flags to match
	 * auto-flag behavior.
	 */
	public static function meltingPoint( string $state, ?string $ruleReason = null, string $hint = '', string $source = '' ): string
	{
		$msg = trim( (string) $ruleReason );
		if ( $msg === '' )
		{
			$msg = sprintf(
				'Handgun with a zinc-alloy / non-homogeneous frame that fails %s\'s minimum melting-point standard — prohibited for sale. Steel-frame models from this line are exempt.',
				$state
			);
		}
		if ( $hint !== '' && $source === 'auto' )
		{
			$msg .= ' [' . $hint . ']';
		}
		if ( $source === 'curated' )
		{
			$msg .= ' [curated]';
		}
		return $msg;
	}

	/**
	 * rate_of_fire (binary triggers / FRT / bump stocks / trigger
	 * cranks) — Engine.php ~line 720. Uses per-state rule reason
	 * when set, else generic template.
	 */
	public static function rateOfFire( string $state, ?string $ruleReason = null, string $hint = '', string $source = '' ): string
	{
		$msg = trim( (string) $ruleReason );
		if ( $msg === '' )
		{
			$msg = sprintf(
				'Rate-of-fire enhancement device (binary trigger / forced-reset trigger / bump stock / trigger crank) — prohibited for sale in %s.',
				$state
			);
		}
		if ( $hint !== '' && str_starts_with( (string) $source, 'auto' ) )
		{
			$msg .= ' [' . $hint . ']';
		}
		if ( $source === 'curated' )
		{
			$msg .= ' [curated]';
		}
		return $msg;
	}

	/**
	 * advisory — passes through the admin-edited reason from
	 * gd_compliance_advisory_rules verbatim (matches how
	 * Advisories::matchesFor() emits it).
	 */
	public static function advisory( array $ruleRow ): string
	{
		return trim( (string) ( $ruleRow['reason'] ?? '' ) );
	}
}
class ReasonBuilder extends _ReasonBuilder {}
