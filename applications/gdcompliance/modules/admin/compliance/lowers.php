<?php
/**
 * @brief  GD Compliance — Lowers & Receivers (v1.6.15)
 *
 * Section-for-section mirror of the Magazines page workflow:
 *   1. Summary ipsBox with sectionHead + stat cards (flagged
 *      lowers / pending review / curated overrides).
 *   2. CLICKABLE state filter buttons — rifle-class AWB states from
 *      gd_compliance_awb_rules. Clicking a state filters the
 *      flagged-list to that state, giving Derrick per-(upc, state)
 *      drill-in. (v1.6.14 rendered these as non-clickable info
 *      badges, which removed the per-state override workflow.)
 *   3. Flagged-Lower-Receivers table:
 *        - "All" (no state filter) → GROUP BY f.upc, one row per
 *          distinct lower with a state_count. Per-row "Set override"
 *          link pre-fills upc only; admin picks state on the form.
 *        - State filter → WHERE f.state_code=?, one row per lower
 *          in that state. Per-row "Set override" link pre-fills
 *          upc + state so a per-(upc, state) clear or restrict is
 *          one click. Same chrome as magazines' per-row link.
 *      Native ->select([table, alias], ...)->join([table, alias], ...);
 *      NEVER raw preparedQuery.
 *   4. Test box — per-UPC live Lowers::classify() verdict.
 *   5. Curated overrides Table\Db — the add / edit / delete CRUD on
 *      gd_compliance_lowers.
 *
 * Curated entries WIN over auto logic (force_clear/force_flag/review).
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _lowers extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const ACTIONS = [
		'force_flag'  => 'Force flag (curated match)',
		'force_clear' => 'Force clear (never flag)',
		'review'      => 'Route to review',
	];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Lowers.php';
			\IPS\gdcompliance\Lowers::clearCache();
		}
		catch ( \Throwable ) {}

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lowers' );

		/* ============================================================
		 * Counts driving the summary card
		 * ============================================================ */
		$distinctFlagged = 0;
		try
		{
			$distinctFlagged = (int) \IPS\Db::i()->select( 'COUNT(DISTINCT upc)', 'gd_compliance_flags',
				[ 'firearm_type=?', 'awb_lower' ] )->first();
		}
		catch ( \Throwable ) {}

		$totalFlagRows = 0;
		try
		{
			$totalFlagRows = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_flags',
				[ 'firearm_type=?', 'awb_lower' ] )->first();
		}
		catch ( \Throwable ) {}

		$pendingReview = 0;
		try
		{
			$pendingReview = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_review',
				[ "review_type=? AND resolved=0", 'lower' ] )->first();
		}
		catch ( \Throwable ) {}

		$curatedCount = 0;
		try
		{
			$curatedCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_lowers' )->first();
		}
		catch ( \Throwable ) {}

		$reviewLowersUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review' )
			->setQueryString( 'review_type', 'lower' );

		/* --- Summary box (mirrors magazines summary) --- */
		$summary = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 6px">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_title' ) ) . '</h2>'
			. '<p style="margin:0 0 10px;color:#475569">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_intro' ) ) . '</p>'
			. '<div style="display:flex;gap:24px;flex-wrap:wrap;font-size:13px">'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $distinctFlagged ) . '</strong><br><span style="color:#64748b">flagged lower receivers</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $totalFlagRows ) . '</strong><br><span style="color:#64748b">total flag rows</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . ( $pendingReview > 0
					? '<a href="' . $h( $reviewLowersUrl ) . '" style="color:#a16207">' . number_format( $pendingReview ) . '</a>'
					: number_format( $pendingReview ) ) . '</strong><br><span style="color:#64748b">pending review</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $curatedCount ) . '</strong><br><span style="color:#64748b">curated overrides</span></div>'
			. '</div>'
			. '</div></div>';

		/* ============================================================
		 * STATE BADGE STRIP (informational, not clickable filters)
		 * ============================================================ */
		/* Read + validate the state filter from the URL. Matches
		   magazines.php ~line 41-42. */
		$stateFilter = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( strlen( $stateFilter ) !== 2 ) { $stateFilter = ''; }

		$awbStates = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'state_code', 'gd_compliance_awb_rules',
				[ "enabled=1 AND firearm_class=?", 'rifle' ], 'state_code ASC' ) as $sc )
			{
				$s = strtoupper( (string) ( is_array( $sc ) ? ( $sc['state_code'] ?? '' ) : $sc ) );
				if ( strlen( $s ) === 2 ) { $awbStates[] = $s; }
			}
		}
		catch ( \Throwable ) {}
		$awbStates = array_values( array_unique( $awbStates ) );
		sort( $awbStates );

		/* Per-state awb_lower flag counts for the button labels. */
		$perState = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'state_code, COUNT(*) AS c',
				'gd_compliance_flags',
				[ 'firearm_type=?', 'awb_lower' ],
				null,
				null,
				'state_code'
			) as $row )
			{
				$perState[ (string) $row['state_code'] ] = (int) $row['c'];
			}
		}
		catch ( \Throwable ) {}

		/* ---------- Clickable STATE filter buttons (mirrors magazines
		   ~lines 82-93). Non-clickable badges were the v1.6.14 gap —
		   Derrick needs to drill INTO a state to reach the per-(upc,
		   state) override. */
		$statesHtml  = '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center">'
			. '<span style="font-size:12px;color:#64748b;font-weight:600;align-self:center;margin-right:4px">STATES:</span>';
		if ( empty( $awbStates ) )
		{
			$statesHtml .= '<span style="color:#94a3b8;font-size:12px">No enabled rifle-class AWB states in gd_compliance_awb_rules.</span>';
		}
		else
		{
			$allActive = $stateFilter === '' ? ' ipsButton--primary' : ' ipsButton--soft';
			$statesHtml .= '<a class="ipsButton ipsButton--verySmall' . $allActive . '" href="' . $h( (string) $baseUrl ) . '">All (' . number_format( $distinctFlagged ) . ')</a>';
			foreach ( $awbStates as $sc )
			{
				$active = $stateFilter === $sc ? ' ipsButton--primary' : ' ipsButton--soft';
				$href   = (string) $baseUrl->setQueryString( 'state', $sc );
				$cnt    = (int) ( $perState[ $sc ] ?? 0 );
				$statesHtml .= '<a class="ipsButton ipsButton--verySmall' . $active . '" href="' . $h( $href ) . '">' . $h( $sc ) . ' (' . number_format( $cnt ) . ')</a>';
			}
		}
		$statesHtml .= '</div>'
			. '<p style="margin:-4px 0 14px;color:#64748b;font-size:12px">'
			. $h( $lang->addToStack( 'gdcompliance_acp_lowers_states_caption' ) )
			. '</p>';

		/* ============================================================
		 * FLAGGED LOWER RECEIVERS TABLE (mirrors magazines flag table)
		 * ============================================================
		 * A serialized AR/AK-pattern lower flags across every enabled
		 * rifle-class AWB state — one gd_compliance_flags row per (upc,
		 * state). Behavior mirrors magazines' state workflow:
		 *
		 *   All mode (no state filter) — GROUP BY f.upc → one row per
		 *     distinct lower, plus a "States" count column. Per-row
		 *     "Set override" link pre-fills upc only; admin picks the
		 *     state on the override form.
		 *
		 *   State mode (state=XX) — WHERE f.state_code=? → one row per
		 *     lower flagged in that state. Per-row "Set override" link
		 *     pre-fills upc + state so a per-(upc, state) clear or
		 *     restrict is one click away — the workflow that v1.6.14
		 *     was missing.
		 *
		 * Native Db::select([table, alias], ...)->join([table, alias],
		 * ...); NO raw preparedQuery. */
		$page = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$per  = 50;
		$off  = ( $page - 1 ) * $per;

		$flagWhere = [ "f.firearm_type=?" ];
		$flagArgs  = [ 'awb_lower' ];
		if ( $stateFilter !== '' )
		{
			$flagWhere[] = 'f.state_code=?';
			$flagArgs[]  = $stateFilter;
		}
		$flagWhereSql = implode( ' AND ', $flagWhere );

		/* Total distinct UPCs matching the current filter — drives the
		   header count + pager. In state mode this equals COUNT(*) of
		   rows since state_code+upc is unique.
		   BUG (v1.6.14/1.6.15): the count query passed the table name
		   without an alias ('gd_compliance_flags') while $flagWhereSql
		   references f.firearm_type=? / f.state_code=?. MySQL raised
		   "Unknown column 'f.firearm_type'" which the try/catch
		   swallowed, leaving $listCount=0 → the pager conditional
		   never fired. Use the aliased-table form to match the list
		   select and the where clause (mirrors magazines.php line 112). */
		$listCount = 0;
		try
		{
			$listCount = (int) \IPS\Db::i()->select(
				'COUNT(DISTINCT f.upc)',
				[ 'gd_compliance_flags', 'f' ],
				array_merge( [ $flagWhereSql ], $flagArgs )
			)->first();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'lowers listCount: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$rowsHtml = '';
		try
		{
			if ( $stateFilter === '' )
			{
				/* All mode — group by upc, show state_count column. */
				$sel = \IPS\Db::i()->select(
					'f.upc, COUNT(*) AS state_count, MAX(f.reason) AS sample_reason, c.brand, c.title, c.caliber, c.mpn',
					[ 'gd_compliance_flags', 'f' ],
					array_merge( [ $flagWhereSql ], $flagArgs ),
					'c.brand ASC, c.title ASC',
					[ $off, $per ],
					'f.upc'
				)->join( [ 'gd_catalog', 'c' ], 'c.upc = f.upc', 'LEFT' );
			}
			else
			{
				/* State mode — one row per (upc, state). No group by. */
				$sel = \IPS\Db::i()->select(
					'f.upc, f.state_code, f.reason, f.citation, c.brand, c.title, c.caliber, c.mpn',
					[ 'gd_compliance_flags', 'f' ],
					array_merge( [ $flagWhereSql ], $flagArgs ),
					'c.brand ASC, c.title ASC',
					[ $off, $per ]
				)->join( [ 'gd_catalog', 'c' ], 'c.upc = f.upc', 'LEFT' );
			}

			foreach ( $sel as $r )
			{
				$upc   = (string) ( $r['upc'] ?? '' );
				$brand = (string) ( $r['brand'] ?? '' );
				$title = (string) ( $r['title'] ?? '' );
				$cal   = (string) ( $r['caliber'] ?? '' );
				$mpn   = (string) ( $r['mpn'] ?? '' );

				if ( $stateFilter === '' )
				{
					$stateCol    = '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;font-family:ui-monospace,monospace;color:#991b1b">' . number_format( (int) ( $r['state_count'] ?? 0 ) ) . '</td>';
					$overrideQs  = [ 'upc' => $upc ];
				}
				else
				{
					$rowState = (string) ( $r['state_code'] ?? $stateFilter );
					$stateCol = '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9"><span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e3a8a">' . $h( $rowState ) . '</span></td>';
					$overrideQs = [ 'upc' => $upc, 'state' => $rowState ];
				}

				$overrideUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=overrides&do=form' )
					->setQueryString( $overrideQs );

				$rowsHtml .= '<tr>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px">' . $h( $upc ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9"><strong>' . $h( $brand !== '' ? $brand : '—' ) . '</strong></td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-size:13px">' . $h( strlen( $title ) > 80 ? substr( $title, 0, 77 ) . '…' : $title ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:12px">' . $h( $cal !== '' ? $cal : '—' ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px;color:#64748b">' . $h( $mpn !== '' ? $mpn : '—' ) . '</td>'
					. $stateCol
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right"><a href="' . $h( $overrideUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--verySmall">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_override' ) ) . '</a></td>'
					. '</tr>';
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'lowers flagged list: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		if ( $rowsHtml === '' )
		{
			$rowsHtml = '<tr><td colspan="7" style="padding:20px;text-align:center;color:#94a3b8">No lower-receiver flags'
				. ( $stateFilter !== '' ? ' for ' . $h( $stateFilter ) : '' )
				. '. Run the compute pass to populate — cat154 rows classified as \'flag\' will land here.</td></tr>';
		}

		$stateColHeader = $stateFilter === '' ? 'States' : 'State';

		$table = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 6px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_flagged_title' ) )
			. ' <span style="color:#64748b;font-weight:400">('
			. number_format( $listCount )
			. ( $stateFilter !== '' ? ' in ' . $h( $stateFilter ) : '' )
			. ')</span></h3>'
			. '<table style="width:100%;border-collapse:collapse">'
			. '<thead><tr style="background:#f8fafc">'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">UPC</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Brand</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Title</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Caliber</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">MPN</th>'
			. '<th style="text-align:' . ( $stateFilter === '' ? 'right' : 'left' ) . ';padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">' . $stateColHeader . '</th>'
			. '<th style="padding:8px 10px;border-bottom:2px solid #e2e8f0"></th>'
			. '</tr></thead>'
			. '<tbody>' . $rowsHtml . '</tbody>'
			. '</table>'
			. '</div></div>';

		$pager = '';
		if ( $listCount > $per )
		{
			$totalPages = (int) ceil( $listCount / $per );
			$prevHref   = (string) $baseUrl->setQueryString( array_filter( [
				'state' => $stateFilter !== '' ? $stateFilter : null,
				'page'  => $page > 1 ? $page - 1 : null,
			] ) );
			$nextHref   = (string) $baseUrl->setQueryString( array_filter( [
				'state' => $stateFilter !== '' ? $stateFilter : null,
				'page'  => $page < $totalPages ? $page + 1 : null,
			] ) );
			$pager = '<div style="display:flex;gap:8px;justify-content:center;margin:0 0 14px;font-size:13px;color:#64748b">'
				. ( $page > 1 ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $prevHref ) . '">&larr; Prev</a>' : '' )
				. '<span style="padding:4px 8px">Page ' . $page . ' / ' . $totalPages . '</span>'
				. ( $page < $totalPages ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $nextHref ) . '">Next &rarr;</a>' : '' )
				. '</div>';
		}

		/* ============================================================
		 * TEST BOX — per-UPC live classify() verdict
		 * ============================================================ */
		$testHtml = '';
		$testUpc  = trim( (string) ( \IPS\Request::i()->test_upc ?? '' ) );
		if ( $testUpc !== '' )
		{
			$row = null;
			try
			{
				$row = \IPS\Db::i()->select( 'upc, category_id, title, brand, manufacturer, model, mpn, caliber',
					'gd_catalog', [ 'upc=?', $testUpc ] )->first();
			}
			catch ( \Throwable ) { $row = null; }

			if ( !is_array( $row ) )
			{
				$testHtml = '<div style="padding:10px;background:#fff7ed;border:1px solid #fdba74;border-radius:6px;color:#7c2d12">UPC <code>' . $h( $testUpc ) . '</code> not found in gd_catalog.</div>';
			}
			else
			{
				$v = \IPS\gdcompliance\Lowers::classify( $row );
				if ( !is_array( $v ) )
				{
					$testHtml = '<div style="padding:10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px"><strong>Skip</strong> &mdash; not in lower categories (cat154/69), or excluded as a part/upper.</div>';
				}
				else
				{
					$verd = (string) ( $v['verdict'] ?? '' );
					$src  = (string) ( $v['source']  ?? '' );
					$pat  = (string) ( $v['pattern'] ?? '' );
					$hint = (string) ( $v['reason_hint'] ?? '' );
					$note = (string) ( $v['note'] ?? '' );

					$colors = [
						'flag'   => [ 'bg' => '#fee2e2', 'br' => '#f87171', 'fg' => '#991b1b' ],
						'review' => [ 'bg' => '#fef3c7', 'br' => '#fbbf24', 'fg' => '#78350f' ],
						'clear'  => [ 'bg' => '#dcfce7', 'br' => '#34d399', 'fg' => '#065f46' ],
					];
					$c = $colors[ $verd ] ?? [ 'bg' => '#f1f5f9', 'br' => '#cbd5e1', 'fg' => '#334155' ];

					$testHtml = '<div style="padding:12px;background:' . $c['bg'] . ';border:1px solid ' . $c['br'] . ';border-radius:6px;color:' . $c['fg'] . '">'
						. '<strong style="text-transform:uppercase;font-size:12px">' . $h( $verd ) . '</strong>'
						. ( $src !== '' ? ' <span style="font-size:11px;color:#475569">source: ' . $h( $src ) . '</span>' : '' )
						. '<br><span style="font-size:13px;color:#334155">' . $h( (string) $row['title'] ) . '</span>'
						. ( $pat  !== '' ? '<br><small>matched: ' . $h( $pat  ) . '</small>' : '' )
						. ( $hint !== '' ? '<br><small>hint: '    . $h( $hint ) . '</small>' : '' )
						. ( $note !== '' ? '<br><small>note: '    . $h( $note ) . '</small>' : '' )
						. '</div>';
				}
			}
		}

		$testUrl  = (string) $baseUrl;
		$testCard = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 8px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_test' ) ) . '</h3>'
			. '<form method="get" action="' . $h( $testUrl ) . '" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">'
			. '<input type="hidden" name="app" value="gdcompliance"><input type="hidden" name="module" value="compliance"><input type="hidden" name="controller" value="lowers">'
			. '<input type="search" name="test_upc" value="' . $h( $testUpc ) . '" placeholder="UPC to test" style="flex:1 1 240px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px">'
			. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Test</button>'
			. '</form>'
			. $testHtml
			. '</div></div>';

		/* ============================================================
		 * CURATED OVERRIDES Table\Db (existing CRUD, matching chrome)
		 * ============================================================ */
		$curatedTable = new \IPS\Helpers\Table\Db( 'gd_compliance_lowers', $baseUrl );
		$curatedTable->langPrefix    = 'gdcompliance_acp_lowers_col_';
		$curatedTable->include       = [ 'pattern', 'platform', 'action', 'note' ];
		$curatedTable->sortBy        = $curatedTable->sortBy ?: 'action';
		$curatedTable->sortDirection = $curatedTable->sortDirection ?: 'asc';

		$actionColors = [
			'force_flag'  => [ 'bg' => '#fee2e2', 'fg' => '#991b1b', 'lbl' => 'FLAG' ],
			'force_clear' => [ 'bg' => '#dcfce7', 'fg' => '#065f46', 'lbl' => 'CLEAR' ],
			'review'      => [ 'bg' => '#fef3c7', 'fg' => '#78350f', 'lbl' => 'REVIEW' ],
		];
		$curatedTable->parsers = [
			'pattern'  => fn( $v ) => '<strong style="font-family:ui-monospace,monospace">' . $h( (string) $v ) . '</strong>',
			'platform' => fn( $v ) => $v ? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e3a8a">' . $h( (string) $v ) . '</span>' : '<span style="color:#cbd5e1">—</span>',
			'action'   => function( $v ) use ( $h, $actionColors ) {
				$key = (string) $v;
				$c   = $actionColors[ $key ] ?? [ 'bg' => '#f1f5f9', 'fg' => '#334155', 'lbl' => strtoupper( $key ) ];
				return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:' . $c['bg'] . ';color:' . $c['fg'] . '">' . $h( $c['lbl'] ) . '</span>';
			},
			'note'     => fn( $v ) => $v ? '<span style="color:#475569;font-size:12px">' . $h( (string) $v ) . '</span>' : '<span style="color:#cbd5e1">—</span>',
		];
		$curatedTable->rowButtons = function( $row ) {
			$base = 'app=gdcompliance&module=compliance&controller=lowers';
			return [
				'edit'   => [ 'icon' => 'pencil',       'title' => 'edit',   'link' => \IPS\Http\Url::internal( $base . '&do=form&id=' . (int) $row['id'] ) ],
				'delete' => [ 'icon' => 'times-circle', 'title' => 'delete', 'link' => \IPS\Http\Url::internal( $base . '&do=delete&id=' . (int) $row['id'] )->csrf(), 'data' => [ 'delete' => '' ] ],
			];
		};

		$addUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lowers&do=form' );
		$curatedIntro = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 6px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_curated' ) ) . '</h3>'
			. '<p style="margin:0 0 10px;color:#475569;font-size:13px">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_curated_intro' ) ) . '</p>'
			. '<a href="' . $h( $addUrl ) . '" class="ipsButton ipsButton--primary ipsButton--small">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_add' ) ) . '</a>'
			. '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_lowers_title' );
		\IPS\Output::i()->output = $summary . $statesHtml . $table . $pager . $testCard . $curatedIntro . (string) $curatedTable;
	}

	protected function form(): void
	{
		$id  = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = null;
		if ( $id > 0 )
		{
			try { $row = \IPS\Db::i()->select( '*', 'gd_compliance_lowers', [ 'id=?', $id ] )->first(); }
			catch ( \Throwable ) { $row = null; }
		}

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_lowers_f_pattern',  $row['pattern']  ?? '', TRUE,  [ 'maxLength' => 191 ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_lowers_f_platform', $row['platform'] ?? '', FALSE, [ 'maxLength' => 40 ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdcompliance_lowers_f_action',   $row['action']   ?? 'force_flag', TRUE, [ 'options' => self::ACTIONS ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_lowers_f_note',     $row['note']     ?? '', FALSE, [ 'maxLength' => 255 ] ) );

		if ( $values = $form->values() )
		{
			$pattern = trim( (string) $values['gdcompliance_lowers_f_pattern'] );
			$action  = (string) $values['gdcompliance_lowers_f_action'];
			if ( $pattern === '' || strlen( $pattern ) < 3 )
			{
				$form->error = 'Pattern must be at least 3 characters.';
			}
			elseif ( !isset( self::ACTIONS[ $action ] ) )
			{
				$form->error = 'Invalid action.';
			}
			else
			{
				$data = [
					'pattern'    => substr( $pattern, 0, 191 ),
					'platform'   => substr( (string) $values['gdcompliance_lowers_f_platform'], 0, 40 ) ?: null,
					'action'     => $action,
					'note'       => substr( (string) $values['gdcompliance_lowers_f_note'], 0, 255 ) ?: null,
					'created_at' => time(),
				];
				try
				{
					if ( $row ) { \IPS\Db::i()->update( 'gd_compliance_lowers', $data, [ 'id=?', $id ] ); }
					else        { \IPS\Db::i()->insert( 'gd_compliance_lowers', $data ); }
					try { \IPS\gdcompliance\Lowers::clearCache(); } catch ( \Throwable ) {}
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'lowers save: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
				}

				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lowers' ),
					'saved'
				);
				return;
			}
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_lowers_add' );
		\IPS\Output::i()->output = (string) $form;
	}

	protected function delete(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try
			{
				\IPS\Db::i()->delete( 'gd_compliance_lowers', [ 'id=?', $id ] );
				try { \IPS\gdcompliance\Lowers::clearCache(); } catch ( \Throwable ) {}
			}
			catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lowers' ), 'deleted' );
	}
}

class lowers extends _lowers {}
