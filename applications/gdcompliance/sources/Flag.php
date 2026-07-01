<?php
/**
 * @brief  GD Compliance — Flag lookup + notice-render helpers
 *
 * Read-only API the storefront (gdsearch product page, catalog listings,
 * widgets, etc.) calls to surface "Restricted for sale into: …" panels.
 * Stays loosely coupled — no writes, no hard template wiring. When
 * gdcompliance is disabled or the flags table is missing, every helper
 * returns an empty / no-op result so callers gracefully show nothing.
 *
 * Type derivation (single source of truth):
 *   firearm_type='manual' AND rule_id=0   → 'override'
 *   rule_id > 0                           → 'capacity'
 *   otherwise                             → 'roster'
 */

namespace IPS\gdcompliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Flag
{
	/** @var array<string, array<int, array{state:string,reason:string,type:string}>>  Per-request cache. */
	protected static array $cache = [];

	/** @var bool  Emitted the shared <style> block once this request. */
	protected static bool $stylePrinted = false;

	const TYPE_CAPACITY = 'capacity';
	const TYPE_ROSTER   = 'roster';
	const TYPE_OVERRIDE = 'override';

	/**
	 * US state abbreviation → full name (50 states + DC). Used by
	 * forUpc() to populate state_name so callers can render "California"
	 * as a popup heading without a lookup table on their side.
	 *
	 * @var array<string, string>
	 */
	const STATE_NAMES = [
		'AL' => 'Alabama',       'AK' => 'Alaska',       'AZ' => 'Arizona',       'AR' => 'Arkansas',
		'CA' => 'California',    'CO' => 'Colorado',     'CT' => 'Connecticut',   'DE' => 'Delaware',
		'DC' => 'District of Columbia',
		'FL' => 'Florida',       'GA' => 'Georgia',      'HI' => 'Hawaii',        'ID' => 'Idaho',
		'IL' => 'Illinois',      'IN' => 'Indiana',      'IA' => 'Iowa',          'KS' => 'Kansas',
		'KY' => 'Kentucky',      'LA' => 'Louisiana',    'ME' => 'Maine',         'MD' => 'Maryland',
		'MA' => 'Massachusetts', 'MI' => 'Michigan',     'MN' => 'Minnesota',     'MS' => 'Mississippi',
		'MO' => 'Missouri',      'MT' => 'Montana',      'NE' => 'Nebraska',      'NV' => 'Nevada',
		'NH' => 'New Hampshire', 'NJ' => 'New Jersey',   'NM' => 'New Mexico',    'NY' => 'New York',
		'NC' => 'North Carolina','ND' => 'North Dakota', 'OH' => 'Ohio',          'OK' => 'Oklahoma',
		'OR' => 'Oregon',        'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',  'SC' => 'South Carolina',
		'SD' => 'South Dakota',  'TN' => 'Tennessee',    'TX' => 'Texas',         'UT' => 'Utah',
		'VT' => 'Vermont',       'VA' => 'Virginia',     'WA' => 'Washington',    'WV' => 'West Virginia',
		'WI' => 'Wisconsin',     'WY' => 'Wyoming',
	];

