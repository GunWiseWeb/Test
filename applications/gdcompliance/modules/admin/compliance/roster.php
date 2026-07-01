<?php
namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _roster extends \IPS\Dispatcher\Controller
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
		$h    = fn( string $k ) => htmlspecialchars( (string) $lang->addToStack( $k ), ENT_QUOTES, 'UTF-8' );

		/* Per-state row counts for the header summary. */
		$counts = [ 'CA' => 0, 'MA' => 0, 'MD' => 0 ];
		$current = [ 'CA' => 0, 'MA' => 0, 'MD' => 0 ];
		try
		{
			foreach ( \IPS\Db::i()->select( "roster_state, COUNT(*) AS c, SUM(is_current) AS curr", 'gd_compliance_roster', null, 'roster_state ASC', null, 'roster_state' ) as $row )
			{
				$st = (string) $row['roster_state'];
				if ( isset( $counts[ $st ] ) )
				{
					$counts[ $st ]  = (int) $row['c'];
					$current[ $st ] = (int) $row['curr'];
				}
			}
		}
		catch ( \Throwable ) {}

		$caRefreshUrl       = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster&do=refresh' )->csrf();
		$maRefreshUrl       = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster&do=refreshMA' )->csrf();
		$mdRefreshUrl       = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster&do=refreshMD' )->csrf();
		$mdDisapprovedUrl2  = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster&do=refreshMDDisapproved' )->csrf();
		$mdUploadUrl        = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster&do=mdImport' );
		$refreshAllUrl      = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster&do=refreshAll' )->csrf();

		$maUrl           = htmlspecialchars( (string) ( \IPS\Settings::i()->gdcompliance_ma_roster_url        ?? \IPS\gdcompliance\Roster::MA_ROSTER_URL_DEFAULT ), ENT_QUOTES, 'UTF-8' );
		$mdApprovedUrl   = htmlspecialchars( (string) ( \IPS\Settings::i()->gdcompliance_md_roster_url        ?? \IPS\gdcompliance\Roster::MD_ROSTER_URL_DEFAULT ), ENT_QUOTES, 'UTF-8' );
		$mdDisapprovedU  = htmlspecialchars( (string) ( \IPS\Settings::i()->gdcompliance_md_disapproved_url   ?? \IPS\gdcompliance\Roster::MD_DISAPPROVED_URL_DEFAULT ), ENT_QUOTES, 'UTF-8' );
		$caUrl           = htmlspecialchars( \IPS\gdcompliance\Roster::ROSTER_URL, ENT_QUOTES, 'UTF-8' );

		/* Data-vintage per (state, list_type) — surfaces as_of_date so
		   Derrick sees exactly how stale each list is. */
		$asOf = [];
		try
		{
			foreach ( \IPS\Db::i()->select( "roster_state, list_type, MAX(as_of_date) AS d", 'gd_compliance_roster', null, null, null, 'roster_state, list_type' ) as $row )
			{
				$asOf[ (string) $row['roster_state'] ][ (string) $row['list_type'] ] = (string) $row['d'];
			}
		}
		catch ( \Throwable ) {}
		$fmtAsOf = function( ?string $d ) { return $d ? htmlspecialchars( 'as of ' . $d, ENT_QUOTES, 'UTF-8' ) : '<em style="color:#94a3b8">no data yet</em>'; };

		$intro = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 10px">' . $h( 'gdcompliance_acp_roster_title' ) . '</h2>'
			. '<p style="margin:0 0 10px">' . $h( 'gdcompliance_acp_roster_intro' ) . '</p>'

			/* Refresh-all convenience: sequentially runs CA + MA + MD approved + MD disapproved.
			   Any per-source failure is surfaced inline in the flash message. */
			. '<p style="margin:0 0 14px"><a href="' . htmlspecialchars( $refreshAllUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--primary ipsButton--small">'
			. $h( 'gdcompliance_acp_roster_refresh_all' )
			. '</a> <span style="color:#64748b;font-size:12px">'
			. $h( 'gdcompliance_acp_roster_refresh_all_hint' )
			. '</span></p>'

			/* CA */
			. '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:10px">'
			. '<strong style="min-width:60px">CA</strong>'
			. '<span style="color:#475569;font-size:13px">' . number_format( $counts['CA'] ) . ' rows (' . number_format( $current['CA'] ) . ' current) &middot; ' . $fmtAsOf( $asOf['CA']['approved'] ?? null ) . ' &middot; <a href="' . $caUrl . '" target="_blank" rel="noopener">DOJ source</a></span>'
			. '<a href="' . htmlspecialchars( $caRefreshUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--primary ipsButton--small" style="margin-left:auto">' . $h( 'gdcompliance_acp_roster_refresh' ) . '</a>'
			. '</div>'

			/* MA */
			. '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:10px">'
			. '<strong style="min-width:60px">MA</strong>'
			. '<span style="color:#475569;font-size:13px">' . number_format( $counts['MA'] ) . ' rows &middot; ' . $fmtAsOf( $asOf['MA']['approved'] ?? null ) . ' &middot; <a href="' . $maUrl . '" target="_blank" rel="noopener">PDF source</a> (set <code>gdcompliance_ma_roster_url</code> if URL changes)</span>'
			. '<a href="' . htmlspecialchars( $maRefreshUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--primary ipsButton--small" style="margin-left:auto">' . $h( 'gdcompliance_acp_roster_refresh_ma' ) . '</a>'
			. '</div>'

			/* MD Approved (auto PDF) */
			. '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:10px">'
			. '<strong style="min-width:60px">MD approved</strong>'
			. '<span style="color:#475569;font-size:13px">' . $fmtAsOf( $asOf['MD']['approved'] ?? null ) . ' &middot; <a href="' . $mdApprovedUrl . '" target="_blank" rel="noopener">MSP PDF (yearly edition)</a></span>'
			. '<a href="' . htmlspecialchars( $mdRefreshUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--primary ipsButton--small" style="margin-left:auto">' . $h( 'gdcompliance_acp_roster_refresh_md' ) . '</a>'
			. '</div>'

			/* MD Disapproved (auto PDF — hard restrict signal) */
			. '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;margin-bottom:10px">'
			. '<strong style="min-width:60px;color:#9a3412">MD disapproved</strong>'
			. '<span style="color:#7c2d12;font-size:13px">' . $fmtAsOf( $asOf['MD']['disapproved'] ?? null ) . ' &middot; <a href="' . $mdDisapprovedU . '" target="_blank" rel="noopener">MSP denylist PDF</a> &middot; matching this list = <strong>hard restrict</strong></span>'
			. '<a href="' . htmlspecialchars( $mdDisapprovedUrl2, ENT_QUOTES, 'UTF-8' ) . '" class="ipsButton ipsButton--primary ipsButton--small" style="margin-left:auto">' . $h( 'gdcompliance_acp_roster_refresh_md_dis' ) . '</a>'
			. '</div>'

			/* MD Manual CSV Override */
			. '<div style="padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:0">'
			. '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:6px">'
			. '<strong style="min-width:60px">MD CSV override</strong>'
			. '<span style="color:#475569;font-size:13px">Freshest export from the MSP Tableau portal wins; supersedes the PDF for whichever list_type your CSV contains.</span>'
			. '</div>'
			. '<p style="margin:0 0 8px;font-size:12px;color:#64748b">' . $h( 'gdcompliance_acp_roster_md_help' ) . '</p>'
			. '<form action="' . htmlspecialchars( $mdUploadUrl, ENT_QUOTES, 'UTF-8' ) . '" method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
			. '<input type="hidden" name="csrfKey" value="' . htmlspecialchars( \IPS\Session::i()->csrfKey, ENT_QUOTES, 'UTF-8' ) . '">'
			. '<input type="file" name="md_csv" accept=".csv,text/csv" required>'
			. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">' . $h( 'gdcompliance_acp_roster_import_md' ) . '</button>'
			. '</form>'
			. '</div>'

			. '</div></div>';

		/* Browser — filterable by state. */
		$stateFilter = strtoupper( trim( (string) ( \IPS\Request::i()->roster_state ?? '' ) ) );
		if ( !in_array( $stateFilter, [ 'CA', 'MA', 'MD' ], true ) ) { $stateFilter = ''; }

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster' );
		$tabs    = '<div style="margin:0 0 12px;display:flex;gap:6px;flex-wrap:wrap">';
		foreach ( [ '' => 'gdcompliance_acp_roster_tab_all', 'CA' => 'gdcompliance_acp_roster_tab_ca',
		            'MA' => 'gdcompliance_acp_roster_tab_ma', 'MD' => 'gdcompliance_acp_roster_tab_md' ] as $key => $lk )
		{
			$active = $stateFilter === $key ? ' ipsButton--primary' : ' ipsButton--soft';
			$href   = $key === '' ? (string) $baseUrl : (string) $baseUrl->setQueryString( 'roster_state', $key );
			$tabs .= '<a class="ipsButton ipsButton--small' . $active . '" href="' . htmlspecialchars( $href, ENT_QUOTES, 'UTF-8' ) . '">' . $h( $lk ) . '</a>';
		}
		$tabs .= '</div>';

		$where   = $stateFilter !== '' ? [ [ 'roster_state=?', $stateFilter ] ] : [];
		$tableUrl = $stateFilter !== '' ? $baseUrl->setQueryString( 'roster_state', $stateFilter ) : $baseUrl;
		$table = new \IPS\Helpers\Table\Db( 'gd_compliance_roster', $tableUrl, $where );
		$table->langPrefix    = 'gdcompliance_acp_roster_col_';
		$table->include       = [ 'roster_state', 'list_type', 'manufacturer', 'model_raw', 'caliber', 'blanket', 'blanket_caliber', 'source_label', 'as_of_date', 'is_current' ];
		$table->sortBy        = $table->sortBy ?: 'manufacturer';
		$table->sortDirection = $table->sortDirection ?: 'asc';

		$table->parsers = [
			'roster_state' => function( $v ) {
				$pill = match( strtoupper( (string) $v ) ) {
					'CA' => 'background:#dbeafe;color:#1e3a8a',
					'MA' => 'background:#dcfce7;color:#14532d',
					'MD' => 'background:#fef3c7;color:#92400e',
					default => 'background:#f1f5f9;color:#475569',
				};
				return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;' . $pill . '">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'manufacturer' => function( $v ) { return '<strong>' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</strong>'; },
			'model_raw'    => function( $v ) { return '<span style="font-family:ui-monospace,monospace;font-size:12px">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>'; },
			'caliber'      => function( $v ) { return $v ? htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">—</span>'; },
			'list_type'    => function( $v ) {
				$lt = strtolower( (string) $v );
				$pill = $lt === 'disapproved'
					? 'background:#fee2e2;color:#991b1b'
					: 'background:#dcfce7;color:#14532d';
				return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;' . $pill . '">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'blanket'      => function( $v ) {
				return ( (int) $v === 1 )
					? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#14532d">ALL MODELS</span>'
					: '<span style="color:#cbd5e1">—</span>';
			},
			'blanket_caliber' => function( $v ) {
				return ( (int) $v === 1 )
					? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e3a8a">ANY CAL</span>'
					: '<span style="color:#cbd5e1">—</span>';
			},
			'source_label' => function( $v ) { return $v ? '<span style="color:#64748b;font-size:12px">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>' : '<span style="color:#cbd5e1">—</span>'; },
			'as_of_date'   => function( $v ) { return $v ? '<span style="font-family:ui-monospace,monospace;font-size:12px">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>' : '<span style="color:#cbd5e1">—</span>'; },
			'is_current'   => function( $v ) {
				return ( (int) $v === 1 )
					? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#14532d">CURRENT</span>'
					: '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b">EXPIRED</span>';
			},
		];

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_roster_title' );
		\IPS\Output::i()->output = $intro . $tabs . (string) $table;
	}

	protected function refresh(): void
	{
		\IPS\Session::i()->csrfCheck();

		$counts = [ 'rows' => 0, 'pages' => 0, 'current' => 0, 'expired' => 0, 'errors' => [], 'duration_ms' => 0 ];
		try
		{
			$counts = \IPS\gdcompliance\Roster::fetchAndParse();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'roster refresh: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_roster_done', false, [
			'sprintf' => [ (int) $counts['rows'], (int) $counts['current'], (int) $counts['expired'], (int) $counts['pages'] ],
		] );
		$msg .= self::errorTail( 'CA', $counts );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster' ),
			$msg
		);
	}

	protected function refreshMA(): void
	{
		\IPS\Session::i()->csrfCheck();

		$counts = [ 'rows' => 0, 'current' => 0, 'errors' => [], 'duration_ms' => 0, 'extractor' => '', 'url' => '' ];
		try { $counts = \IPS\gdcompliance\Roster::fetchMA(); }
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'roster refresh MA: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_roster_ma_done', false, [
			'sprintf' => [ (int) $counts['rows'], (string) $counts['extractor'], count( (array) $counts['errors'] ) ],
		] );
		$msg .= self::errorTail( 'MA', $counts );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster' ),
			$msg
		);
	}

	protected function refreshMD(): void
	{
		\IPS\Session::i()->csrfCheck();
		$counts = [ 'rows' => 0, 'split' => 0, 'blanket_caliber' => 0, 'errors' => [], 'duration_ms' => 0, 'extractor' => '', 'as_of_date' => null ];
		try { $counts = \IPS\gdcompliance\Roster::fetchMD(); }
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'roster refresh MD: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_roster_md_pdf_done', false, [
			'sprintf' => [ (int) $counts['rows'], (string) ( $counts['as_of_date'] ?? '—' ), (int) $counts['split'], (int) $counts['blanket_caliber'], count( (array) $counts['errors'] ) ],
		] );
		$msg .= self::errorTail( 'MD approved', $counts );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster' ),
			$msg
		);
	}

	protected function refreshMDDisapproved(): void
	{
		\IPS\Session::i()->csrfCheck();
		$counts = [ 'rows' => 0, 'errors' => [], 'duration_ms' => 0, 'extractor' => '', 'as_of_date' => null ];
		try { $counts = \IPS\gdcompliance\Roster::fetchMDDisapproved(); }
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'roster refresh MD disapproved: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_roster_md_dis_done', false, [
			'sprintf' => [ (int) $counts['rows'], (string) ( $counts['as_of_date'] ?? '—' ), count( (array) $counts['errors'] ) ],
		] );
		$msg .= self::errorTail( 'MD disapproved', $counts );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster' ),
			$msg
		);
	}

	/**
	 * Compact error string for the ACP flash redirect. Pulls up to the
	 * first 3 error strings from a fetcher's $counts['errors'] array so
	 * Derrick sees WHY a fetch returned zero rows without having to open
	 * the log. Also surfaces skipped_wipe / extractor / url when set.
	 */
	protected static function errorTail( string $tag, array $counts ): string
	{
		$parts = [];
		if ( !empty( $counts['skipped_wipe'] ) )
		{
			$parts[] = 'kept ' . (int) ( $counts['existing_rows'] ?? 0 ) . ' existing rows';
		}
		if ( !empty( $counts['extractor'] ) )
		{
			$parts[] = 'extractor=' . (string) $counts['extractor'];
		}
		if ( !empty( $counts['url'] ) )
		{
			$parts[] = 'url=' . (string) $counts['url'];
		}
		$errs = (array) ( $counts['errors'] ?? [] );
		if ( !empty( $errs ) )
		{
			$show = array_slice( array_map( 'strval', $errs ), 0, 3 );
			$parts[] = 'errors: ' . implode( ' | ', $show );
			if ( count( $errs ) > 3 )
			{
				$parts[] = '+' . ( count( $errs ) - 3 ) . ' more (see gdcompliance log)';
			}
		}
		return empty( $parts ) ? '' : ' — [' . $tag . '] ' . implode( ' · ', $parts );
	}

	/**
	 * Run all four fetchers back-to-back and redirect with a combined
	 * summary. Each fetcher runs in its own try/catch so a single
	 * broken source doesn't stop the others.
	 */
	protected function refreshAll(): void
	{
		\IPS\Session::i()->csrfCheck();

		$msgs = [];

		$ca = [ 'rows' => 0, 'errors' => [] ];
		try { $ca = \IPS\gdcompliance\Roster::fetchAndParse(); }
		catch ( \Throwable $e )
		{
			$ca['errors'][] = 'threw: ' . $e->getMessage();
			try { \IPS\Log::log( 'refreshAll CA: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		$msgs[] = 'CA=' . (int) $ca['rows'] . self::errorTail( 'CA', $ca );

		$ma = [ 'rows' => 0, 'errors' => [] ];
		try { $ma = \IPS\gdcompliance\Roster::fetchMA(); }
		catch ( \Throwable $e )
		{
			$ma['errors'][] = 'threw: ' . $e->getMessage();
			try { \IPS\Log::log( 'refreshAll MA: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		$msgs[] = 'MA=' . (int) $ma['rows'] . self::errorTail( 'MA', $ma );

		$md = [ 'rows' => 0, 'errors' => [] ];
		try { $md = \IPS\gdcompliance\Roster::fetchMD(); }
		catch ( \Throwable $e )
		{
			$md['errors'][] = 'threw: ' . $e->getMessage();
			try { \IPS\Log::log( 'refreshAll MD: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		$msgs[] = 'MD/A=' . (int) $md['rows'] . self::errorTail( 'MD approved', $md );

		$mdD = [ 'rows' => 0, 'errors' => [] ];
		try { $mdD = \IPS\gdcompliance\Roster::fetchMDDisapproved(); }
		catch ( \Throwable $e )
		{
			$mdD['errors'][] = 'threw: ' . $e->getMessage();
			try { \IPS\Log::log( 'refreshAll MD disapproved: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		$msgs[] = 'MD/D=' . (int) $mdD['rows'] . self::errorTail( 'MD disapproved', $mdD );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster' ),
			'refreshAll: ' . implode( ' | ', $msgs )
		);
	}

	protected function mdImport(): void
	{
		\IPS\Session::i()->csrfCheck();

		$csvText = '';
		if ( isset( $_FILES['md_csv'] ) && is_array( $_FILES['md_csv'] ) && ( $_FILES['md_csv']['error'] ?? 1 ) === UPLOAD_ERR_OK )
		{
			$tmp = (string) ( $_FILES['md_csv']['tmp_name'] ?? '' );
			if ( $tmp !== '' && is_uploaded_file( $tmp ) )
			{
				$csvText = (string) @file_get_contents( $tmp );
			}
		}

		$counts = [ 'rows' => 0, 'blanket' => 0, 'errors' => [], 'duration_ms' => 0 ];
		if ( $csvText !== '' )
		{
			try { $counts = \IPS\gdcompliance\Roster::importMD( $csvText ); }
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'roster import MD: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			}
		}
		else
		{
			$counts['errors'][] = 'no CSV uploaded';
		}

		$msg = (string) \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_roster_md_done', false, [
			'sprintf' => [ (int) $counts['rows'], (int) $counts['blanket'], count( (array) $counts['errors'] ) ],
		] );
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=roster' ),
			$msg
		);
	}
}

class roster extends _roster {}
