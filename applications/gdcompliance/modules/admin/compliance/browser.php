<?php
/**
 * @brief  GD Compliance — Restrictions Browser
 *
 * "What products are flagged?" ACP page. Table\Db over gd_compliance_flags
 * joined to gd_catalog for product title. Searchable by UPC or product
 * title (q), filterable by state_code + reason type (capacity / roster /
 * override), paginated. Read-only.
 *
 * Complements the Rules table ("what's technically banned") and the
 * per-UPC lookup ("why did this UPC flag / not flag").
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _browser extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const STATES = [
		'AL','AK','AZ','AR','CA','CO','CT','DC','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
		'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
		'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
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

		$q     = trim( (string) ( \IPS\Request::i()->q     ?? '' ) );
		$state = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		$type  = trim( (string) ( \IPS\Request::i()->type ?? '' ) );

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=browser' );

		/* --- Filter form (GET) --- */
		$stateOpts = '<option value="">— all —</option>';
		foreach ( self::STATES as $st )
		{
			$sel = $state === $st ? ' selected' : '';
			$stateOpts .= '<option value="' . $h( $st ) . '"' . $sel . '>' . $h( $st ) . '</option>';
		}

		$typeOpts = '<option value="">— all —</option>';
		foreach ( [ 'capacity' => 'Capacity', 'roster' => 'Roster', 'awb' => 'AWB (assault weapons ban)', 'override' => 'Manual override' ] as $k => $v )
		{
			$sel = $type === $k ? ' selected' : '';
			$typeOpts .= '<option value="' . $h( $k ) . '"' . $sel . '>' . $h( $v ) . '</option>';
		}

		$filterForm = '<form method="get" action="' . $h( (string) $baseUrl ) . '" class="ipsBox ipsPad" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:0 0 14px">'
			. '<input type="hidden" name="app" value="gdcompliance">'
			. '<input type="hidden" name="module" value="compliance">'
			. '<input type="hidden" name="controller" value="browser">'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;flex:1 1 240px"><span>' . $h( $lang->addToStack( 'gdcompliance_acp_browser_q' ) ) . '</span><input type="search" name="q" value="' . $h( $q ) . '" placeholder="UPC or product title"></label>'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;min-width:120px"><span>' . $h( $lang->addToStack( 'gdcompliance_acp_browser_state' ) ) . '</span><select name="state">' . $stateOpts . '</select></label>'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;min-width:140px"><span>' . $h( $lang->addToStack( 'gdcompliance_acp_browser_type' ) ) . '</span><select name="type">' . $typeOpts . '</select></label>'
			. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">' . $h( $lang->addToStack( 'gdcompliance_acp_search_go' ) ) . '</button>'
			. ( ( $q !== '' || $state !== '' || $type !== '' ) ? ' <a href="' . $h( (string) $baseUrl ) . '" class="ipsButton ipsButton--soft ipsButton--small" style="margin-left:6px">Clear</a>' : '' )
			. '</form>';

		$intro = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $h( $lang->addToStack( 'gdcompliance_acp_browser_title' ) ) . '</h2>'
			. '<p style="margin:0 0 10px;color:#475569">' . $h( $lang->addToStack( 'gdcompliance_acp_browser_intro' ) ) . '</p>'
			. $filterForm
			. '</div></div>';

		/* --- WHERE clause + args --- */
		$whereParts = [ '1=1' ];
		$whereArgs  = [];

		if ( $state !== '' )
		{
			$whereParts[] = 'f.state_code=?';
			$whereArgs[]  = $state;
		}
		if ( $type === 'capacity' )
		{
			$whereParts[] = "f.rule_id > 0 AND f.firearm_type NOT LIKE 'awb_%' AND f.firearm_type NOT LIKE 'pica_%'";
		}
		elseif ( $type === 'roster' )
		{
			$whereParts[] = "f.rule_id = 0 AND f.firearm_type <> 'manual' AND f.firearm_type NOT LIKE 'awb_%' AND f.firearm_type NOT LIKE 'pica_%'";
		}
		elseif ( $type === 'awb' )
		{
			$whereParts[] = "f.firearm_type LIKE 'awb_%' OR f.firearm_type LIKE 'pica_%'";
		}
		elseif ( $type === 'override' )
		{
			$whereParts[] = "f.firearm_type = 'manual' AND f.rule_id = 0";
		}
		if ( $q !== '' )
		{
			$like = '%' . $q . '%';
			$whereParts[] = '(f.upc = ? OR c.title LIKE ?)';
			$whereArgs[]  = $q;
			$whereArgs[]  = $like;
		}
		$where = array_merge( [ implode( ' AND ', $whereParts ) ], $whereArgs );

		/* --- Pagination --- */
		$page = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$per  = 50;

		/* v1.6.5: IPS-native select()->join() with [table, alias] tuples.
		   Earlier preparedQuery rewrite returned mysqli_stmt whose
		   ->fetch_assoc() throws "undefined method"; the IPS select+join
		   form emits proper backtick-quoted aliases and iterates rows
		   without any stmt dance. Where-fragments still use f. / c.
		   aliases; args are passed through the trailing where args
		   array. */
		$whereWithArgs = array_merge( [ implode( ' AND ', $whereParts ) ], $whereArgs );

		$totalRows = 0;
		try
		{
			$totalRows = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				[ 'gd_compliance_flags', 'f' ],
				$whereWithArgs
			)->join(
				[ 'gd_catalog', 'c' ],
				'c.upc=f.upc',
				'LEFT'
			)->first();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'browser count: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$totalPages = max( 1, (int) ceil( $totalRows / $per ) );
		if ( $page > $totalPages ) { $page = $totalPages; }
		$offset = ( $page - 1 ) * $per;

		$rows = [];
		try
		{
			$rowIter = \IPS\Db::i()->select(
				'f.id AS fid, f.upc, f.state_code, f.firearm_type, f.parsed_capacity, f.rule_id, f.reason, f.computed_at, '
				. 'c.title, c.manufacturer, c.model',
				[ 'gd_compliance_flags', 'f' ],
				$whereWithArgs,
				'f.state_code ASC, f.upc ASC',
				[ $offset, $per ]
			)->join(
				[ 'gd_catalog', 'c' ],
				'c.upc=f.upc',
				'LEFT'
			);
			foreach ( $rowIter as $r )
			{
				$rows[] = $r;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'browser fetch: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		/* --- Render table --- */
		$body = '<div class="ipsBox"><div class="ipsBox_body ipsPad">';
		$body .= '<p style="margin:0 0 10px;color:#475569">' . $h( $lang->addToStack( 'gdcompliance_acp_browser_showing' ) ) . ' <strong>' . number_format( $totalRows ) . '</strong> flagged rows'
			. ( $totalRows > 0 ? ' &middot; page ' . $page . ' of ' . $totalPages : '' )
			. '</p>';

		if ( empty( $rows ) )
		{
			$body .= '<p style="margin:0;color:#94a3b8;font-style:italic">' . $h( $lang->addToStack( 'gdcompliance_acp_browser_empty' ) ) . '</p>';
		}
		else
		{
			$body .= '<table class="ipsTable ipsTable_responsive" style="width:100%;border-collapse:collapse">'
				. '<thead><tr>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">State</th>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">UPC</th>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Product</th>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Type</th>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Reason</th>'
				. '<th style="text-align:right;padding:6px 10px;border-bottom:2px solid #e6e9ee"></th>'
				. '</tr></thead><tbody>';

			foreach ( $rows as $r )
			{
				$upc      = (string) ( $r['upc'] ?? '' );
				$title    = trim( (string) ( $r['manufacturer'] ?? '' ) . ' ' . (string) ( $r['model'] ?? '' ) );
				if ( $title === '' ) { $title = (string) ( $r['title'] ?? '(unknown)' ); }
				$reason   = (string) ( $r['reason'] ?? '' );
				$ftype    = (string) ( $r['firearm_type'] ?? '' );
				$ruleId   = (int)    ( $r['rule_id'] ?? 0 );
				$derivedType = ( strncmp( $ftype, 'awb_', 4 ) === 0 || strncmp( $ftype, 'pica_', 5 ) === 0 ) ? 'awb'
					: ( ( $ftype === 'manual' && $ruleId === 0 ) ? 'override'
						: ( $ruleId > 0 ? 'capacity' : 'roster' ) );

				$typeStyle = match( $derivedType ) {
					'capacity' => 'background:#fed7aa;color:#7c2d12',
					'roster'   => 'background:#fecaca;color:#991b1b',
					'awb'      => 'background:#e0f2fe;color:#075985',
					'override' => 'background:#e0e7ff;color:#3730a3',
					default    => 'background:#e2e8f0;color:#475569',
				};

				$lookupUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lookup' )->setQueryString( 'upc', $upc );

				$body .= '<tr>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-weight:700">' . $h( (string) $r['state_code'] ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px">' . $h( $upc ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9">' . $h( $title ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9"><span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;' . $typeStyle . '">' . $h( $derivedType ) . '</span></td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:13px">' . $h( $reason ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right"><a href="' . $h( $lookupUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--verySmall">Lookup</a></td>'
					. '</tr>';
			}
			$body .= '</tbody></table>';

			/* Pagination footer. */
			if ( $totalPages > 1 )
			{
				$body .= '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:12px">';
				for ( $p = max( 1, $page - 3 ); $p <= min( $totalPages, $page + 3 ); $p++ )
				{
					$pageUrl = (string) $baseUrl->setQueryString( array_filter( [
						'q'     => $q     !== '' ? $q     : null,
						'state' => $state !== '' ? $state : null,
						'type'  => $type  !== '' ? $type  : null,
						'page'  => $p,
					] ) );
					$style = $p === $page ? 'background:#1e40af;color:#fff' : 'background:#fff;color:#1e40af';
					$body .= '<a href="' . $h( $pageUrl ) . '" style="display:inline-block;padding:5px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;' . $style . '">' . $p . '</a>';
				}
				$body .= '</div>';
			}
		}

		$body .= '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_browser_title' );
		\IPS\Output::i()->output = $intro . $body;
	}
}

class browser extends _browser {}
