<?php
/**
 * @brief  GD Compliance — Knife state-advisory dashboard (v1.6.47)
 *
 * Reads/writes gd_compliance_advisory_rules WHERE firearm_class='knife'
 * — one row per state. Displays currently-enabled knife advisories with
 * their per-state customer-visible reason text, editable inline.
 *
 * Mirrors advisories.php verbatim in structure (same summary block,
 * same edit-cards grid, same clickable state-filter with the
 * flagged-products table + per-row Set-override link). The ONLY
 * differences are the WHERE clause (firearm_class = 'knife'), the
 * lang keys, and the controller name in URLs.
 *
 * Advisories are NOT restrictions. Every row here means: item ships;
 * buyer needs a permit/card/training step at the FFL.
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _knives extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const STATE_NAMES = [
		'WA' => 'Washington',
		'CA' => 'California',
		'MN' => 'Minnesota',
		'NM' => 'New Mexico',
		'HI' => 'Hawaii',
		'NJ' => 'New Jersey',
		'NY' => 'New York',
		'DC' => 'District of Columbia',
		'MA' => 'Massachusetts',
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

		$saved = (int) ( \IPS\Request::i()->saved ?? 0 ) === 1;

		/* Live counts across gd_compliance_flags for advisory rows. */
		$perState = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'state_code, COUNT(*) AS c',
				'gd_compliance_flags',
				[ 'firearm_type=?', 'advisory' ],
				null,
				null,
				'state_code'
			) as $row )
			{
				$perState[ (string) $row['state_code'] ] = (int) $row['c'];
			}
		}
		catch ( \Throwable ) {}
		$totalAdvisories = array_sum( $perState );

		$distinctAdvisoryUpcs = 0;
		try
		{
			$distinctAdvisoryUpcs = (int) \IPS\Db::i()->select( 'COUNT(DISTINCT upc)', 'gd_compliance_flags',
				[ 'firearm_type=?', 'advisory' ] )->first();
		}
		catch ( \Throwable ) {}

		$rules = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_advisory_rules', [ 'firearm_class=?', 'knife' ],
				'state_code ASC' ) as $row )
			{
				$rules[] = $row;
			}
		}
		catch ( \Throwable ) {}

		$savedBanner = '';
		if ( $saved )
		{
			$savedBanner = '<div class="ipsBox" style="margin-bottom:14px;border-left:4px solid #059669"><div class="ipsBox_body ipsPad" style="background:#ecfdf5"><strong style="color:#065f46">Saved.</strong> Recompute to re-emit advisory flags with the updated text.</div></div>';
		}

		$summary = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 6px">' . $h( $lang->addToStack( 'gdcompliance_acp_knife_title' ) ) . '</h2>'
			. '<p style="margin:0 0 10px;color:#475569">' . $h( $lang->addToStack( 'gdcompliance_acp_knife_intro' ) ) . '</p>'
			. '<div style="display:flex;gap:24px;flex-wrap:wrap;font-size:13px">'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $distinctAdvisoryUpcs ) . '</strong><br><span style="color:#64748b">distinct products with advisories</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $totalAdvisories ) . '</strong><br><span style="color:#64748b">total advisory rows</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( count( $perState ) ) . '</strong><br><span style="color:#64748b">active states</span></div>'
			. '</div>'
			. '</div></div>';

		/* Per-rule edit cards. */
		$editCards = '';
		if ( empty( $rules ) )
		{
			$editCards = '<div class="ipsBox"><div class="ipsBox_body ipsPad" style="text-align:center;color:#94a3b8">No advisory rules seeded yet. Run upg_10617 to populate CO + MN.</div></div>';
		}
		else
		{
			$csrf = (string) \IPS\Session::i()->csrfKey;
			foreach ( $rules as $r )
			{
				$id    = (int)    ( $r['id'] ?? 0 );
				$state = strtoupper( (string) ( $r['state_code'] ?? '' ) );
				$cls   = strtolower( (string) ( $r['firearm_class'] ?? 'rifle' ) );
				$ena   = (int) ( $r['enabled'] ?? 0 ) === 1;
				$reason  = (string) ( $r['reason']   ?? '' );
				$cite    = (string) ( $r['citation'] ?? '' );
				$effDate = (string) ( $r['effective_date'] ?? '' );

				$flagCount = (int) ( $perState[ $state ] ?? 0 );
				$stateName = self::STATE_NAMES[ $state ] ?? $state;

				$saveUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=knives&do=save' );

				$editCards .= '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
					. '<div style="display:flex;justify-content:space-between;align-items:baseline;margin:0 0 8px">'
					. '<h3 style="margin:0;font-size:15px;color:#0f172a"><span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#fef3c7;color:#78350f;margin-right:8px">' . $h( $state ) . '</span> ' . $h( $stateName ) . ' <span style="color:#64748b;font-size:12px;font-weight:400">&middot; ' . $h( $cls ) . '</span></h3>'
					. '<span style="color:#64748b;font-size:12px">' . number_format( $flagCount ) . ' advisory rows on flags</span>'
					. '</div>'
					. '<form method="post" action="' . $h( $saveUrl ) . '">'
					. '<input type="hidden" name="csrfKey" value="' . $h( $csrf ) . '">'
					. '<input type="hidden" name="id" value="' . $id . '">'
					. '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;margin-bottom:10px">'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Enabled</span><select name="enabled">'
					. '<option value="1"' . ( $ena ? ' selected' : '' ) . '>Enabled</option>'
					. '<option value="0"' . ( !$ena ? ' selected' : '' ) . '>Disabled</option>'
					. '</select></label>'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Effective date (YYYY-MM-DD; blank = active now)</span><input type="text" name="effective_date" placeholder="YYYY-MM-DD" maxlength="10" value="' . $h( $effDate ) . '"></label>'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;grid-column:span 2"><span>Citation</span><input type="text" name="citation" maxlength="255" value="' . $h( $cite ) . '"></label>'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;grid-column:span 2"><span>Customer-visible advisory text</span><textarea name="reason" rows="5">' . $h( $reason ) . '</textarea><span style="color:#64748b;font-size:11px">Shown in the yellow advisory block on the product page. Wording should make it clear the item CAN ship — the buyer completes a permit/card step at the FFL.</span></label>'
					. '</div>'
					. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Save ' . $h( $state ) . '</button>'
					. '</form>'
					. '</div></div>';
			}
		}

		/* ============================================================
		 * v1.6.18 — Flagged-products workflow
		 * ============================================================
		 * Mirrors the Magazines / Lowers page: clickable STATE filter,
		 * native join over gd_compliance_flags × gd_catalog, per-row
		 * "Set override" link, prev/next pager preserving the state
		 * filter across pages. Lives BELOW the summary + reason-edit
		 * cards; those are untouched.
		 */
		$stateFilter = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( strlen( $stateFilter ) !== 2 ) { $stateFilter = ''; }

		/* State list = distinct state_code from gd_compliance_advisory_rules.
		   Falls back to array_keys(self::STATE_NAMES) if the table is
		   empty on a partial upgrade. */
		$advStates = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'DISTINCT state_code', 'gd_compliance_advisory_rules',
				[ 'firearm_class=?', 'knife' ], 'state_code ASC' ) as $sc )
			{
				$s = strtoupper( (string) ( is_array( $sc ) ? ( $sc['state_code'] ?? '' ) : $sc ) );
				if ( strlen( $s ) === 2 ) { $advStates[] = $s; }
			}
		}
		catch ( \Throwable ) {}
		if ( empty( $advStates ) ) { $advStates = array_keys( self::STATE_NAMES ); }
		$advStates = array_values( array_unique( $advStates ) );
		sort( $advStates );

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=knives' );

		/* --- Section A — clickable state filter buttons --- */
		$stateTabs  = '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center"><span style="font-size:12px;color:#64748b;font-weight:600;align-self:center;margin-right:4px">STATE:</span>';
		$allActive = $stateFilter === '' ? ' ipsButton--primary' : ' ipsButton--soft';
		$stateTabs .= '<a class="ipsButton ipsButton--verySmall' . $allActive . '" href="' . $h( (string) $baseUrl ) . '">All (' . number_format( $totalAdvisories ) . ')</a>';
		foreach ( $advStates as $sc )
		{
			$active = $stateFilter === $sc ? ' ipsButton--primary' : ' ipsButton--soft';
			$href   = (string) $baseUrl->setQueryString( 'state', $sc );
			$cnt    = (int) ( $perState[ $sc ] ?? 0 );
			$stateTabs .= '<a class="ipsButton ipsButton--verySmall' . $active . '" href="' . $h( $href ) . '">' . $h( $sc ) . ' (' . number_format( $cnt ) . ')</a>';
		}
		$stateTabs .= '</div>';

		/* --- Sections B+C+D — flagged-products list, per-row override, pager --- */
		$page = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$per  = 50;
		$off  = ( $page - 1 ) * $per;

		$flagWhere = [ "f.firearm_type=?" ];
		$flagArgs  = [ 'advisory' ];
		if ( $stateFilter !== '' )
		{
			$flagWhere[] = 'f.state_code=?';
			$flagArgs[]  = $stateFilter;
		}
		$flagWhereSql = implode( ' AND ', $flagWhere );

		/* Count over the SAME aliased-table + where — mirrors the fix
		   from v1.6.16 that made Lowers pagination work (bare table
		   with f.-alias in where = silent MySQL error). */
		$flagCount = 0;
		try
		{
			$flagCount = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				[ 'gd_compliance_flags', 'f' ],
				array_merge( [ $flagWhereSql ], $flagArgs )
			)->first();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'advisories flagCount: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$flaggedRowsHtml = '';
		try
		{
			$sel = \IPS\Db::i()->select(
				'f.upc, f.state_code, f.reason, f.citation, c.title, c.brand, c.caliber',
				[ 'gd_compliance_flags', 'f' ],
				array_merge( [ $flagWhereSql ], $flagArgs ),
				'f.state_code ASC, c.brand ASC, c.title ASC',
				[ $off, $per ]
			)->join( [ 'gd_catalog', 'c' ], 'c.upc = f.upc', 'LEFT' );

			foreach ( $sel as $r )
			{
				$upc   = (string) ( $r['upc'] ?? '' );
				$st    = (string) ( $r['state_code'] ?? '' );
				$brand = (string) ( $r['brand'] ?? '' );
				$title = (string) ( $r['title'] ?? '' );
				$cal   = (string) ( $r['caliber'] ?? '' );
				$rsn   = (string) ( $r['reason'] ?? '' );

				$overrideUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=overrides&do=form' )
					->setQueryString( [ 'upc' => $upc, 'state' => $st ] );

				$flaggedRowsHtml .= '<tr>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9"><span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#fef3c7;color:#78350f">' . $h( $st ) . '</span></td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px">' . $h( $upc ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9"><strong>' . $h( $brand !== '' ? $brand : '—' ) . '</strong></td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-size:13px">' . $h( strlen( $title ) > 80 ? substr( $title, 0, 77 ) . '…' : $title ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:12px">' . $h( $cal !== '' ? $cal : '—' ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:12px">' . $h( strlen( $rsn ) > 120 ? substr( $rsn, 0, 117 ) . '…' : $rsn ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right"><a href="' . $h( $overrideUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--verySmall">' . $h( $lang->addToStack( 'gdcompliance_acp_knife_override' ) ) . '</a></td>'
					. '</tr>';
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'advisories flagged list: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		if ( $flaggedRowsHtml === '' )
		{
			$flaggedRowsHtml = '<tr><td colspan="7" style="padding:20px;text-align:center;color:#94a3b8">No advisory flags'
				. ( $stateFilter !== '' ? ' for ' . $h( $stateFilter ) : '' )
				. '. Run the compute pass to populate.</td></tr>';
		}

		$flaggedHeader = $stateFilter !== ''
			? ' in ' . $h( $stateFilter )
			: '';

		$flaggedTable = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 6px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_knife_flagged_title' ) )
			. ' <span style="color:#64748b;font-weight:400">(' . number_format( $flagCount ) . $flaggedHeader . ')</span></h3>'
			. '<p style="margin:0 0 10px;color:#64748b;font-size:12px">' . $h( $lang->addToStack( 'gdcompliance_acp_knife_flagged_intro' ) ) . '</p>'
			. $stateTabs
			. '<table style="width:100%;border-collapse:collapse">'
			. '<thead><tr style="background:#f8fafc">'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">State</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">UPC</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Brand</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Title</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Caliber</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Reason</th>'
			. '<th style="padding:8px 10px;border-bottom:2px solid #e2e8f0"></th>'
			. '</tr></thead>'
			. '<tbody>' . $flaggedRowsHtml . '</tbody>'
			. '</table>';

		if ( $flagCount > $per )
		{
			$totalPages = (int) ceil( $flagCount / $per );
			$prevHref   = (string) $baseUrl->setQueryString( array_filter( [
				'state' => $stateFilter !== '' ? $stateFilter : null,
				'page'  => $page > 1 ? $page - 1 : null,
			] ) );
			$nextHref   = (string) $baseUrl->setQueryString( array_filter( [
				'state' => $stateFilter !== '' ? $stateFilter : null,
				'page'  => $page < $totalPages ? $page + 1 : null,
			] ) );
			$flaggedTable .= '<div style="display:flex;gap:8px;justify-content:center;margin-top:12px;font-size:13px;color:#64748b">'
				. ( $page > 1 ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $prevHref ) . '">&larr; Prev</a>' : '' )
				. '<span style="padding:4px 8px">Page ' . $page . ' / ' . $totalPages . '</span>'
				. ( $page < $totalPages ? '<a class="ipsButton ipsButton--soft ipsButton--verySmall" href="' . $h( $nextHref ) . '">Next &rarr;</a>' : '' )
				. '</div>';
		}

		$flaggedTable .= '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_knife_title' );
		\IPS\Output::i()->output = $savedBanner . $summary . $editCards . $flaggedTable;
	}

	protected function save(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id <= 0 )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=knives' ) );
			return;
		}
		try
		{
			$effRaw = trim( (string) ( \IPS\Request::i()->effective_date ?? '' ) );
			$eff    = null;
			if ( $effRaw !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $effRaw ) )
			{
				$eff = $effRaw;
			}
			\IPS\Db::i()->update( 'gd_compliance_advisory_rules', [
				'enabled'        => (int) ( \IPS\Request::i()->enabled ?? 0 ) === 1 ? 1 : 0,
				'reason'         => trim( (string) \IPS\Request::i()->reason ),
				'citation'       => substr( (string) \IPS\Request::i()->citation, 0, 255 ) ?: null,
				'effective_date' => $eff,
				'updated_at'     => time(),
			], [ 'id=?', $id ] );
			try { \IPS\gdcompliance\Advisories::clearCache(); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'advisories save: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=knives' )->setQueryString( 'saved', 1 )
		);
	}
}

class knives extends _knives {}
