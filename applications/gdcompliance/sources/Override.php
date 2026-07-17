<?php
/**
 * @brief  GD Compliance — Manual overrides
 *
 * Phase 4 layer that survives recomputes. Every override in
 * gd_compliance_overrides is applied AFTER the rule-based engine writes
 * its flags, on every run. Force_restrict guarantees a flag row exists;
 * force_clear guarantees no flag row exists for that (upc, state)
 * combo — regardless of what the capacity + roster passes decided.
 *
 * The Phase-2/3 review-queue resolution also writes overrides so a
 * "Mark on-roster" / "Mark off-roster" decision persists across
 * recomputes without being erased by the rule sweep.
 *
 * Table is separate from gd_compliance_flags on purpose: flags are
 * volatile (wiped + rebuilt every compute); overrides are permanent
 * (human decisions).
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Override
{
	const ACTION_RESTRICT = 'force_restrict';
	const ACTION_CLEAR    = 'force_clear';
	const VALID_ACTIONS   = [ self::ACTION_RESTRICT, self::ACTION_CLEAR ];

	/**
	 * Create or update an override row for the (upc, state_code) pair.
	 * Because uq_upc_state is unique, a later save replaces an earlier one
	 * — that's the intended semantics: the newest decision wins.
	 *
	 *   Override::save('00007121', 'IL', 'force_restrict', 'AR-15 pistol', $memberId)
	 *
	 * If $applyImmediately is true, single-UPC apply mirrors the decision
	 * into gd_compliance_flags immediately so the effect is instant (no
	 * need for a full recompute).
	 *
	 * @return array{action:string,ok:bool,error:?string}
	 */
	public static function save( string $upc, string $stateCode, string $action, ?string $reason, ?int $memberId = null, bool $applyImmediately = true, ?string $firearmType = null ): array
	{
		$upc       = trim( $upc );
		$stateCode = strtoupper( trim( $stateCode ) );
		$action    = strtolower( trim( $action ) );

		if ( $upc === '' )                                          { return [ 'action' => 'skip', 'ok' => false, 'error' => 'empty upc' ]; }
		if ( !preg_match( '/^[A-Z]{2}$/', $stateCode ) )            { return [ 'action' => 'skip', 'ok' => false, 'error' => 'invalid state_code' ]; }
		if ( !in_array( $action, self::VALID_ACTIONS, true ) )      { return [ 'action' => 'skip', 'ok' => false, 'error' => 'invalid action' ]; }

		/* v1.6.51 — reason widened to 500 to match gd_compliance_flags.
		   firearm_type recorded on the override row so applyOne() writes
		   a flag with the auto-compute's own firearm_type (awb_lower /
		   awb_rifle / etc.) rather than a generic 'manual' label. */
		$row = [
			'upc'          => substr( $upc, 0, 50 ),
			'state_code'   => $stateCode,
			'action'       => $action,
			'reason'       => $reason !== null ? substr( $reason, 0, 500 ) : null,
			'firearm_type' => $firearmType !== null ? substr( $firearmType, 0, 20 ) : null,
			'created_by'   => $memberId,
			'created_at'   => time(),
		];

		$existingId = null;
		try
		{
			$existingId = (int) \IPS\Db::i()->select( 'id', 'gd_compliance_overrides',
				[ 'upc=? AND state_code=?', $row['upc'], $stateCode ]
			)->first();
		}
		catch ( \UnderflowException ) {}
		catch ( \Throwable ) {}

		try
		{
			if ( $existingId )
			{
				\IPS\Db::i()->update( 'gd_compliance_overrides', $row, [ 'id=?', $existingId ] );
				$op = 'update';
			}
			else
			{
				\IPS\Db::i()->insert( 'gd_compliance_overrides', $row );
				$op = 'insert';
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Override::save: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			return [ 'action' => 'error', 'ok' => false, 'error' => $e->getMessage() ];
		}

		if ( $applyImmediately )
		{
			self::applyOne( $row );
		}

		return [ 'action' => $op, 'ok' => true, 'error' => null ];
	}

	/**
	 * Remove the override for a (upc, state_code). Optionally recompute
	 * the flag for that UPC+state immediately from pure rule state (i.e.
	 * revert to whatever the engine's rule pass would produce, without
	 * running a full recompute).
	 *
	 * Since we don't cheaply know what rules WOULD produce for a single
	 * UPC without re-running the whole engine, we take the safe path:
	 * delete the override, delete any Manual-override flag row for that
	 * UPC+state. The next full recompute (or the next Override::apply
	 * pass, run after any computeFlags call) will re-populate rule-based
	 * flags. This never leaves a stale override in place.
	 */
	public static function remove( string $upc, string $stateCode ): array
	{
		$upc       = trim( $upc );
		$stateCode = strtoupper( trim( $stateCode ) );
		if ( $upc === '' || !preg_match( '/^[A-Z]{2}$/', $stateCode ) )
		{
			return [ 'ok' => false, 'error' => 'invalid upc / state' ];
		}
		try
		{
			\IPS\Db::i()->delete( 'gd_compliance_overrides', [ 'upc=? AND state_code=?', $upc, $stateCode ] );
			/* v1.6.51 — the flag row for this (upc, state) is the
			   override's own product; delete it here. A later full
			   recompute will restore rule-based flags if applicable.
			   Prior versions filtered by reason LIKE 'Manual
			   override:%' but v1.6.51 dropped that prefix so applyOne
			   writes reason verbatim; the LIKE would no-op. Deleting
			   the (upc, state) flag is safe because overrides are the
			   exclusive owner of that combo while active. */
			\IPS\Db::i()->delete( 'gd_compliance_flags', [ 'upc=? AND state_code=?', $upc, $stateCode ] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Override::remove: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			return [ 'ok' => false, 'error' => $e->getMessage() ];
		}
		return [ 'ok' => true, 'error' => null ];
	}

	/**
	 * Apply all overrides in one sweep. Called by Engine::computeFlags AFTER
	 * the rule-based flag writes so overrides always have final say.
	 *
	 * @return array{restrict:int,clear:int}
	 */
	public static function applyAll(): array
	{
		$counts = [ 'restrict' => 0, 'clear' => 0 ];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_overrides' ) as $r )
			{
				if ( self::applyOne( $r ) )
				{
					if ( (string) $r['action'] === self::ACTION_RESTRICT ) { $counts['restrict']++; }
					else                                                   { $counts['clear']++; }
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Override::applyAll: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		return $counts;
	}

	/**
	 * Apply a single override row to gd_compliance_flags. Idempotent —
	 * repeated calls converge to the same end state.
	 */
	public static function applyOne( array $override ): bool
	{
		$upc    = (string) ( $override['upc'] ?? '' );
		$state  = strtoupper( (string) ( $override['state_code'] ?? '' ) );
		$action = (string) ( $override['action'] ?? '' );
		$reason = (string) ( $override['reason'] ?? '' );
		if ( $upc === '' || $state === '' || !in_array( $action, self::VALID_ACTIONS, true ) ) { return false; }

		try
		{
			if ( $action === self::ACTION_CLEAR )
			{
				/* Wipe every flag for this upc + state — rule-based OR
				   prior manual restrict. */
				\IPS\Db::i()->delete( 'gd_compliance_flags', [ 'upc=? AND state_code=?', $upc, $state ] );
				return true;
			}

			/* force_restrict — ensure a flag exists. Wipe any prior rule-
			   based rows for the same upc+state first so we don't stack
			   duplicates on repeated applications; then insert one row
			   whose firearm_type matches whatever auto-compute would
			   have produced (awb_lower / awb_rifle / magazine /
			   advisory / etc., recorded on the override) so manual +
			   auto flags are indistinguishable in the flags table. Falls
			   back to 'manual' when the override row predates v1.6.51.
			   Reason is written verbatim (no "Manual override:" prefix)
			   so byte-identical formatting to auto-flags carries through. */
			$ftype = strtolower( trim( (string) ( $override['firearm_type'] ?? '' ) ) );
			if ( $ftype === '' ) { $ftype = 'manual'; }

			$writeReason = $reason !== '' ? $reason : 'force_restrict';

			\IPS\Db::i()->delete( 'gd_compliance_flags', [ 'upc=? AND state_code=?', $upc, $state ] );
			\IPS\Db::i()->insert( 'gd_compliance_flags', [
				'upc'             => $upc,
				'state_code'      => $state,
				'firearm_type'    => substr( $ftype, 0, 20 ),
				'parsed_capacity' => null,
				'rule_id'         => 0,
				'reason'          => substr( $writeReason, 0, 500 ),
				'computed_at'     => time(),
			] );
			return true;
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Override::applyOne: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			return false;
		}
	}

	/**
	 * Get current override for a (upc, state) or null.
	 */
	public static function get( string $upc, string $stateCode ): ?array
	{
		$upc       = trim( $upc );
		$stateCode = strtoupper( trim( $stateCode ) );
		if ( $upc === '' || !preg_match( '/^[A-Z]{2}$/', $stateCode ) ) { return null; }
		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_compliance_overrides',
				[ 'upc=? AND state_code=?', $upc, $stateCode ]
			)->first();
			return is_array( $row ) ? $row : null;
		}
		catch ( \Throwable ) { return null; }
	}

	/**
	 * All overrides for a UPC, keyed by state_code — used by the ACP lookup
	 * page to render the per-state status column.
	 */
	public static function forUpc( string $upc ): array
	{
		$out = [];
		$upc = trim( $upc );
		if ( $upc === '' ) { return $out; }
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_overrides', [ 'upc=?', $upc ] ) as $r )
			{
				$out[ (string) $r['state_code'] ] = $r;
			}
		}
		catch ( \Throwable ) {}
		return $out;
	}
}

class Override extends _Override {}
