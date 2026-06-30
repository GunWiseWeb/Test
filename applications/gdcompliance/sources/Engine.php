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
			'per_state'      => [],
			'per_state_type' => [],
			'unparsed'       => [],
			'sample'         => [],
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
		   both off-roster AND over-capacity (two flags). Prime the cache once;
		   if the roster table is empty (Refresh hasn't run yet), the
		   classifier still works but every handgun lands in unmatched_review
		   for "no manufacturer match". */
		try { \IPS\gdcompliance\Roster::primeCache(); } catch ( \Throwable ) {}

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
			foreach ( \IPS\Db::i()->select( 'upc, category_id, capacity, manufacturer, model, caliber, mpn, title', 'gd_catalog' ) as $p )
			{
				$result['processed']++;
				$upc = (string) ( $p['upc'] ?? '' );
				$cat = (int)    ( $p['category_id'] ?? 0 );
				if ( $upc === '' || $cat <= 0 ) { continue; }

				$type = $typeMap[ $cat ] ?? null;
				if ( $type === null ) { continue; }
				$result['firearms']++;

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
							$state  = (string) $r['state_code'];
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

				/* --- Phase 2: CA roster pass (handguns only) --- */
				if ( $type === 'handgun' )
				{
					try
					{
						$cls = \IPS\gdcompliance\Roster::classifyHandgun( $p );
					}
					catch ( \Throwable )
					{
						$cls = [ 'status' => 'unmatched_review', 'reason' => 'classifier error', 'confidence' => 'none', 'matched_roster_id' => null, 'candidates' => [] ];
					}

					$status = (string) ( $cls['status'] ?? 'unmatched_review' );
					if ( $status === 'on_roster' )
					{
						$result['roster']['on']++;
					}
					elseif ( $status === 'off_roster' )
					{
						$result['roster']['off']++;
						$flags[] = [
							'upc'             => substr( $upc, 0, 50 ),
							'state_code'      => 'CA',
							'firearm_type'    => 'handgun',
							'parsed_capacity' => null,
							'rule_id'         => 0,
							'reason'          => substr( 'Not on CA DOJ roster — ' . ( $cls['reason'] ?? '' ), 0, 255 ),
							'computed_at'     => $now,
						];
						$result['per_state']['CA'] = ( $result['per_state']['CA'] ?? 0 ) + 1;
						$result['per_state_type']['CA']['handgun_roster'] = ( $result['per_state_type']['CA']['handgun_roster'] ?? 0 ) + 1;
					}
					else /* unmatched_review */
					{
						$result['roster']['review']++;
						$result['review_queue'][] = [
							'upc'              => substr( $upc, 0, 50 ),
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
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'Engine::computeFlags scan: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$result['flags'] = count( $flags );

		if ( $dryRun )
		{
			return $result;
		}

		/* Real run — replace prior flags + unparsed tallies. */
		try
		{
			\IPS\Db::i()->delete( 'gd_compliance_flags' );
		}
		catch ( \Throwable ) {}

		if ( !empty( $flags ) )
		{
			/* Batch insert in chunks of 500 so a 100k-row write doesn't bloat
			   memory or hit max_allowed_packet. */
			$chunks = array_chunk( $flags, 500 );
			foreach ( $chunks as $chunk )
			{
				try
				{
					\IPS\Db::i()->insert( 'gd_compliance_flags', $chunk );
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'Engine::computeFlags insert: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
				}
			}
		}

		/* Refresh review queue — preserve previously RESOLVED rows so Derrick's
		   manual decisions stick. Wipe only the unresolved rows + re-insert
		   the freshly classified unmatched_review handguns. */
		try { \IPS\Db::i()->delete( 'gd_compliance_review', [ 'resolved=0' ] ); } catch ( \Throwable ) {}
		if ( !empty( $result['review_queue'] ) )
		{
			$existing = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'upc', 'gd_compliance_review', [ 'resolved=1' ] ) as $row )
				{
					$existing[ (string) ( is_array( $row ) ? ( $row['upc'] ?? '' ) : $row ) ] = true;
				}
			}
			catch ( \Throwable ) {}

			$fresh = array_filter( $result['review_queue'], fn( $r ) => empty( $existing[ $r['upc'] ?? '' ] ) );
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

		try { \IPS\Settings::i()->changeValues( [
			'gdcompliance_last_run'    => date( 'Y-m-d H:i:s', $now ),
			'gdcompliance_last_counts' => json_encode( [
				'processed' => $result['processed'],
				'firearms'  => $result['firearms'],
				'flags'     => $result['flags'],
				'per_state' => $result['per_state'],
			] ),
		] ); } catch ( \Throwable ) {}

		try { \IPS\Log::log( 'Engine::computeFlags complete: ' . json_encode( [
			'flags' => $result['flags'], 'per_state' => $result['per_state'],
		] ), 'gdcompliance' ); } catch ( \Throwable ) {}

		return $result;
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
