<?php
/**
 * @brief  GD Compliance — AWB States dashboard (v1.6.1)
 *
 * Two views:
 *
 *   manage() → landing: table of all AWB states (rows from
 *   gd_compliance_awb_rules) with per-state model + flagged-product
 *   counts and quick enable/disable + "Open" link into the per-state
 *   dashboard.
 *
 *   view()  → single-state dashboard (?state=CA): the state's rule config
 *   editable inline, its named-model list (state-scoped, add/remove),
 *   its flagged catalog products, AND a banned/allowed search box that
 *   answers "is this UPC banned in this state?" for any UPC/title.
 *
 * Uses native ACP chrome (Table\Db, ipsBox, plain form) — no
 * ipsButton_/ipsMessage single-underscore anti-patterns (rule #48).
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _awbstates extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const STATE_NAMES = [
		'CA' => 'California', 'CT' => 'Connecticut', 'DC' => 'District of Columbia',
		'DE' => 'Delaware', 'HI' => 'Hawaii', 'IL' => 'Illinois',
		'MA' => 'Massachusetts', 'MD' => 'Maryland', 'NJ' => 'New Jersey',
		'NY' => 'New York', 'RI' => 'Rhode Island', 'VA' => 'Virginia', 'WA' => 'Washington',
	];

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	/**
	 * Landing: one row per (state, firearm_class) in gd_compliance_awb_rules
	 * with model counts and flagged-product counts. Native ipsBox +
	 * ipsTable — no Table\Db for this overview because we join 3 tables.
	 */
	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$modelCounts = [];
		try
		{
			foreach ( \IPS\Db::i()->select( "state_code, COUNT(*) AS c", 'gd_compliance_awb_models', null, null, null, 'state_code' ) as $row )
			{
				$modelCounts[ (string) $row['state_code'] ] = (int) $row['c'];
			}
		}
		catch ( \Throwable ) {}

		$flagCounts = [];
		try
		{
			foreach ( \IPS\Db::i()->select( "state_code, COUNT(*) AS c", 'gd_compliance_flags', [ "firearm_type LIKE 'awb_%' OR firearm_type LIKE 'pica_%'" ], null, null, 'state_code' ) as $row )
			{
				$flagCounts[ (string) $row['state_code'] ] = (int) $row['c'];
			}
		}
		catch ( \Throwable ) {}

		$rules = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_awb_rules', null, 'state_code ASC, firearm_class ASC' ) as $row )
			{
				$rules[] = $row;
			}
		}
		catch ( \Throwable ) {}

		$out  = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $h( $lang->addToStack( 'gdcompliance_acp_awbstates_title' ) ) . '</h2>'
			. '<p style="margin:0 0 6px">' . $h( $lang->addToStack( 'gdcompliance_acp_awbstates_intro' ) ) . '</p>'
			. '</div></div>';

		$out .= '<div class="ipsBox"><div class="ipsBox_body ipsPad">';
		$out .= '<table class="ipsTable ipsTable_responsive" style="width:100%;border-collapse:collapse">'
			. '<thead><tr>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e6e9ee">State</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e6e9ee">Class</th>'
			. '<th style="text-align:center;padding:8px 10px;border-bottom:2px solid #e6e9ee">Enabled</th>'
			. '<th style="text-align:center;padding:8px 10px;border-bottom:2px solid #e6e9ee">Thresh</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e6e9ee">Effective</th>'
			. '<th style="text-align:right;padding:8px 10px;border-bottom:2px solid #e6e9ee">Models</th>'
			. '<th style="text-align:right;padding:8px 10px;border-bottom:2px solid #e6e9ee">Flagged</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e6e9ee">Citation</th>'
			. '<th style="text-align:right;padding:8px 10px;border-bottom:2px solid #e6e9ee"></th>'
			. '</tr></thead><tbody>';

		if ( empty( $rules ) )
		{
			$out .= '<tr><td colspan="9" style="padding:20px;text-align:center;color:#94a3b8">No AWB rules seeded yet.</td></tr>';
		}
		else
		{
			foreach ( $rules as $r )
			{
				$state = (string) $r['state_code'];
				$class = (string) $r['firearm_class'];
				$name  = self::STATE_NAMES[ $state ] ?? $state;
				$dashUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbstates&do=view' )
					->setQueryString( [ 'state' => $state, 'class' => $class ] );
				$toggleUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbstates&do=toggle' )
					->setQueryString( [ 'id' => (int) $r['id'] ] )->csrf();

				$mc = (int) ( $modelCounts[ $state ] ?? 0 );
				$fc = (int) ( $flagCounts[ $state ]  ?? 0 );
				$enabledPill = (int) $r['enabled'] === 1
					? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#14532d">ON</span>'
					: '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b">OFF</span>';

				$out .= '<tr>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9"><strong>' . $h( $state ) . '</strong> <span style="color:#64748b;font-size:12px">' . $h( $name ) . '</span></td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:12px">' . $h( $class ) . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:center">' . $enabledPill . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:center;font-family:ui-monospace,monospace">' . (int) $r['feature_count_threshold'] . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:12px">' . $h( (string) ( $r['effective_date'] ?? '—' ) ) . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-family:ui-monospace,monospace">' . number_format( $mc ) . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-family:ui-monospace,monospace">' . number_format( $fc ) . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:12px">' . $h( (string) ( $r['citation'] ?? '' ) ) . '</td>'
					. '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:right">'
					. '<a href="' . $h( $dashUrl )   . '" class="ipsButton ipsButton--primary ipsButton--verySmall" style="margin-right:4px">Open</a>'
					. '<a href="' . $h( $toggleUrl ) . '" class="ipsButton ipsButton--soft ipsButton--verySmall">' . ( (int) $r['enabled'] === 1 ? 'Disable' : 'Enable' ) . '</a>'
					. '</td>'
					. '</tr>';
			}
		}
		$out .= '</tbody></table></div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_awbstates_title' );
		\IPS\Output::i()->output = $out;
	}

	/**
	 * Per-state dashboard: config + models + flagged products + search.
	 */
	protected function view(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$state = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		$class = strtolower( trim( (string) ( \IPS\Request::i()->class ?? 'rifle' ) ) );
		if ( !isset( self::STATE_NAMES[ $state ] ) )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbstates' ) );
			return;
		}

		$rule = null;
		try { $rule = \IPS\Db::i()->select( '*', 'gd_compliance_awb_rules', [ 'state_code=? AND firearm_class=?', $state, $class ] )->first(); }
		catch ( \Throwable ) { $rule = null; }

		if ( !is_array( $rule ) )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbstates' ) );
			return;
		}

		/* Handle inline config save (form POST). */
		if ( isset( \IPS\Request::i()->save_config ) )
		{
			\IPS\Session::i()->csrfCheck();
			try
			{
				$len = trim( (string) ( \IPS\Request::i()->max_overall_length_in ?? '' ) );
				\IPS\Db::i()->update( 'gd_compliance_awb_rules', [
					'feature_count_threshold' => max( 1, (int) \IPS\Request::i()->feature_count_threshold ),
					'centerfire_only'         => (int) ( \IPS\Request::i()->centerfire_only ?? 0 ) === 1 ? 1 : 0,
					'max_overall_length_in'   => $len === '' ? null : (float) $len,
					'citation'                => substr( (string) \IPS\Request::i()->citation, 0, 255 ),
					'effective_date'          => self::cleanDate( (string) \IPS\Request::i()->effective_date ),
					'expires_date'            => self::cleanDate( (string) \IPS\Request::i()->expires_date ),
					'enabled'                 => (int) ( \IPS\Request::i()->enabled ?? 0 ) === 1 ? 1 : 0,
					'notes'                   => substr( (string) \IPS\Request::i()->notes, 0, 255 ),
					'updated_at'              => time(),
				], [ 'id=?', (int) $rule['id'] ] );
				try { \IPS\gdcompliance\AwbModels::clearCache(); } catch ( \Throwable ) {}
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'awbstates saveConfig: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			}
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbstates&do=view' )
					->setQueryString( [ 'state' => $state, 'class' => $class ] ),
				'saved'
			);
			return;
		}

		$name       = self::STATE_NAMES[ $state ];
		$backUrl    = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbstates' );
		$csrf       = (string) \IPS\Session::i()->csrfKey;
		$saveUrl    = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbstates&do=view' )
			->setQueryString( [ 'state' => $state, 'class' => $class, 'save_config' => 1 ] );
		$searchUrl  = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbstates&do=view' )
			->setQueryString( [ 'state' => $state, 'class' => $class ] );

		/* --- Config form --- */
		$c = fn( string $k, $def = '' ) => htmlspecialchars( (string) ( $rule[ $k ] ?? $def ), ENT_QUOTES, 'UTF-8' );
		$enabled = (int) $rule['enabled'] === 1;
		$center  = (int) $rule['centerfire_only'] === 1;

		$out  = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad" style="display:flex;align-items:center;gap:12px">'
			. '<a href="' . $h( $backUrl ) . '" class="ipsButton ipsButton--soft ipsButton--small">&larr; All states</a>'
			. '<h2 class="ipsType_sectionHead" style="margin:0"><strong>' . $h( $state ) . '</strong> ' . $h( $name ) . ' &middot; <span style="color:#475569;font-size:14px">' . $h( $class ) . '</span></h2>'
			. '</div></div>';

		$out .= '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 10px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_awbstates_config' ) ) . '</h3>'
			. '<form method="post" action="' . $h( $saveUrl ) . '">'
			. '<input type="hidden" name="csrfKey" value="' . $h( $csrf ) . '">'
			. '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px">'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Enabled</span><select name="enabled">'
			. '<option value="1"' . ( $enabled ? ' selected' : '' ) . '>Enabled</option>'
			. '<option value="0"' . ( !$enabled ? ' selected' : '' ) . '>Disabled</option>'
			. '</select></label>'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Feature threshold (1 = one-feature; 2 = two-feature)</span><input type="number" min="1" max="3" name="feature_count_threshold" value="' . $c( 'feature_count_threshold', '1' ) . '"></label>'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Centerfire only</span><select name="centerfire_only">'
			. '<option value="1"' . ( $center ? ' selected' : '' ) . '>Yes (recommended)</option>'
			. '<option value="0"' . ( !$center ? ' selected' : '' ) . '>No</option>'
			. '</select></label>'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Max overall length (inches, blank = n/a)</span><input type="text" name="max_overall_length_in" placeholder="30.00" value="' . $c( 'max_overall_length_in' ) . '"></label>'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;grid-column:span 2"><span>Citation</span><input type="text" name="citation" maxlength="255" value="' . $c( 'citation' ) . '"></label>'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Effective date (YYYY-MM-DD; blank = active now)</span><input type="text" name="effective_date" placeholder="YYYY-MM-DD" maxlength="10" value="' . $c( 'effective_date' ) . '"></label>'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Expires date</span><input type="text" name="expires_date" placeholder="YYYY-MM-DD" maxlength="10" value="' . $c( 'expires_date' ) . '"></label>'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;grid-column:span 2"><span>Notes</span><textarea name="notes" rows="2">' . $c( 'notes' ) . '</textarea></label>'
			. '</div>'
			. '<div style="margin-top:12px"><button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Save config</button></div>'
			. '</form>'
			. '</div></div>';

		/* --- Model list (state-scoped) --- */
		$modelsUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbmodels' )
			->setQueryString( 'state_code', $state );
		$modelCount = 0;
		try { $modelCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_awb_models', [ 'state_code=?', $state ] )->first(); }
		catch ( \Throwable ) {}

		$out .= '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 10px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_awbstates_models' ) ) . '</h3>'
			. '<p style="margin:0 0 8px;color:#475569">' . number_format( $modelCount ) . ' named models seeded for ' . $h( $state ) . '. Use the Master Model List to add/edit — the state filter is pre-applied.</p>'
			. '<a href="' . $h( $modelsUrl ) . '" class="ipsButton ipsButton--primary ipsButton--small">Open ' . $h( $state ) . ' models</a>'
			. '</div></div>';

		/* --- Banned/allowed search --- */
		$q = trim( (string) ( \IPS\Request::i()->q ?? '' ) );
		$out .= '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 10px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_awbstates_search' ) ) . '</h3>'
			. '<form method="get" action="' . $h( $searchUrl ) . '" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px">'
			. '<input type="hidden" name="app" value="gdcompliance"><input type="hidden" name="module" value="compliance"><input type="hidden" name="controller" value="awbstates"><input type="hidden" name="do" value="view">'
			. '<input type="hidden" name="state" value="' . $h( $state ) . '"><input type="hidden" name="class" value="' . $h( $class ) . '">'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;flex:1 1 260px"><span>UPC or product title</span><input type="search" name="q" value="' . $h( $q ) . '" placeholder="e.g. 022188899658 or M&P FPC"></label>'
			. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Check ' . $h( $state ) . '</button>'
			. '</form>';

		if ( $q !== '' )
		{
			$out .= $this->renderSearchResult( $state, $q );
		}
		$out .= '</div></div>';

		/* --- Flagged products for this state ---
		   Use preparedQuery() for the JOIN because \IPS\Db::i()->select()
		   backtick-quotes the entire table-name argument as one identifier
		   (so a raw "table_a a LEFT JOIN table_b b ON …" string throws
		   "Table doesn't exist"). preparedQuery hands the SQL through
		   verbatim with ? placeholders. */
		$flagBaseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbstates&do=view' )
			->setQueryString( [ 'state' => $state, 'class' => $class ] );
		$prefix    = (string) \IPS\Db::i()->prefix;
		$flagCount = 0;
		try
		{
			$res = \IPS\Db::i()->preparedQuery(
				"SELECT COUNT(*) AS c FROM " . $prefix . "gd_compliance_flags f "
				. "WHERE f.state_code = ? AND (f.firearm_type LIKE 'awb\\_%' OR f.firearm_type LIKE 'pica\\_%')",
				[ $state ]
			);
			if ( $res && ( $row = $res->fetch_assoc() ) )
			{
				$flagCount = (int) $row['c'];
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'awbstates flagCount: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$out .= '<div class="ipsBox"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 10px;font-size:14px;color:#334155">Flagged catalog products in ' . $h( $state ) . ' <span style="color:#64748b;font-weight:400">(' . number_format( $flagCount ) . ' total)</span></h3>';

		/* Simple paginated list (top 100). Derrick can use the Restrictions
		   Browser for full paging + additional filters. */
		try
		{
			$page   = max( 1, (int) ( \IPS\Request::i()->fpage ?? 1 ) );
			$per    = 30;
			$offset = ( $page - 1 ) * $per;
			$pages  = max( 1, (int) ceil( $flagCount / $per ) );
			if ( $page > $pages ) { $page = $pages; $offset = ( $page - 1 ) * $per; }

			$rows = [];
			$res = \IPS\Db::i()->preparedQuery(
				"SELECT f.upc, f.reason, f.citation, c.title, c.brand, c.model "
				. "FROM " . $prefix . "gd_compliance_flags f "
				. "LEFT JOIN " . $prefix . "gd_catalog c ON c.upc = f.upc "
				. "WHERE f.state_code = ? AND (f.firearm_type LIKE 'awb\\_%' OR f.firearm_type LIKE 'pica\\_%') "
				. "ORDER BY f.upc ASC LIMIT " . (int) $offset . ", " . (int) $per,
				[ $state ]
			);
			if ( $res )
			{
				while ( $row = $res->fetch_assoc() )
				{
					$rows[] = $row;
				}
			}

			if ( empty( $rows ) )
			{
				$out .= '<p style="margin:0;color:#94a3b8;font-style:italic">No AWB flags yet for ' . $h( $state ) . '. Recompute to populate.</p>';
			}
			else
			{
				$out .= '<table class="ipsTable ipsTable_responsive" style="width:100%;border-collapse:collapse">'
					. '<thead><tr>'
					. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">UPC</th>'
					. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Product</th>'
					. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Reason</th>'
					. '</tr></thead><tbody>';
				foreach ( $rows as $r )
				{
					$title = trim( (string) ( $r['brand'] ?? '' ) . ' ' . (string) ( $r['model'] ?? '' ) );
					if ( $title === '' ) { $title = (string) ( $r['title'] ?? '(unknown)' ); }
					$lookup = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lookup' )->setQueryString( 'upc', (string) $r['upc'] );
					$out .= '<tr>'
						. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px"><a href="' . $h( $lookup ) . '">' . $h( (string) $r['upc'] ) . '</a></td>'
						. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9">' . $h( $title ) . '</td>'
						. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:13px">' . $h( (string) ( $r['reason'] ?? '' ) ) . '</td>'
						. '</tr>';
				}
				$out .= '</tbody></table>';

				if ( $pages > 1 )
				{
					$out .= '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:12px">';
					for ( $p = max( 1, $page - 3 ); $p <= min( $pages, $page + 3 ); $p++ )
					{
						$pageUrl = (string) $flagBaseUrl->setQueryString( 'fpage', $p );
						$style = $p === $page ? 'background:#1e40af;color:#fff' : 'background:#fff;color:#1e40af';
						$out .= '<a href="' . $h( $pageUrl ) . '" style="display:inline-block;padding:5px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;' . $style . '">' . $p . '</a>';
					}
					$out .= '</div>';
				}
			}
		}
		catch ( \Throwable $e )
		{
			$out .= '<p style="color:#dc2626;margin:0">Error loading flags: ' . $h( $e->getMessage() ) . '</p>';
		}

		$out .= '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_awbstates_title' ) . ' — ' . $state;
		\IPS\Output::i()->output = $out;
	}

	/**
	 * Answers "is this UPC banned in $state?" for the dashboard search box.
	 */
	protected function renderSearchResult( string $state, string $q ): string
	{
		$h = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		/* Match by exact UPC or title LIKE — top 5 hits. */
		$rows = [];
		try
		{
			$like = '%' . $q . '%';
			foreach ( \IPS\Db::i()->select(
				'upc, title, brand, model',
				'gd_catalog',
				[ '(upc=? OR title LIKE ? OR model LIKE ?)', $q, $like, $like ],
				'title ASC',
				[ 0, 5 ]
			) as $row )
			{
				$rows[] = $row;
			}
		}
		catch ( \Throwable ) {}

		if ( empty( $rows ) )
		{
			return '<p style="margin:0;color:#94a3b8;font-style:italic">No catalog product matched "' . $h( $q ) . '".</p>';
		}

		$out = '<div style="border-top:1px solid #e2e8f0;padding-top:8px">';
		foreach ( $rows as $r )
		{
			$upc  = (string) $r['upc'];
			$name = trim( (string) ( $r['brand'] ?? '' ) . ' ' . (string) ( $r['model'] ?? '' ) );
			if ( $name === '' ) { $name = (string) ( $r['title'] ?? '' ); }

			/* Look up flag rows for this UPC in this state. */
			$hits = [];
			try
			{
				foreach ( \IPS\Db::i()->select( 'firearm_type, reason, citation', 'gd_compliance_flags', [ 'upc=? AND state_code=?', $upc, $state ] ) as $f )
				{
					$hits[] = $f;
				}
			}
			catch ( \Throwable ) {}

			$badge = empty( $hits )
				? '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#dcfce7;color:#14532d">ALLOWED</span>'
				: '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b">BANNED</span>';

			$out .= '<div style="padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:8px">'
				. '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><strong style="font-family:ui-monospace,monospace">' . $h( $upc ) . '</strong> ' . $badge . ' <span style="color:#475569">' . $h( $name ) . '</span></div>';

			if ( !empty( $hits ) )
			{
				foreach ( $hits as $f )
				{
					$out .= '<div style="margin-top:6px;color:#7f1d1d;font-size:13px"><strong>' . $h( (string) $f['firearm_type'] ) . '</strong> — ' . $h( (string) $f['reason'] );
					if ( !empty( $f['citation'] ) )
					{
						$out .= ' <span style="color:#64748b">(' . $h( (string) $f['citation'] ) . ')</span>';
					}
					$out .= '</div>';
				}
			}
			else
			{
				$out .= '<div style="margin-top:4px;color:#14532d;font-size:13px">No AWB / capacity / roster / override flag recorded for this UPC in ' . $h( $state ) . '.</div>';
			}
			$out .= '</div>';
		}
		$out .= '</div>';
		return $out;
	}

	protected function toggle(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id > 0 )
		{
			try
			{
				$cur = (int) \IPS\Db::i()->select( 'enabled', 'gd_compliance_awb_rules', [ 'id=?', $id ] )->first();
				\IPS\Db::i()->update( 'gd_compliance_awb_rules', [ 'enabled' => $cur === 1 ? 0 : 1, 'updated_at' => time() ], [ 'id=?', $id ] );
				try { \IPS\gdcompliance\AwbModels::clearCache(); } catch ( \Throwable ) {}
			}
			catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=awbstates' ) );
	}

	protected static function cleanDate( string $v ): ?string
	{
		$v = trim( $v );
		if ( $v === '' ) { return null; }
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
	}
}

class awbstates extends _awbstates {}
