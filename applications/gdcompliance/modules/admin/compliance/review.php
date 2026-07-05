<?php
/**
 * @brief  GD Compliance — Category-aware review queue (v1.6.12)
 *
 * The gd_compliance_review table now holds four distinct review kinds:
 *
 *   review_type = 'roster'      — CA-style handgun roster reviews
 *                                 (suggested_status = 'unmatched_review').
 *                                 Resolve = Mark on-roster (force_clear)
 *                                 / off-roster (force_restrict) via
 *                                 Override::save.
 *
 *   review_type = 'awb_firearm' — semi-auto centerfire firearms flagged
 *                                 for AWB feature-based review
 *                                 (suggested_status = 'awb_review_*'
 *                                 or 'awb_tier2_*'). Resolve = Confirm
 *                                 AWB (force_restrict per-state) /
 *                                 Not an AWB (force_clear per-state).
 *
 *   review_type = 'lower'       — ambiguous cat154 lower receivers.
 *                                 Resolve = Confirm restricted lower
 *                                 (force_restrict per rifle-class
 *                                 AWB state) / Not an AWB lower
 *                                 (force_clear per rifle-class AWB
 *                                 state).
 *
 *   review_type = 'magazine'    — over-capacity mag reviews (future).
 *                                 Resolve = Confirm over-capacity /
 *                                 Not restricted.
 *
 * All resolutions persist across recompute via Override::save (which
 * writes to gd_compliance_overrides + applies immediately to
 * gd_compliance_flags).
 *
 * The primary filter is now review_type (category tabs). State
 * (roster_state) is a secondary filter that operates WITHIN a
 * category — the CA/MA/MD/DC list only appears in the 'roster'
 * category; other categories use the AWB rifle states.
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _review extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const REVIEW_TYPES = [
		''            => 'All',
		'roster'      => 'Roster',
		'awb_firearm' => 'AWB Firearms',
		'lower'       => 'Lowers',
		'magazine'    => 'Magazines',
		'melting'     => 'Melting-Point',
	];

	const ROSTER_STATES = [ 'CA', 'MA', 'MD', 'DC' ];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		$lh   = fn( string $k ) => htmlspecialchars( (string) $lang->addToStack( $k ), ENT_QUOTES, 'UTF-8' );

		$showResolved = (int) ( \IPS\Request::i()->resolved ?? 0 ) === 1;
		$typeFilter   = strtolower( trim( (string) ( \IPS\Request::i()->review_type ?? '' ) ) );
		if ( !array_key_exists( $typeFilter, self::REVIEW_TYPES ) ) { $typeFilter = ''; }
		$stateFilter  = strtoupper( trim( (string) ( \IPS\Request::i()->roster_state ?? '' ) ) );
		if ( strlen( $stateFilter ) !== 2 ) { $stateFilter = ''; }

		/* ---------- Overall pending / resolved ---------- */
		$pending = $resolved = 0;
		try { $pending  = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_review', [ 'resolved=0' ] )->first(); } catch ( \Throwable ) {}
		try { $resolved = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_review', [ 'resolved=1' ] )->first(); } catch ( \Throwable ) {}

		/* ---------- Per-category counts (for the tabs) ---------- */
		$perType = [];
		try
		{
			foreach ( \IPS\Db::i()->select( "review_type, COUNT(*) AS c", 'gd_compliance_review',
				[ 'resolved=?', $showResolved ? 1 : 0 ], null, null, 'review_type' ) as $row )
			{
				$perType[ (string) $row['review_type'] ] = (int) $row['c'];
			}
		}
		catch ( \Throwable ) {}

		/* ---------- Per-state counts WITHIN the selected category ---------- */
		$stateWhere = [ 'resolved=?', $showResolved ? 1 : 0 ];
		if ( $typeFilter !== '' )
		{
			$stateWhere[0] .= ' AND review_type=?';
			$stateWhere[]   = $typeFilter;
		}
		$perState = [];
		try
		{
			foreach ( \IPS\Db::i()->select( "roster_state, COUNT(*) AS c", 'gd_compliance_review',
				$stateWhere, null, null, 'roster_state' ) as $row )
			{
				$s = (string) $row['roster_state'];
				if ( $s !== '' ) { $perState[ $s ] = (int) $row['c']; }
			}
		}
		catch ( \Throwable ) {}

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review' );

		/* Preserve current filters as a query-string helper. */
		$preserveQs = function( array $override ) use ( $baseUrl, $typeFilter, $stateFilter, $showResolved ) {
			$qs = [];
			if ( $typeFilter    !== '' ) { $qs['review_type']  = $typeFilter; }
			if ( $stateFilter   !== '' ) { $qs['roster_state'] = $stateFilter; }
			if ( $showResolved         ) { $qs['resolved']     = 1; }
			foreach ( $override as $k => $v )
			{
				if ( $v === null ) { unset( $qs[ $k ] ); } else { $qs[ $k ] = $v; }
			}
			return (string) $baseUrl->setQueryString( $qs );
		};

		/* ---------- Pending / Resolved segmented control ---------- */
		$tabs = '<div style="margin:0 0 10px">'
			. "<a class='ipsButton ipsButton--small" . ( $showResolved ? ' ipsButton--soft' : ' ipsButton--primary' ) . "' href='"
			. $h( $preserveQs( [ 'resolved' => null ] ) ) . "'>" . $lh( 'gdcompliance_acp_review_pending' ) . " ({$pending})</a> "
			. "<a class='ipsButton ipsButton--small" . ( $showResolved ? ' ipsButton--primary' : ' ipsButton--soft' ) . "' href='"
			. $h( $preserveQs( [ 'resolved' => 1 ] ) ) . "'>" . $lh( 'gdcompliance_acp_review_resolved' ) . " ({$resolved})</a>"
			. '</div>';

		/* ---------- Category tabs (primary filter) ---------- */
		$typeTabs = '<div style="margin:0 0 8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">'
			. '<span style="font-size:12px;color:#64748b;font-weight:600;margin-right:4px">CATEGORY:</span>';
		foreach ( self::REVIEW_TYPES as $key => $label )
		{
			$active = $typeFilter === $key ? ' ipsButton--primary' : ' ipsButton--soft';
			$href   = $preserveQs( [ 'review_type' => $key === '' ? null : $key, 'roster_state' => null ] );
			$count  = $key === '' ? array_sum( $perType ) : (int) ( $perType[ $key ] ?? 0 );
			$typeTabs .= '<a class="ipsButton ipsButton--verySmall' . $active . '" href="' . $h( $href ) . '">' . $h( $label ) . ' (' . number_format( $count ) . ')</a>';
		}
		$typeTabs .= '</div>';

		/* ---------- State tabs (secondary, within category) ---------- */
		$stateOptions = self::stateOptionsFor( $typeFilter );
		$stateTabs    = '';
		if ( !empty( $stateOptions ) )
		{
			$stateTabs = '<div style="margin:0 0 14px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">'
				. '<span style="font-size:12px;color:#64748b;font-weight:600;margin-right:4px">STATE:</span>';
			$allHref = $preserveQs( [ 'roster_state' => null ] );
			$allActive = $stateFilter === '' ? ' ipsButton--primary' : ' ipsButton--soft';
			$stateTabs .= '<a class="ipsButton ipsButton--verySmall' . $allActive . '" href="' . $h( $allHref ) . '">All</a>';
			foreach ( $stateOptions as $sc )
			{
				$active = $stateFilter === $sc ? ' ipsButton--primary' : ' ipsButton--soft';
				$href   = $preserveQs( [ 'roster_state' => $sc ] );
				$cnt    = (int) ( $perState[ $sc ] ?? 0 );
				$stateTabs .= '<a class="ipsButton ipsButton--verySmall' . $active . '" href="' . $h( $href ) . '">' . $h( $sc ) . ' (' . number_format( $cnt ) . ')</a>';
			}
			$stateTabs .= '</div>';
		}

		/* ---------- Adaptive header + description ---------- */
		[ $titleKey, $introKey ] = self::headerFor( $typeFilter );
		$intro = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $lh( $titleKey ) . '</h2>'
			. '<p style="margin:0 0 10px">' . $lh( $introKey ) . '</p>'
			. $tabs
			. $typeTabs
			. $stateTabs
			. '</div></div>';

		/* ---------- Table\Db with the composite WHERE ---------- */
		$whereParts = [ 'resolved=?' ];
		$whereArgs  = [ $showResolved ? 1 : 0 ];
		if ( $typeFilter !== '' )
		{
			$whereParts[] = 'review_type=?';
			$whereArgs[]  = $typeFilter;
		}
		if ( $stateFilter !== '' )
		{
			$whereParts[] = 'roster_state=?';
			$whereArgs[]  = $stateFilter;
		}
		$whereSql = implode( ' AND ', $whereParts );
		$tableWhere = [ array_merge( [ $whereSql ], $whereArgs ) ];

		$tableUrl = $baseUrl;
		if ( $typeFilter  !== '' ) { $tableUrl = $tableUrl->setQueryString( 'review_type',  $typeFilter ); }
		if ( $stateFilter !== '' ) { $tableUrl = $tableUrl->setQueryString( 'roster_state', $stateFilter ); }
		if ( $showResolved       ) { $tableUrl = $tableUrl->setQueryString( 'resolved',     1 ); }

		$table = new \IPS\Helpers\Table\Db( 'gd_compliance_review', $tableUrl, $tableWhere );
		$table->langPrefix    = 'gdcompliance_acp_review_col_';
		$table->include       = [ 'review_type', 'roster_state', 'upc', 'manufacturer', 'model_title', 'caliber', 'suggested_status', 'resolved_status' ];
		$table->sortBy        = $table->sortBy ?: 'id';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->parsers = [
			'review_type' => function( $v ) use ( $h ) {
				$k = (string) $v;
				$style = match( $k ) {
					'roster'      => 'background:#dbeafe;color:#1e3a8a',
					'awb_firearm' => 'background:#fee2e2;color:#991b1b',
					'lower'       => 'background:#fef3c7;color:#78350f',
					'magazine'    => 'background:#fce7f3;color:#831843',
					default       => 'background:#f1f5f9;color:#475569',
				};
				return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;' . $style . '">' . $h( $k ) . '</span>';
			},
			'roster_state' => function( $v ) use ( $h ) {
				if ( $v === '' || $v === null ) { return '<span style="color:#cbd5e1">—</span>'; }
				$k = strtoupper( (string) $v );
				$style = match( $k ) {
					'CA' => 'background:#dbeafe;color:#1e3a8a',
					'MA' => 'background:#dcfce7;color:#14532d',
					'MD' => 'background:#fef3c7;color:#92400e',
					'DC' => 'background:#fee2e2;color:#991b1b',
					default => 'background:#f1f5f9;color:#475569',
				};
				return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;' . $style . '">' . $h( $k ) . '</span>';
			},
			'upc'              => fn( $v ) => '<span style="font-family:ui-monospace,monospace;font-size:12px">' . $h( (string) $v ) . '</span>',
			'manufacturer'     => fn( $v ) => '<strong>' . $h( (string) $v ) . '</strong>',
			'model_title'      => fn( $v ) => $h( (string) $v ),
			'caliber'          => fn( $v ) => $v ? $h( (string) $v ) : '<span style="color:#cbd5e1">—</span>',
			'suggested_status' => function( $v ) use ( $h ) {
				$lbl = strtolower( (string) $v );
				$pill = match( true ) {
					$lbl === 'off_roster'       => 'background:#fee2e2;color:#991b1b',
					$lbl === 'on_roster'        => 'background:#dcfce7;color:#14532d',
					str_starts_with( $lbl, 'awb_review_' )   => 'background:#fee2e2;color:#991b1b',
					str_starts_with( $lbl, 'awb_tier2_' )    => 'background:#fef3c7;color:#78350f',
					$lbl === 'lower_review'                  => 'background:#fef3c7;color:#78350f',
					default                                  => 'background:#fef3c7;color:#92400e',
				};
				return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;' . $pill . '">' . $h( (string) $v ) . '</span>';
			},
			'resolved_status'  => function( $v ) use ( $h ) {
				if ( !$v ) { return '<span style="color:#cbd5e1">—</span>'; }
				$k = strtolower( (string) $v );
				/* Cleared / kept = green; restricted / off = red. */
				$isGreen = in_array( $k, [ 'on_roster', 'not_awb', 'not_lower', 'not_magazine', 'cleared' ], true );
				$pill    = $isGreen ? 'background:#dcfce7;color:#14532d' : 'background:#fee2e2;color:#991b1b';
				return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;' . $pill . '">' . $h( (string) $v ) . '</span>';
			},
		];

		$table->rowButtons = function( $row ) {
			$base = 'app=gdcompliance&module=compliance&controller=review';
			$id   = (int) ( $row['id'] ?? 0 );
			$rt   = (string) ( $row['review_type'] ?? 'roster' );
			$btns = [
				'view' => [
					'icon'  => 'eye',
					'title' => 'view',
					'link'  => \IPS\Http\Url::internal( $base . '&do=view&id=' . $id ),
				],
			];
			if ( (int) ( $row['resolved'] ?? 0 ) === 0 )
			{
				[ $confirmStatus, $clearStatus, $confirmLbl, $clearLbl ] = self::resolutionsFor( $rt );
				$btns['confirm'] = [
					'icon'  => 'times',
					'title' => $confirmLbl,
					'link'  => \IPS\Http\Url::internal( $base . '&do=resolve&id=' . $id . '&status=' . $confirmStatus )->csrf(),
				];
				$btns['clear'] = [
					'icon'  => 'check',
					'title' => $clearLbl,
					'link'  => \IPS\Http\Url::internal( $base . '&do=resolve&id=' . $id . '&status=' . $clearStatus )->csrf(),
				];
			}
			else
			{
				$btns['reopen'] = [
					'icon'  => 'undo',
					'title' => 'gdcompliance_acp_review_reopen',
					'link'  => \IPS\Http\Url::internal( $base . '&do=reopen&id=' . $id )->csrf(),
				];
			}
			return $btns;
		};

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_review_title' );
		\IPS\Output::i()->output = $intro . (string) $table;
	}

	/**
	 * Category → adaptive title/intro lang key pair.
	 *
	 * @return array{0:string, 1:string}
	 */
	protected static function headerFor( string $typeFilter ): array
	{
		return match( $typeFilter ) {
			'roster'      => [ 'gdcompliance_acp_review_title_roster',   'gdcompliance_acp_review_intro_roster' ],
			'awb_firearm' => [ 'gdcompliance_acp_review_title_awb',      'gdcompliance_acp_review_intro_awb' ],
			'lower'       => [ 'gdcompliance_acp_review_title_lower',    'gdcompliance_acp_review_intro_lower' ],
			'magazine'    => [ 'gdcompliance_acp_review_title_magazine', 'gdcompliance_acp_review_intro_magazine' ],
			'melting'     => [ 'gdcompliance_acp_review_title_melting',  'gdcompliance_acp_review_intro_melting' ],
			default       => [ 'gdcompliance_acp_review_title_all',      'gdcompliance_acp_review_intro_all' ],
		};
	}

	/**
	 * Per-category valid state buttons. Roster is CA/MA/MD/DC; AWB
	 * firearms + lowers use the enabled rifle-class AWB states pulled
	 * live from gd_compliance_awb_rules. Magazines cover all capacity-
	 * limit states from gd_compliance_rules (broader).
	 *
	 * @return string[]
	 */
	protected static function stateOptionsFor( string $typeFilter ): array
	{
		if ( $typeFilter === 'roster' || $typeFilter === '' )
		{
			return self::ROSTER_STATES;
		}
		if ( $typeFilter === 'awb_firearm' )
		{
			$out = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'state_code', 'gd_compliance_awb_rules',
					[ 'enabled=1 AND firearm_class=?', 'rifle' ], 'state_code ASC' ) as $sc )
				{
					$s = strtoupper( (string) ( is_array( $sc ) ? ( $sc['state_code'] ?? '' ) : $sc ) );
					if ( strlen( $s ) === 2 ) { $out[] = $s; }
				}
			}
			catch ( \Throwable ) {}
			return array_values( array_unique( $out ) );
		}
		if ( $typeFilter === 'lower' )
		{
			/* v1.6.13 — lowers are not per-state. A serialized AR
			   lower is restricted uniformly across every enabled
			   rifle-class AWB state, so a state filter would just
			   narrow to nothing (Engine writes roster_state='' on
			   lower reviews). Return an empty state list so no
			   STATE row renders for this category. */
			return [];
		}
		if ( $typeFilter === 'melting' )
		{
			/* v1.6.20 — melting-point reviews are per-product, not
			   per-state (Engine writes roster_state='' on melting
			   reviews). Resolving one row applies force_flag /
			   force_clear across every enabled melting-point state,
			   matching the flag-emission model. */
			return [];
		}
		if ( $typeFilter === 'magazine' )
		{
			$out = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'DISTINCT state_code', 'gd_compliance_rules',
					[ 'enabled=1' ], 'state_code ASC' ) as $sc )
				{
					$s = strtoupper( (string) ( is_array( $sc ) ? ( $sc['state_code'] ?? '' ) : $sc ) );
					if ( strlen( $s ) === 2 ) { $out[] = $s; }
				}
			}
			catch ( \Throwable ) {}
			return array_values( array_unique( $out ) );
		}
		return [];
	}

	/**
	 * Per-review-type resolve mapping.
	 *
	 * @return array{0:string, 1:string, 2:string, 3:string}
	 *   [ confirmStatus, clearStatus, confirmLangKey, clearLangKey ]
	 *   confirmStatus writes an Override RESTRICT; clearStatus writes
	 *   an Override CLEAR. Legacy 'roster' keeps on_roster/off_roster
	 *   values so existing resolved rows read correctly.
	 */
	protected static function resolutionsFor( string $reviewType ): array
	{
		return match( $reviewType ) {
			'awb_firearm' => [ 'confirm_awb',      'not_awb',      'gdcompliance_acp_review_mark_confirm_awb',     'gdcompliance_acp_review_mark_not_awb' ],
			'lower'       => [ 'confirm_lower',    'not_lower',    'gdcompliance_acp_review_mark_confirm_lower',   'gdcompliance_acp_review_mark_not_lower' ],
			'magazine'    => [ 'confirm_magazine', 'not_magazine', 'gdcompliance_acp_review_mark_confirm_mag',     'gdcompliance_acp_review_mark_not_mag' ],
			'melting'     => [ 'confirm_melting',  'not_melting',  'gdcompliance_acp_review_mark_confirm_melting', 'gdcompliance_acp_review_mark_not_melting' ],
			default       => [ 'off_roster',       'on_roster',    'gdcompliance_acp_review_mark_off',             'gdcompliance_acp_review_mark_on' ],
		};
	}

	protected function view(): void
	{
		$id  = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = null;
		try { $row = \IPS\Db::i()->select( '*', 'gd_compliance_review', [ 'id=?', $id ] )->first(); }
		catch ( \Throwable ) { $row = null; }
		if ( !$row )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review' ) );
			return;
		}

		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $k ) => htmlspecialchars( (string) $lang->addToStack( $k ), ENT_QUOTES, 'UTF-8' );
		$ce   = fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );

		$rt = (string) ( $row['review_type'] ?? 'roster' );
		[ $confirmStatus, $clearStatus, $confirmLbl, $clearLbl ] = self::resolutionsFor( $rt );

		/* Roster reviews carry near-miss candidates in candidates_json.
		   Non-roster rows may leave it empty — skip the block cleanly. */
		$candHtml = '';
		if ( $rt === 'roster' )
		{
			$candidates = json_decode( (string) ( $row['candidates_json'] ?? '[]' ), true );
			if ( !is_array( $candidates ) ) { $candidates = []; }
			if ( !empty( $candidates ) )
			{
				$candHtml .= '<h3 style="margin:16px 0 6px">' . $h( 'gdcompliance_acp_review_candidates' ) . '</h3>';
				$candHtml .= '<table class="ipsTable ipsTable_responsive" style="width:100%;border-collapse:collapse">'
					. '<thead><tr>'
					. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Roster ID</th>'
					. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Model</th>'
					. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Caliber</th>'
					. '<th style="text-align:right;padding:6px 10px;border-bottom:2px solid #e6e9ee">Status</th>'
					. '</tr></thead><tbody>';
				foreach ( $candidates as $c )
				{
					$status = ( (int) ( $c['is_current'] ?? 0 ) === 1 )
						? '<span style="color:#14532d;font-weight:700">CURRENT</span>'
						: '<span style="color:#991b1b;font-weight:700">EXPIRED</span>';
					$candHtml .= '<tr>'
						. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace">' . $ce( $c['id'] ?? '' ) . '</td>'
						. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9">' . $ce( $c['model'] ?? '' ) . '</td>'
						. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9">' . $ce( $c['caliber'] ?? '' ) . '</td>'
						. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right">' . $status . '</td>'
						. '</tr>';
				}
				$candHtml .= '</tbody></table>';
			}
		}

		$confirmUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review&do=resolve&id=' . $id . '&status=' . $confirmStatus )->csrf();
		$clearUrl   = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review&do=resolve&id=' . $id . '&status=' . $clearStatus )->csrf();

		$typeBadgeStyle = match( $rt ) {
			'roster'      => 'background:#dbeafe;color:#1e3a8a',
			'awb_firearm' => 'background:#fee2e2;color:#991b1b',
			'lower'       => 'background:#fef3c7;color:#78350f',
			'magazine'    => 'background:#fce7f3;color:#831843',
			default       => 'background:#f1f5f9;color:#475569',
		};

		$out  = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">';
		$out .= '<h2 class="ipsType_sectionHead" style="margin:0 0 10px">' . $ce( $row['manufacturer'] ) . ' &mdash; ' . $ce( $row['model_title'] ) . '</h2>';
		$out .= '<p style="margin:0 0 4px"><span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;' . $typeBadgeStyle . '">' . $ce( $rt ) . '</span>'
			. ( ( $row['roster_state'] ?? '' ) !== '' ? ' <strong style="color:#475569">' . $ce( $row['roster_state'] ) . '</strong>' : '' ) . '</p>';
		$out .= '<p style="margin:0 0 4px"><strong>UPC:</strong> <span style="font-family:ui-monospace,monospace">' . $ce( $row['upc'] ) . '</span></p>';
		$out .= '<p style="margin:0 0 4px"><strong>Caliber:</strong> ' . $ce( $row['caliber'] ?: '—' ) . '</p>';
		$out .= '<p style="margin:0 0 14px"><strong>Suggested:</strong> ' . $ce( $row['suggested_status'] ) . '</p>';

		if ( (int) ( $row['resolved'] ?? 0 ) === 0 )
		{
			$out .= '<div style="display:flex;gap:8px">'
				. '<a href="' . $ce( $confirmUrl ) . '" class="ipsButton ipsButton--primary">' . $h( $confirmLbl ) . '</a>'
				. '<a href="' . $ce( $clearUrl )   . '" class="ipsButton ipsButton--soft">'    . $h( $clearLbl )   . '</a>'
				. '</div>';
		}
		else
		{
			$out .= '<p style="margin:0;color:#475569">' . $h( 'gdcompliance_acp_review_already_resolved' ) . ' &mdash; <strong>' . $ce( $row['resolved_status'] ) . '</strong></p>';
		}
		$out .= $candHtml;
		$out .= '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_review_title' );
		\IPS\Output::i()->output = $out;
	}

	protected function resolve(): void
	{
		\IPS\Session::i()->csrfCheck();

		$id     = (int) ( \IPS\Request::i()->id ?? 0 );
		$status = (string) ( \IPS\Request::i()->status ?? '' );
		$valid  = [ 'on_roster', 'off_roster', 'confirm_awb', 'not_awb', 'confirm_lower', 'not_lower', 'confirm_magazine', 'not_magazine', 'confirm_melting', 'not_melting' ];
		if ( !in_array( $status, $valid, true ) )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review' ) );
			return;
		}

		$row = null;
		try { $row = \IPS\Db::i()->select( '*', 'gd_compliance_review', [ 'id=?', $id ] )->first(); }
		catch ( \Throwable ) { $row = null; }
		if ( !$row )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review' ) );
			return;
		}

		/* Persist the resolution row itself. */
		try
		{
			\IPS\Db::i()->update( 'gd_compliance_review', [
				'resolved'        => 1,
				'resolved_status' => $status,
				'resolved_by'     => (int) \IPS\Member::loggedIn()->member_id,
				'resolved_at'     => time(),
			], [ 'id=?', $id ] );
		}
		catch ( \Throwable ) {}

		/* Persist the decision as an OVERRIDE — survives future
		   recomputes. Roster reviews behave as before; other review
		   types map to force_restrict / force_clear per the same
		   (upc, state) key. For lower reviews, the state may be
		   empty (Engine sets roster_state=''), so we apply the
		   override across EVERY enabled rifle-class AWB state so a
		   confirmation flags across all of them, or a clear wipes
		   all of them. */
		$upc      = (string) ( $row['upc'] ?? '' );
		$rt       = (string) ( $row['review_type'] ?? 'roster' );
		$rowState = (string) ( $row['roster_state'] ?? '' );

		$restrict = [ 'off_roster', 'confirm_awb', 'confirm_lower', 'confirm_magazine', 'confirm_melting' ];
		$clear    = [ 'on_roster',  'not_awb',     'not_lower',     'not_magazine',    'not_melting' ];
		$action   = in_array( $status, $restrict, true )
			? \IPS\gdcompliance\Override::ACTION_RESTRICT
			: \IPS\gdcompliance\Override::ACTION_CLEAR;

		$reason = self::overrideReasonFor( $rt, $status, $rowState );

		/* Which states to write the override for. */
		$targetStates = [];
		if ( $rt === 'lower' && $rowState === '' )
		{
			$targetStates = self::stateOptionsFor( 'lower' );
		}
		elseif ( $rt === 'melting' && $rowState === '' )
		{
			/* v1.6.20 — melting-point resolutions apply across every
			   enabled melting-point state, matching how the flags
			   were emitted per-state. Pull the state list live from
			   gd_compliance_melting_rules; fall back to the seeded
			   six-state constant if the table is unavailable. */
			$mpStates = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'state_code', 'gd_compliance_melting_rules', [ 'enabled=?', 1 ] ) as $sc )
				{
					$s = strtoupper( (string) ( is_array( $sc ) ? ( $sc['state_code'] ?? '' ) : $sc ) );
					if ( strlen( $s ) === 2 ) { $mpStates[] = $s; }
				}
			}
			catch ( \Throwable ) {}
			$targetStates = !empty( $mpStates ) ? $mpStates : [ 'HI', 'IL', 'MD', 'MA', 'MN', 'NY' ];
		}
		elseif ( $rowState !== '' )
		{
			$targetStates = [ $rowState ];
		}
		else
		{
			$targetStates = [ 'CA' ]; /* legacy safe default */
		}

		foreach ( $targetStates as $st )
		{
			try
			{
				\IPS\gdcompliance\Override::save(
					$upc,
					$st,
					$action,
					$reason,
					(int) \IPS\Member::loggedIn()->member_id,
					true
				);
			}
			catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review' ), 'saved' );
	}

	/**
	 * Human-readable override reason for the review resolution.
	 */
	protected static function overrideReasonFor( string $rt, string $status, string $rowState ): string
	{
		$where = $rowState !== '' ? " ({$rowState})" : '';
		return match( $status ) {
			'off_roster'       => "Not on {$rowState} roster (manual review)",
			'on_roster'        => "Cleared by manual review (on roster)",
			'confirm_awb'      => "Confirmed AWB firearm via manual review{$where}",
			'not_awb'          => "Cleared — not an AWB via manual review{$where}",
			'confirm_lower'    => "Confirmed AWB-pattern lower receiver via manual review{$where}",
			'not_lower'        => "Cleared — not an AWB lower via manual review{$where}",
			'confirm_magazine' => "Confirmed over-capacity magazine via manual review{$where}",
			'not_magazine'     => "Cleared — not restricted via manual review{$where}",
			'confirm_melting'  => "Confirmed zinc-alloy frame (Saturday-Night-Special ban) via manual review{$where}",
			'not_melting'      => "Cleared — steel frame confirmed via manual review{$where}",
			default            => "Manual review resolution: {$status}",
		};
	}

	protected function reopen(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try
			{
				\IPS\Db::i()->update( 'gd_compliance_review', [
					'resolved'        => 0,
					'resolved_status' => null,
					'resolved_by'     => null,
					'resolved_at'     => null,
				], [ 'id=?', $id ] );
			}
			catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review' )->setQueryString( 'resolved', 1 ) );
	}
}

class review extends _review {}
