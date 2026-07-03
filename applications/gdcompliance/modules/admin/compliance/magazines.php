<?php
/**
 * @brief  GD Compliance — Magazines monitor (v1.6.10)
 *
 * Read-only view of over-capacity magazine flags emitted by the
 * standalone-magazine capacity pass (cat38, classified by caliber →
 * state's rifle/handgun/shotgun limit). Exceptions are corrected via
 * the existing gd_compliance_overrides (force_clear / force_restrict)
 * — this page provides the "Set override" link per (upc, state) row.
 *
 * No per-mag curation table — capacity math is deterministic. The
 * curation lever is Lowers ACP for lowers; here it's the Rules ACP
 * (edit state limits) and Overrides ACP (per-UPC clear).
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _magazines extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$stateFilter = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( strlen( $stateFilter ) !== 2 ) { $stateFilter = ''; }

		/* --- Per-state summary --- */
		$perState = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'state_code, COUNT(*) AS c',
				'gd_compliance_flags',
				[ 'firearm_type=?', 'magazine' ],
				null,
				null,
				'state_code'
			) as $row )
			{
				$perState[ (string) $row['state_code'] ] = (int) $row['c'];
			}
		}
		catch ( \Throwable ) {}
		ksort( $perState );

		$totalMagFlags = array_sum( $perState );
		$distinctMags  = 0;
		try
		{
			$distinctMags = (int) \IPS\Db::i()->select( 'COUNT(DISTINCT upc)', 'gd_compliance_flags',
				[ 'firearm_type=?', 'magazine' ] )->first();
		}
		catch ( \Throwable ) {}

		$summary = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 6px">' . $h( $lang->addToStack( 'gdcompliance_acp_mag_title' ) ) . '</h2>'
			. '<p style="margin:0 0 10px;color:#475569">' . $h( $lang->addToStack( 'gdcompliance_acp_mag_intro' ) ) . '</p>'
			. '<div style="display:flex;gap:24px;flex-wrap:wrap;font-size:13px">'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $distinctMags ) . '</strong><br><span style="color:#64748b">distinct magazines flagged</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $totalMagFlags ) . '</strong><br><span style="color:#64748b">total flag rows</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( count( $perState ) ) . '</strong><br><span style="color:#64748b">states with mag flags</span></div>'
			. '</div>'
			. '</div></div>';

		/* --- State tabs --- */
		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=magazines' );
		$tabs    = '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px"><span style="font-size:12px;color:#64748b;font-weight:600;align-self:center;margin-right:4px">STATE:</span>';
		$allActive = $stateFilter === '' ? ' ipsButton--primary' : ' ipsButton--soft';
		$tabs .= '<a class="ipsButton ipsButton--verySmall' . $allActive . '" href="' . $h( (string) $baseUrl ) . '">All (' . number_format( $totalMagFlags ) . ')</a>';
		foreach ( $perState as $st => $c )
		{
			$active = $stateFilter === $st ? ' ipsButton--primary' : ' ipsButton--soft';
			$href   = (string) $baseUrl->setQueryString( 'state', $st );
			$tabs .= '<a class="ipsButton ipsButton--verySmall' . $active . '" href="' . $h( $href ) . '">' . $h( $st ) . ' (' . number_format( $c ) . ')</a>';
		}
		$tabs .= '</div>';

		/* --- Flag detail list (paginated) --- */
		$page  = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$per   = 50;
		$off   = ( $page - 1 ) * $per;

		$where = [ 'f.firearm_type=?' ];
		$args  = [ 'magazine' ];
		if ( $stateFilter !== '' )
		{
			$where[] = 'f.state_code=?';
			$args[]  = $stateFilter;
		}
		$whereSql = implode( ' AND ', $where );

		$flagCount = 0;
		try
		{
			$flagCount = (int) \IPS\Db::i()->select( 'COUNT(*)', [ 'gd_compliance_flags', 'f' ],
				array_merge( [ $whereSql ], $args ) )->first();
		}
		catch ( \Throwable ) {}

		$rowsHtml = '';
		try
		{
			$sel = \IPS\Db::i()->select(
				'f.upc, f.state_code, f.reason, f.parsed_capacity, f.citation, c.title, c.caliber',
				[ 'gd_compliance_flags', 'f' ],
				array_merge( [ $whereSql ], $args ),
				'f.state_code ASC, f.parsed_capacity DESC',
				[ $off, $per ]
			)->join( [ 'gd_catalog', 'c' ], 'c.upc = f.upc', 'LEFT' );

			foreach ( $sel as $r )
			{
				$upc         = (string) ( $r['upc'] ?? '' );
				$st          = (string) ( $r['state_code'] ?? '' );
				$overrideUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=overrides&do=form' )
					->setQueryString( [ 'upc' => $upc, 'state' => $st ] );

				$rowsHtml .= '<tr>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px">' . $h( $upc ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9"><strong>' . $h( $st ) . '</strong></td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-size:13px">' . $h( (string) ( $r['title'] ?? '' ) ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:12px">' . $h( (string) ( $r['caliber'] ?? '—' ) ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700">' . number_format( (int) ( $r['parsed_capacity'] ?? 0 ) ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:12px">' . $h( (string) ( $r['reason'] ?? '' ) ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right"><a href="' . $h( $overrideUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--verySmall">' . $h( $lang->addToStack( 'gdcompliance_acp_mag_override' ) ) . '</a></td>'
					. '</tr>';
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'magazines list: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		if ( $rowsHtml === '' )
		{
			$rowsHtml = '<tr><td colspan="7" style="padding:20px;text-align:center;color:#94a3b8">No over-capacity magazines' . ( $stateFilter !== '' ? ' for ' . $h( $stateFilter ) : '' ) . '.</td></tr>';
		}

		$table = '<div class="ipsBox"><div class="ipsBox_body ipsPad">'
			. '<table style="width:100%;border-collapse:collapse">'
			. '<thead><tr style="background:#f8fafc">'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">UPC</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">State</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Title</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Caliber</th>'
			. '<th style="text-align:right;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Cap</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Reason</th>'
			. '<th style="padding:8px 10px;border-bottom:2px solid #e2e8f0"></th>'
			. '</tr></thead>'
			. '<tbody>' . $rowsHtml . '</tbody>'
			. '</table>'
			. '</div></div>';

		/* Simple prev/next pagination. */
		$pager = '';
		if ( $flagCount > $per )
		{
			$totalPages = (int) ceil( $flagCount / $per );
			$prevHref   = (string) $baseUrl->setQueryString( array_filter( [ 'state' => $stateFilter ?: null, 'page' => $page > 1 ? $page - 1 : null ] ) );
			$nextHref   = (string) $baseUrl->setQueryString( array_filter( [ 'state' => $stateFilter ?: null, 'page' => $page < $totalPages ? $page + 1 : null ] ) );
			$pager = '<div style="display:flex;gap:8px;justify-content:center;margin-top:12px;font-size:13px;color:#64748b">'
				. ( $page > 1 ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $prevHref ) . '">&larr; Prev</a>' : '' )
				. '<span style="padding:4px 8px">Page ' . $page . ' / ' . $totalPages . '</span>'
				. ( $page < $totalPages ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $nextHref ) . '">Next &rarr;</a>' : '' )
				. '</div>';
		}

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_mag_title' );
		\IPS\Output::i()->output = $summary . $tabs . $table . $pager;
	}
}

class magazines extends _magazines {}
