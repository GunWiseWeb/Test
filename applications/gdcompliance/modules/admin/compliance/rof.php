<?php
/**
 * @brief  GD Compliance — Rate-of-Fire enhancer ban dashboard (v1.6.21)
 *
 * Same shape as Melting-Point + Advisories pages:
 *   1. Summary ipsBox (flagged devices, total flag rows, active
 *      states, curated overrides).
 *   2. Per-state edit cards (enabled / effective_date / citation /
 *      reason). Editable inline.
 *   3. Clickable state filter (All | 14 banned states — no MN).
 *   4. Flagged-products table with per-row "Set override" link.
 *      Native Db::select([table, alias], ...)->join([table, alias],
 *      ...). NEVER raw preparedQuery.
 *   5. Prev/Next pager preserving ?state=XX.
 *   6. Curated overrides Table\Db (gd_compliance_rof).
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _rof extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	const STATE_NAMES = [
		'CA' => 'California',
		'CT' => 'Connecticut',
		'DE' => 'Delaware',
		'DC' => 'District of Columbia',
		'HI' => 'Hawaii',
		'IL' => 'Illinois',
		'MA' => 'Massachusetts',
		'MD' => 'Maryland',
		'NJ' => 'New Jersey',
		'NV' => 'Nevada',
		'NY' => 'New York',
		'OR' => 'Oregon',
		'RI' => 'Rhode Island',
		'WA' => 'Washington',
	];

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
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/RateOfFire.php';
			\IPS\gdcompliance\RateOfFire::clearCache();
		}
		catch ( \Throwable ) {}

		$saved = (int) ( \IPS\Request::i()->saved ?? 0 ) === 1;

		$stateFilter = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( strlen( $stateFilter ) !== 2 ) { $stateFilter = ''; }

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rof' );

		/* ------- Summary counts ------- */
		$perState = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'state_code, COUNT(*) AS c', 'gd_compliance_flags',
				[ 'firearm_type=?', 'rate_of_fire' ], null, null, 'state_code' ) as $row )
			{
				$perState[ (string) $row['state_code'] ] = (int) $row['c'];
			}
		}
		catch ( \Throwable ) {}
		$totalRofFlags = array_sum( $perState );

		$distinctFlagged = 0;
		try
		{
			$distinctFlagged = (int) \IPS\Db::i()->select( 'COUNT(DISTINCT upc)', 'gd_compliance_flags',
				[ 'firearm_type=?', 'rate_of_fire' ] )->first();
		}
		catch ( \Throwable ) {}

		$curatedCount = 0;
		try
		{
			$curatedCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_rof' )->first();
		}
		catch ( \Throwable ) {}

		$rules = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_rof_rules', null, 'state_code ASC' ) as $row )
			{
				$rules[] = $row;
			}
		}
		catch ( \Throwable ) {}

		$savedBanner = '';
		if ( $saved )
		{
			$savedBanner = '<div class="ipsBox" style="margin-bottom:14px;border-left:4px solid #059669"><div class="ipsBox_body ipsPad" style="background:#ecfdf5"><strong style="color:#065f46">Saved.</strong> Recompute to re-emit rate_of_fire flags with the updated text.</div></div>';
		}

		$summary = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 6px">' . $h( $lang->addToStack( 'gdcompliance_acp_rof_title' ) ) . '</h2>'
			. '<p style="margin:0 0 10px;color:#475569">' . $h( $lang->addToStack( 'gdcompliance_acp_rof_intro' ) ) . '</p>'
			. '<div style="display:flex;gap:24px;flex-wrap:wrap;font-size:13px">'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $distinctFlagged ) . '</strong><br><span style="color:#64748b">flagged devices</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $totalRofFlags ) . '</strong><br><span style="color:#64748b">total flag rows</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( count( $perState ) ) . '</strong><br><span style="color:#64748b">active states</span></div>'
			. '<div><strong style="color:#0f172a;font-size:22px">' . number_format( $curatedCount ) . '</strong><br><span style="color:#64748b">curated overrides</span></div>'
			. '</div>'
			. '</div></div>';

		/* ------- Per-state edit cards ------- */
		$csrf = (string) \IPS\Session::i()->csrfKey;
		$editCards = '';
		if ( empty( $rules ) )
		{
			$editCards = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad" style="text-align:center;color:#94a3b8">No rate-of-fire rules seeded yet. Run upg_10621 to populate the 14 banned states.</div></div>';
		}
		else
		{
			foreach ( $rules as $r )
			{
				$id      = (int)    ( $r['id'] ?? 0 );
				$state   = strtoupper( (string) ( $r['state_code'] ?? '' ) );
				$ena     = (int) ( $r['enabled'] ?? 0 ) === 1;
				$reason  = (string) ( $r['reason']   ?? '' );
				$cite    = (string) ( $r['citation'] ?? '' );
				$effDate = (string) ( $r['effective_date'] ?? '' );

				$flagCount = (int) ( $perState[ $state ] ?? 0 );
				$stateName = self::STATE_NAMES[ $state ] ?? $state;

				$saveUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rof&do=saveRule' );

				$editCards .= '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
					. '<div style="display:flex;justify-content:space-between;align-items:baseline;margin:0 0 8px">'
					. '<h3 style="margin:0;font-size:15px;color:#0f172a"><span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b;margin-right:8px">' . $h( $state ) . '</span> ' . $h( $stateName ) . '</h3>'
					. '<span style="color:#64748b;font-size:12px">' . number_format( $flagCount ) . ' flag rows</span>'
					. '</div>'
					. '<form method="post" action="' . $h( $saveUrl ) . '">'
					. '<input type="hidden" name="csrfKey" value="' . $h( $csrf ) . '">'
					. '<input type="hidden" name="id" value="' . $id . '">'
					. '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;margin-bottom:10px">'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Enabled</span><select name="enabled">'
					. '<option value="1"' . ( $ena ? ' selected' : '' ) . '>Enabled</option>'
					. '<option value="0"' . ( !$ena ? ' selected' : '' ) . '>Disabled</option>'
					. '</select></label>'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px"><span>Effective date (blank = active now)</span><input type="text" name="effective_date" placeholder="YYYY-MM-DD" maxlength="10" value="' . $h( $effDate ) . '"></label>'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;grid-column:span 2"><span>Citation</span><input type="text" name="citation" maxlength="255" value="' . $h( $cite ) . '"></label>'
					. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;grid-column:span 2"><span>Customer-visible reason</span><textarea name="reason" rows="4">' . $h( $reason ) . '</textarea></label>'
					. '</div>'
					. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Save ' . $h( $state ) . '</button>'
					. '</form>'
					. '</div></div>';
			}
		}

		/* ------- State filter buttons ------- */
		$stateOrder = array_keys( self::STATE_NAMES );
		$stateTabs  = '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center"><span style="font-size:12px;color:#64748b;font-weight:600;align-self:center;margin-right:4px">STATE:</span>';
		$allActive  = $stateFilter === '' ? ' ipsButton--primary' : ' ipsButton--soft';
		$stateTabs .= '<a class="ipsButton ipsButton--verySmall' . $allActive . '" href="' . $h( (string) $baseUrl ) . '">All (' . number_format( $totalRofFlags ) . ')</a>';
		foreach ( $stateOrder as $sc )
		{
			$active = $stateFilter === $sc ? ' ipsButton--primary' : ' ipsButton--soft';
			$href   = (string) $baseUrl->setQueryString( 'state', $sc );
			$cnt    = (int) ( $perState[ $sc ] ?? 0 );
			$stateTabs .= '<a class="ipsButton ipsButton--verySmall' . $active . '" href="' . $h( $href ) . '">' . $h( $sc ) . ' (' . number_format( $cnt ) . ')</a>';
		}
		$stateTabs .= '</div>';

		/* ------- Flagged-products table (paginated) ------- */
		$page = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$per  = 50;
		$off  = ( $page - 1 ) * $per;

		$flagWhere = [ "f.firearm_type=?" ];
		$flagArgs  = [ 'rate_of_fire' ];
		if ( $stateFilter !== '' )
		{
			$flagWhere[] = 'f.state_code=?';
			$flagArgs[]  = $stateFilter;
		}
		$flagWhereSql = implode( ' AND ', $flagWhere );

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
			try { \IPS\Log::log( 'rof flagCount: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$rowsHtml = '';
		try
		{
			$sel = \IPS\Db::i()->select(
				'f.upc, f.state_code, f.reason, c.brand, c.title, c.caliber',
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

				$overrideUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=overrides&do=form' )
					->setQueryString( [ 'upc' => $upc, 'state' => $st ] );

				$rowsHtml .= '<tr>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9"><span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b">' . $h( $st ) . '</span></td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px">' . $h( $upc ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9"><strong>' . $h( $brand !== '' ? $brand : '—' ) . '</strong></td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-size:13px">' . $h( strlen( $title ) > 80 ? substr( $title, 0, 77 ) . '…' : $title ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:12px">' . $h( $cal !== '' ? $cal : '—' ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right"><a href="' . $h( $overrideUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--verySmall">' . $h( $lang->addToStack( 'gdcompliance_acp_rof_override' ) ) . '</a></td>'
					. '</tr>';
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'rof flagged list: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		if ( $rowsHtml === '' )
		{
			$rowsHtml = '<tr><td colspan="6" style="padding:20px;text-align:center;color:#94a3b8">No rate-of-fire flags'
				. ( $stateFilter !== '' ? ' for ' . $h( $stateFilter ) : '' )
				. '. Run the compute pass to populate.</td></tr>';
		}

		$flaggedTable = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 6px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_rof_flagged_title' ) )
			. ' <span style="color:#64748b;font-weight:400">(' . number_format( $flagCount )
			. ( $stateFilter !== '' ? ' in ' . $h( $stateFilter ) : '' ) . ')</span></h3>'
			. '<p style="margin:0 0 10px;color:#64748b;font-size:12px">' . $h( $lang->addToStack( 'gdcompliance_acp_rof_flagged_intro' ) ) . '</p>'
			. $stateTabs
			. '<table style="width:100%;border-collapse:collapse">'
			. '<thead><tr style="background:#f8fafc">'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">State</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">UPC</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Brand</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Title</th>'
			. '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#64748b">Caliber</th>'
			. '<th style="padding:8px 10px;border-bottom:2px solid #e2e8f0"></th>'
			. '</tr></thead>'
			. '<tbody>' . $rowsHtml . '</tbody>'
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

		/* ------- Curated overrides Table\Db ------- */
		$curatedTable = new \IPS\Helpers\Table\Db( 'gd_compliance_rof', $baseUrl );
		$curatedTable->langPrefix    = 'gdcompliance_acp_rof_col_';
		$curatedTable->include       = [ 'pattern', 'action', 'note' ];
		$curatedTable->sortBy        = $curatedTable->sortBy ?: 'action';
		$curatedTable->sortDirection = $curatedTable->sortDirection ?: 'asc';

		$actionColors = [
			'force_flag'  => [ 'bg' => '#fee2e2', 'fg' => '#991b1b', 'lbl' => 'FLAG' ],
			'force_clear' => [ 'bg' => '#dcfce7', 'fg' => '#065f46', 'lbl' => 'CLEAR' ],
			'review'      => [ 'bg' => '#fef3c7', 'fg' => '#78350f', 'lbl' => 'REVIEW' ],
		];
		$curatedTable->parsers = [
			'pattern' => fn( $v ) => '<strong style="font-family:ui-monospace,monospace">' . $h( (string) $v ) . '</strong>',
			'action'  => function( $v ) use ( $h, $actionColors ) {
				$key = (string) $v;
				$c   = $actionColors[ $key ] ?? [ 'bg' => '#f1f5f9', 'fg' => '#334155', 'lbl' => strtoupper( $key ) ];
				return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;background:' . $c['bg'] . ';color:' . $c['fg'] . '">' . $h( $c['lbl'] ) . '</span>';
			},
			'note'    => fn( $v ) => $v ? '<span style="color:#475569;font-size:12px">' . $h( (string) $v ) . '</span>' : '<span style="color:#cbd5e1">—</span>',
		];
		$curatedTable->rowButtons = function( $row ) {
			$base = 'app=gdcompliance&module=compliance&controller=rof';
			return [
				'edit'   => [ 'icon' => 'pencil',       'title' => 'edit',   'link' => \IPS\Http\Url::internal( $base . '&do=form&id=' . (int) $row['id'] ) ],
				'delete' => [ 'icon' => 'times-circle', 'title' => 'delete', 'link' => \IPS\Http\Url::internal( $base . '&do=delete&id=' . (int) $row['id'] )->csrf(), 'data' => [ 'delete' => '' ] ],
			];
		};

		$addUrl       = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rof&do=form' );
		$curatedIntro = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h3 style="margin:0 0 6px;font-size:14px;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_rof_curated' ) ) . '</h3>'
			. '<p style="margin:0 0 10px;color:#475569;font-size:13px">' . $h( $lang->addToStack( 'gdcompliance_acp_rof_curated_intro' ) ) . '</p>'
			. '<a href="' . $h( $addUrl ) . '" class="ipsButton ipsButton--primary ipsButton--small">' . $h( $lang->addToStack( 'gdcompliance_acp_rof_add' ) ) . '</a>'
			. '</div></div>';

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_rof_title' );
		\IPS\Output::i()->output = $savedBanner . $summary . $editCards . $flaggedTable . $curatedIntro . (string) $curatedTable;
	}

	protected function saveRule(): void
	{
		\IPS\Session::i()->csrfCheck();
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		if ( $id <= 0 )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rof' ) );
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
			\IPS\Db::i()->update( 'gd_compliance_rof_rules', [
				'enabled'        => (int) ( \IPS\Request::i()->enabled ?? 0 ) === 1 ? 1 : 0,
				'reason'         => trim( (string) \IPS\Request::i()->reason ),
				'citation'       => substr( (string) \IPS\Request::i()->citation, 0, 255 ) ?: null,
				'effective_date' => $eff,
				'updated_at'     => time(),
			], [ 'id=?', $id ] );
			try { \IPS\gdcompliance\RateOfFire::clearCache(); } catch ( \Throwable ) {}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'rof saveRule: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rof' )->setQueryString( 'saved', 1 )
		);
	}

	protected function form(): void
	{
		$id  = (int) ( \IPS\Request::i()->id ?? 0 );
		$row = null;
		if ( $id > 0 )
		{
			try { $row = \IPS\Db::i()->select( '*', 'gd_compliance_rof', [ 'id=?', $id ] )->first(); }
			catch ( \Throwable ) { $row = null; }
		}

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_rof_f_pattern', $row['pattern'] ?? '', TRUE,  [ 'maxLength' => 191 ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdcompliance_rof_f_action',  $row['action']  ?? 'force_flag', TRUE, [ 'options' => self::ACTIONS ] ) );
		$form->add( new \IPS\Helpers\Form\Text(   'gdcompliance_rof_f_note',    $row['note']    ?? '', FALSE, [ 'maxLength' => 255 ] ) );

		if ( $values = $form->values() )
		{
			$pattern = trim( (string) $values['gdcompliance_rof_f_pattern'] );
			$action  = (string) $values['gdcompliance_rof_f_action'];
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
					'action'     => $action,
					'note'       => substr( (string) $values['gdcompliance_rof_f_note'], 0, 255 ) ?: null,
					'created_at' => time(),
				];
				try
				{
					if ( $row ) { \IPS\Db::i()->update( 'gd_compliance_rof', $data, [ 'id=?', $id ] ); }
					else        { \IPS\Db::i()->insert( 'gd_compliance_rof', $data ); }
					try { \IPS\gdcompliance\RateOfFire::clearCache(); } catch ( \Throwable ) {}
				}
				catch ( \Throwable $e )
				{
					try { \IPS\Log::log( 'rof save curated: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
				}

				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rof' ),
					'saved'
				);
				return;
			}
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcompliance_acp_rof_add' );
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
				\IPS\Db::i()->delete( 'gd_compliance_rof', [ 'id=?', $id ] );
				try { \IPS\gdcompliance\RateOfFire::clearCache(); } catch ( \Throwable ) {}
			}
			catch ( \Throwable ) {}
		}
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=rof' ), 'deleted' );
	}
}

class rof extends _rof {}
