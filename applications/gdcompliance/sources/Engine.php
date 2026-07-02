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
		];

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

		$now    = time();
		$flags  = [];

		try
		{
			foreach ( \IPS\Db::i()->select( 'upc, category_id, capacity, brand, manufacturer, model, caliber, mpn, title, action_type, stock_material, description', 'gd_catalog' ) as $p )
			{
				$result['processed']++;
				$upc = (string) ( $p['upc'] ?? '' );
				$cat = (int)    ( $p['category_id'] ?? 0 );
				if ( $upc === '' || $cat <= 0 ) { continue; }

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
						$awbStates = [];
						try { $awbStates = \IPS\gdcompliance\AwbModels::enabledStates( $awbClass ); }
						catch ( \Throwable ) {}

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

		$result['flags'] = count( $flags );

		if ( $dryRun )
		{
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
					foreach ( array_chunk( $flags, 500 ) as $ci => $chunk )
					{
						$chunkIdx = $ci;
						\IPS\Db::i()->insert( $stageTable, $chunk );
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
			foreach ( array_chunk( array_values( $fresh ), 500 ) as $chunk )
			{
				try { \IPS\Db::i()->insert( 'gd_compliance_review', $chunk ); }
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

		try { \IPS\Log::log( 'Engine::computeFlags complete: ' . json_encode( [
			'flags' => $result['flags'], 'per_state' => $result['per_state'],
		] ), 'gdcompliance' ); } catch ( \Throwable ) {}

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