	/**
	 * Restricted-state rows for a UPC.
	 *
	 *   Flag::forUpc('00007121') → [
	 *     ['state'=>'CA','state_name'=>'California','reason'=>'Not on CA DOJ roster','type'=>'roster','citation'=>''],
	 *     ['state'=>'IL','state_name'=>'Illinois','reason'=>'Handgun mag 17 > IL limit 15','type'=>'capacity','citation'=>'PA 102-1116 (Protect Illinois Communities Act, 2023)'],
	 *   ]
	 *
	 * citation is pulled from gd_compliance_rules.source_note via
	 * LEFT JOIN on rule_id. Roster / override rows have rule_id = 0 so
	 * their citation is '' — callers should hide the citation line when
	 * empty rather than rendering "no citation".
	 *
	 * @return array<int, array{state:string,state_name:string,reason:string,type:string,citation:string}>
	 */
	public static function forUpc( string $upc ): array
	{
		$upc = trim( $upc );
		if ( $upc === '' ) { return []; }

		if ( isset( static::$cache[ $upc ] ) ) { return static::$cache[ $upc ]; }

		$out = [];
		try
		{
			$prefix = (string) \IPS\Db::i()->prefix;
			$sql    = "SELECT f.state_code, f.firearm_type, f.rule_id, f.reason, r.source_note
				FROM " . $prefix . "gd_compliance_flags f
				LEFT JOIN " . $prefix . "gd_compliance_rules r ON r.id = f.rule_id AND f.rule_id > 0
				WHERE f.upc = ?
				ORDER BY f.state_code ASC";
			$res = \IPS\Db::i()->preparedQuery( $sql, [ $upc ] );
			if ( $res )
			{
				while ( $row = $res->fetch_assoc() )
				{
					$state = strtoupper( (string) ( $row['state_code'] ?? '' ) );
					if ( $state === '' ) { continue; }

					$ftype  = (string) ( $row['firearm_type'] ?? '' );
					$ruleId = (int)    ( $row['rule_id']      ?? 0 );
					$reason = trim( (string) ( $row['reason'] ?? '' ) );
					$cite   = trim( (string) ( $row['source_note'] ?? '' ) );

					$type = static::TYPE_ROSTER;
					if ( $ftype === 'manual' && $ruleId === 0 )
					{
						$type = static::TYPE_OVERRIDE;
					}
					elseif ( $ruleId > 0 )
					{
						$type = static::TYPE_CAPACITY;
					}

					$out[] = [
						'state'      => $state,
						'state_name' => static::STATE_NAMES[ $state ] ?? $state,
						'reason'     => $reason,
						'type'       => $type,
						'citation'   => $cite,
					];
				}
			}
		}
		catch ( \Throwable )
		{
			/* Fallback if preparedQuery isn't available for any reason —
			   two separate queries, in-memory JOIN. Same result shape. */
			try
			{
				$ruleCite = [];
				try
				{
					foreach ( \IPS\Db::i()->select( 'id, source_note', 'gd_compliance_rules' ) as $r )
					{
						$ruleCite[ (int) ( $r['id'] ?? 0 ) ] = (string) ( $r['source_note'] ?? '' );
					}
				}
				catch ( \Throwable ) {}

				foreach ( \IPS\Db::i()->select(
					'state_code, firearm_type, rule_id, reason',
					'gd_compliance_flags',
					[ 'upc=?', $upc ],
					'state_code ASC'
				) as $row )
				{
					$state = strtoupper( (string) ( $row['state_code'] ?? '' ) );
					if ( $state === '' ) { continue; }

					$ftype  = (string) ( $row['firearm_type'] ?? '' );
					$ruleId = (int)    ( $row['rule_id']      ?? 0 );
					$reason = trim( (string) ( $row['reason'] ?? '' ) );

					$type = static::TYPE_ROSTER;
					if ( $ftype === 'manual' && $ruleId === 0 )
					{
						$type = static::TYPE_OVERRIDE;
					}
					elseif ( $ruleId > 0 )
					{
						$type = static::TYPE_CAPACITY;
					}

					$out[] = [
						'state'      => $state,
						'state_name' => static::STATE_NAMES[ $state ] ?? $state,
						'reason'     => $reason,
						'type'       => $type,
						'citation'   => trim( (string) ( $ruleCite[ $ruleId ] ?? '' ) ),
					];
				}
			}
			catch ( \Throwable ) { $out = []; }
		}

		return static::$cache[ $upc ] = $out;
	}

	/**
	 * Compact summary for badges / listing grids.
	 *
	 *   Flag::summaryForUpc('00007121') → ['states'=>['CA','IL','NY'], 'count'=>3]
	 *
	 * @return array{states:string[],count:int}
	 */
	public static function summaryForUpc( string $upc ): array
	{
		$rows = static::forUpc( $upc );
		$states = [];
		foreach ( $rows as $r )
		{
			$s = $r['state'];
			if ( !in_array( $s, $states, true ) ) { $states[] = $s; }
		}
		sort( $states );
		return [ 'states' => $states, 'count' => count( $states ) ];
	}

