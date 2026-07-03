<?php
/**
 * @brief  GD Compliance — Lowers & Receivers (v1.6.10)
 *
 * Curated override layer on top of the auto cat154 matcher. Same UI
 * conventions as awbmodels.php: Table\Db over gd_compliance_lowers,
 * add/edit/toggle/delete row buttons, plus an auto-summary card and a
 * per-UPC test box that runs Lowers::classify() live.
 *
 * Curated entries WIN over auto logic (force_clear/force_flag/review).
 * Empty table → pure auto behavior.
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

		/* ============================================================
		 * SECTION 1 — Title + auto-summary counts + AWB state badges
		 * ============================================================
		 * Lowers are NOT per-state (an AR/AK-pattern lower is restricted
		 * in EVERY enabled rifle-class AWB state uniformly). Badges are
		 * informational, not clickable filters — matches the awbmodels
		 * state-badge pill style so the two pages read alike.
		 */
		$totals = [ 'flag' => 0, 'review' => 0, 'clear' => 0, 'skip' => 0, 'total' => 0 ];
		try
		{
			foreach ( \IPS\Db::i()->select( 'upc, category_id, title, brand, manufacturer, model, mpn, caliber',
				'gd_catalog', [ 'category_id=?', \IPS\gdcompliance\Lowers::CATEGORY_LOWER ] ) as $p )
			{
				$totals['total']++;
				$v = \IPS\gdcompliance\Lowers::classify( $p );
				if ( !is_array( $v ) ) { $totals['skip']++; continue; }
				$verd = (string) ( $v['verdict'] ?? '' );
				if ( isset( $totals[ $verd ] ) ) { $totals[ $verd ]++; }
			}
		}
		catch ( \Throwable ) {}

		/* Enabled rifle-class AWB states from gd_compliance_awb_rules. */
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

		$statesRow = '<div style="margin:12px 0 4px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">'
			. '<span style="font-size:12px;color:#64748b;font-weight:600;margin-right:4px">STATES:</span>';
		if ( empty( $awbStates ) )
		{
			$statesRow .= '<span style="color:#94a3b8;font-size:12px">No enabled rifle-class AWB states in gd_compliance_awb_rules.</span>';
		}
		else
		{
			foreach ( $awbStates as $sc )
			{
				$statesRow .= '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e3a8a">' . $h( $sc ) . '</span>';
			}
		}
		$statesRow .= '</div>';

		$statesCaption = '<p style="margin:6px 0 0;color:#64748b;font-size:12px">'
			. 'AR/AK-pattern lower receivers are restricted for sale in each of these states. Lowers apply uniformly across the set (no per-state filter — a serialized AWB-pattern lower IS the assault weapon).'
			. '</p>';

		$summary = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 6px">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_title' ) ) . '</h2>'
			. '<p style="margin:0 0 10px;color:#475569">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_intro' ) ) . '</p>'
			. '<div style="display:flex;gap:16px;flex-wrap:wrap;font-size:13px">'
			. '<div><strong style="color:#0f172a;font-size:20px">' . number_format( (int) $totals['total'] ) . '</strong><br><span style="color:#64748b">cat154 rows</span></div>'
			. '<div><strong style="color:#991b1b;font-size:20px">' . number_format( (int) $totals['flag'] ) . '</strong><br><span style="color:#64748b">will flag</span></div>'
			. '<div><strong style="color:#a16207;font-size:20px">' . number_format( (int) $totals['review'] ) . '</strong><br><span style="color:#64748b">to review</span></div>'
			. '<div><strong style="color:#059669;font-size:20px">' . number_format( (int) $totals['clear'] ) . '</strong><br><span style="color:#64748b">force clear</span></div>'
			. '<div><strong style="color:#94a3b8;font-size:20px">' . number_format( (int) $totals['skip'] ) . '</strong><br><span style="color:#64748b">skipped (parts)</span></div>'
			. '</div>'
			. $statesRow
			. $statesCaption
			. '</div></div>';

		/* ============================================================
		 * SECTION 2 — Flagged Lower Receivers (the actual banned items)
		 * ============================================================
		 * Distinct UPCs from gd_compliance_flags WHERE firearm_type =
		 * 'awb_lower' joined to gd_catalog. Uses IPS native
		 * ->select([table, alias], ...)->join([table, alias], ...) —
		 * NO raw preparedQuery here.
		 *
		 * GROUP BY f.upc collapses the per-state duplicates (one
		 * gd_compliance_flags row per (upc, state)) so the admin sees
		 * one product per row, with state_count = COUNT(state).
		 */
		$distinctFlagged = 0;
		try
		{
			$distinctFlagged = (int) \IPS\Db::i()->select(
				'COUNT(DISTINCT upc)',
				'gd_compliance_flags',
				[ 'firearm_type=?', 'awb_lower' ]
			)->first();
		}
		catch ( \Throwable ) {}

		$reviewPending = 0;
		try
		{
			$reviewPending = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				'gd_compliance_review',
				[ 'suggested_status=? AND resolved=0', 'lower_review' ]
			)->first();
		}
		catch ( \Throwable ) {}

		$page   = max( 1, (int) ( \IPS\Request::i()->flagged_page ?? 1 ) );
		$per    = 50;
		$off    = ( $page - 1 ) * $per;
		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lowers' );

		$flaggedRowsHtml = '';
		try
		{
			$sel = \IPS\Db::i()->select(
				'f.upc, COUNT(*) AS state_count, MAX(f.reason) AS sample_reason, c.brand, c.title, c.caliber, c.mpn, c.category_id',
				[ 'gd_compliance_flags', 'f' ],
				[ "f.firearm_type=?", 'awb_lower' ],
				'c.brand ASC, c.title ASC',
				[ $off, $per ],
				'f.upc'
			)->join( [ 'gd_catalog', 'c' ], 'c.upc = f.upc', 'LEFT' );

			foreach ( $sel as $r )
			{
				$upc    = (string) ( $r['upc'] ?? '' );
				$brand  = (string) ( $r['brand'] ?? '' );
				$title  = (string) ( $r['title'] ?? '' );
				$cal    = (string) ( $r['caliber'] ?? '' );
				$mpn    = (string) ( $r['mpn'] ?? '' );
				$sc     = (int)    ( $r['state_count'] ?? 0 );
				$reason = (string) ( $r['sample_reason'] ?? '' );

				/* Pull "matched pattern: X" out of the reason string
				   (populated by Engine::computeFlags Phase 7A). */
				$matched = '';
				if ( $reason !== '' && preg_match( '/matched pattern:\s*([^;]+?)(\s*\[curated\])?\s*$/i', $reason, $m ) )
				{
					$matched = trim( (string) $m[1] );
				}
				$curated = ( strpos( $reason, '[curated]' ) !== false );

				$lookupUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lookup&do=upc' )
					->setQueryString( 'upc', $upc );

				$flaggedRowsHtml .= '<tr>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px"><a href="' . $h( $lookupUrl ) . '">' . $h( $upc ) . '</a></td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-weight:700">' . $h( $brand !== '' ? $brand : '—' ) . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13px">' . $h( strlen( $title ) > 90 ? substr( $title, 0, 87 ) . '…' : $title ) . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:12px">' . $h( $cal !== '' ? $cal : '—' ) . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px;color:#64748b">' . $h( $mpn !== '' ? $mpn : '—' ) . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9">'
					. ( $matched !== '' ? '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e3a8a">' . $h( $matched ) . '</span>' : '<span style="color:#cbd5e1">—</span>' )
					. ( $curated ? ' <span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;background:#fef3c7;color:#78350f">CURATED</span>' : '' )
					. '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-family:ui-monospace,monospace;color:#991b1b;font-weight:700">' . number_format( $sc ) . '</td>'
					. '</tr>';
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'lowers flagged list: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		if ( $flaggedRowsHtml === '' )
		{
			$flaggedRowsHtml = '<tr><td colspan="7" style="padding:20px;text-align:center;color:#94a3b8">No lower-receiver flags yet. Run the compute pass to populate — cat154 rows classified as \'flag\' will land here.</td></tr>';
		}

		$reviewLink = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review' )
			->setQueryString( 'suggested_status', 'lower_review' );
		$reviewLine = $reviewPending > 0
			? '<p style="margin:0 0 10px;color:#a16207;font-size:12px"><strong>' . number_format( $reviewPending ) . '</strong> lower(s) routed to review (bolt-action / rimfire / ambiguous). <a href="' . $h( $reviewLink ) . '">Open review queue &rarr;</a></p>'
			: '';

		$flaggedTable = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 6px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_flagged_title' ) )
			. ' <span style="color:#64748b;font-weight:400">(' . number_format( $distinctFlagged ) . ')</span></h3>'
			. '<p style="margin:0 0 10px;color:#64748b;font-size:12px">' . $h( $lang->addToStack( 'gdcompliance_acp_lowers_flagged_intro' ) ) . '</p>'
			. $reviewLine
			. '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse">'
			. '<thead><tr style="background:#f8fafc">'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">UPC</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Brand</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Title</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Caliber</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">MPN</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Matched</th>'
			. '<th style="text-align:right;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">States</th>'
			. '</tr></thead>'
			. '<tbody>' . $flaggedRowsHtml . '</tbody>'
			. '</table></div>';

		if ( $distinctFlagged > $per )
		{
			$totalPages = (int) ceil( $distinctFlagged / $per );
			$prevHref   = (string) $baseUrl->setQueryString( 'flagged_page', $page > 1 ? $page - 1 : null );
			$nextHref   = (string) $baseUrl->setQueryString( 'flagged_page', $page < $totalPages ? $page + 1 : null );
			$flaggedTable .= '<div style="display:flex;gap:8px;justify-content:center;margin-top:12px;font-size:13px;color:#64748b">'
				. ( $page > 1 ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $prevHref ) . '">&larr; Prev</a>' : '' )
				. '<span style="padding:4px 8px">Page ' . $page . ' / ' . $totalPages . '</span>'
				. ( $page < $totalPages ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $nextHref ) . '">Next &rarr;</a>' : '' )
				. '</div>';
		}
		$flaggedTable .= '</div></div>';

		/* Test box — enter a UPC, show the verdict live. Kept from
		   v1.6.10; parked between the flagged list and the curated
		   overrides. */
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

		$testUrl  = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lowers' );
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
		 * SECTION 3 — Curated overrides (existing Table\Db, restyled)
		 * ============================================================
		 * The gd_compliance_lowers CRUD. Sits BELOW the flagged list
		 * so the admin sees the actual banned items first.
		 */
		$table = new \IPS\Helpers\Table\Db( 'gd_compliance_lowers', $baseUrl );
		$table->langPrefix    = 'gdcompliance_acp_lowers_col_';
		$table->include       = [ 'pattern', 'platform', 'action', 'note' ];
		$table->sortBy        = $table->sortBy ?: 'action';
		$table->sortDirection = $table->sortDirection ?: 'asc';

		$actionColors = [
			'force_flag'  => [ 'bg' => '#fee2e2', 'fg' => '#991b1b', 'lbl' => 'FLAG' ],
			'force_clear' => [ 'bg' => '#dcfce7', 'fg' => '#065f46', 'lbl' => 'CLEAR' ],
			'review'      => [ 'bg' => '#fef3c7', 'fg' => '#78350f', 'lbl' => 'REVIEW' ],
		];
		$table->parsers = [
			'pattern'  => fn( $v ) => '<strong style="font-family:ui-monospace,monospace">' . $h( (string) $v ) . '</strong>',
			'platform' => fn( $v ) => $v ? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e3a8a">' . $h( (string) $v ) . '</span>' : '<span style="color:#cbd5e1">—</span>',
			'action'   => function( $v ) use ( $h, $actionColors ) {
				$key = (string) $v;
				$c   = $actionColors[ $key ] ?? [ 'bg' => '#f1f5f9', 'fg' => '#334155', 'lbl' => strtoupper( $key ) ];
				return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:' . $c['bg'] . ';color:' . $c['fg'] . '">' . $h( $c['lbl'] ) . '</span>';
			},
			'note'     => fn( $v ) => $v ? '<span style="color:#475569;font-size:12px">' . $h( (string) $v ) . '</span>' : '<span style="color:#cbd5e1">—</span>',
		];
		$table->rowButtons = function( $row ) {
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
		\IPS\Output::i()->output = $summary . $flaggedTable . $testCard . $curatedIntro . (string) $table;
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