	/**
	 * Detail rows for a UPC (raw column shape — different clients prefer
	 * different keys). Kept for admin/detail views that want the fields
	 * literally as stored.
	 *
	 * @return array<int, array{state_code:string,firearm_type:string,parsed_capacity:?int,rule_id:int,reason:?string}>
	 */
	public static function detailsForUpc( string $upc ): array
	{
		$upc = trim( $upc );
		if ( $upc === '' ) { return []; }

		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'state_code, firearm_type, parsed_capacity, rule_id, reason',
				'gd_compliance_flags', [ 'upc=?', $upc ], 'state_code ASC'
			) as $row )
			{
				$out[] = $row;
			}
		}
		catch ( \Throwable ) {}

		return $out;
	}

	/**
	 * Bulk-load restricted state codes for many UPCs in one query — for
	 * catalog-list rendering.
	 *
	 *   Flag::forUpcs(['00007121','00019827']) → [
	 *     '00007121' => ['CA','IL'],
	 *     '00019827' => ['NY'],
	 *   ]
	 *
	 * @param string[] $upcs
	 * @return array<string, string[]>
	 */
	public static function forUpcs( array $upcs ): array
	{
		$out  = [];
		$upcs = array_values( array_filter( array_map( 'strval', $upcs ), fn( $u ) => trim( $u ) !== '' ) );
		if ( empty( $upcs ) ) { return $out; }

		try
		{
			$placeholders = implode( ',', array_fill( 0, count( $upcs ), '?' ) );
			$args         = array_merge( [ "upc IN ({$placeholders})" ], $upcs );
			foreach ( \IPS\Db::i()->select( 'upc, state_code', 'gd_compliance_flags', $args, 'upc ASC, state_code ASC' ) as $row )
			{
				$u  = (string) $row['upc'];
				$st = strtoupper( (string) $row['state_code'] );
				if ( $u === '' || $st === '' ) { continue; }
				if ( !isset( $out[ $u ] ) || !in_array( $st, $out[ $u ], true ) )
				{
					$out[ $u ][] = $st;
				}
			}
		}
		catch ( \Throwable ) {}

		return $out;
	}

	/**
	 * Render the "Sales Restrictions" notice partial for a UPC, ready to
	 * embed in ANY IPS template with one line:
	 *
	 *   {expression="\\IPS\\gdcompliance\\Flag::renderNotice( $product['upc'] )"}
	 *
	 * Returns empty string when:
	 *   - gdcompliance_front_enabled = 0 (kill switch)
	 *   - UPC is empty
	 *   - the product has zero restrictions (clean product pages)
	 *   - the notice template can't render (defensive — no crashes)
	 *
	 * This is the single loose-coupling touch-point. gdcatalog / gdsearch
	 * templates need at most one call to this helper; removing gdcompliance
	 * turns the call into a no-op via class_exists check on the caller side.
	 */
	public static function renderNotice( string $upc ): string
	{
		try
		{
			$enabled = (int) ( \IPS\Settings::i()->gdcompliance_front_enabled ?? 1 );
			if ( !$enabled ) { return ''; }
		}
		catch ( \Throwable ) { /* setting missing → treat as enabled */ }

		$rows = static::forUpc( $upc );
		if ( empty( $rows ) ) { return ''; }

		$showReasons = 1;
		try { $showReasons = (int) ( \IPS\Settings::i()->gdcompliance_front_show_reasons ?? 1 ); }
		catch ( \Throwable ) {}

		$disclaimer = '';
		try { $disclaimer = (string) ( \IPS\Settings::i()->gdcompliance_front_disclaimer ?? '' ); }
		catch ( \Throwable ) {}

		if ( $disclaimer === '' )
		{
			try { $disclaimer = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_front_disclaimer' ); }
			catch ( \Throwable ) { $disclaimer = 'Restrictions are provided as guidance and may not reflect the most current law; verify before purchase.'; }
		}

		$summary = static::summaryForUpc( $upc );

		try
		{
			$html = (string) \IPS\Theme::i()->getTemplate( 'compliance', 'gdcompliance', 'front' )
				->restrictionNotice( $rows, $summary, (bool) $showReasons, $disclaimer );
		}
		catch ( \Throwable ) { return ''; }

		return static::styleBlock() . $html;
	}

	/**
	 * Emit the scoped CSS block exactly ONCE per request. Reads from the
	 * interface/ file so styling lives in one editable place.
	 */
	protected static function styleBlock(): string
	{
		if ( static::$stylePrinted ) { return ''; }
		static::$stylePrinted = true;

		$css = '';
		try
		{
			$path = \IPS\ROOT_PATH . '/applications/gdcompliance/interface/gdcompliance.css';
			if ( is_readable( $path ) )
			{
				$css = (string) file_get_contents( $path );
			}
		}
		catch ( \Throwable ) {}

		if ( $css === '' ) { return ''; }
		return '<style data-owner="gdcompliance">' . $css . '</style>';
	}

	/**
	 * Compact badge for grid/listing views ("Restricted in N states").
	 * Returns empty string on kill-switch, zero UPC, or zero restrictions.
	 */
	public static function renderBadge( string $upc ): string
	{
		try
		{
			$enabled = (int) ( \IPS\Settings::i()->gdcompliance_front_enabled ?? 1 );
			if ( !$enabled ) { return ''; }
		}
		catch ( \Throwable ) { /* fall through */ }

		$summary = static::summaryForUpc( $upc );
		if ( $summary['count'] === 0 ) { return ''; }

		try
		{
			$html = (string) \IPS\Theme::i()->getTemplate( 'compliance', 'gdcompliance', 'front' )
				->restrictionBadge( $summary );
		}
		catch ( \Throwable ) { return ''; }

		return static::styleBlock() . $html;
	}
}

class Flag extends _Flag {}
