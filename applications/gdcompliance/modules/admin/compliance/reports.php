<?php
/**
 * @brief  GD Compliance — ACP: Compliance Reports (Stage 2 triage)
 *
 * Triage page for member-submitted reports from the public /state-lookup/
 * page. Reports are read-only until a staff member Resolves or Dismisses;
 * "Resolve + create override" also drops a row in gd_compliance_overrides
 * so the classification flips on next recompute.
 *
 * On resolve / dismiss:
 *   1. Update gd_compliance_reports row (status, resolved_by, resolved_at,
 *      resolution_note).
 *   2. If the resolution creates an override, insert into
 *      gd_compliance_overrides (upc, state_code, action, reason).
 *   3. IPS notification (bell + email per member prefs) fires to the
 *      reporting member — extension Report.php parses it.
 *
 * All state-changing actions are ACP-perm-gated + CSRF-checked. The
 * form-render GETs (Resolve form) do NOT carry ->csrf() in the URL —
 * IN_DEV forbids it (rule #62/#81); csrfKey goes in the POST form body.
 */

namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _reports extends \IPS\Dispatcher\Controller
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
		$esc  = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		/* Status filter tab */
		$status = (string) ( \IPS\Request::i()->status ?? 'pending' );
		if ( !in_array( $status, [ 'pending', 'resolved', 'dismissed', 'all' ], true ) )
		{
			$status = 'pending';
		}

		/* Counts (per status) — one COUNT()/GROUP BY roundtrip */
		$counts = [ 'pending' => 0, 'resolved' => 0, 'dismissed' => 0, 'all' => 0 ];
		try
		{
			foreach ( \IPS\Db::i()->select( 'status, COUNT(*) AS n', 'gd_compliance_reports', null, null, null, 'status' ) as $r )
			{
				$s = (string) ( $r['status'] ?? '' );
				$n = (int)    ( $r['n']      ?? 0 );
				if ( isset( $counts[ $s ] ) ) { $counts[ $s ] = $n; }
				$counts['all'] += $n;
			}
		}
		catch ( \Throwable ) {}

		/* Tabs */
		$tabHtml = '<div class="ipsTabBar" style="margin-bottom:16px"><ul>';
		foreach ( [ 'pending', 'resolved', 'dismissed', 'all' ] as $t )
		{
			$url = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=reports&status=' . $t );
			$active = ( $t === $status ) ? ' ipsTabBar_active' : '';
			$labelKey = 'gdcompliance_acp_reports_tab_' . $t;
			$label = $lang->addToStack( $labelKey );
			$tabHtml .= '<li class="ipsTabBar_item' . $active . '"><a href="' . $esc( $url ) . '">'
				. $esc( (string) $label )
				. ' <span class="ipsBadge ipsBadge_neutral" style="margin-left:6px">' . number_format( (int) $counts[ $t ] ) . '</span>'
				. '</a></li>';
		}
		$tabHtml .= '</ul></div>';

		$intro = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $h( 'gdcompliance_acp_reports_title' ) . '</h2>'
			. '<p style="margin:0">' . $h( 'gdcompliance_acp_reports_intro' ) . '</p>'
			. '</div></div>';

		/* Table via Table\Db — filter by status */
		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=reports&status=' . $status );
		$table   = new \IPS\Helpers\Table\Db( 'gd_compliance_reports', $baseUrl );
		if ( $status !== 'all' )
		{
			$table->where = [ 'status=?', $status ];
		}
		$table->langPrefix    = 'gdcompliance_acp_reports_col_';
		$table->include       = [ 'member_id', 'upc', 'state_code', 'reported_classification', 'note', 'status', 'created_at' ];
		$table->sortBy        = $table->sortBy ?: 'created_at';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->parsers = [
			'member_id' => function( $v ) {
				if ( !$v ) { return '<span style="color:#cbd5e1">Guest</span>'; }
				try
				{
					$m   = \IPS\Member::load( (int) $v );
					$url = (string) $m->url();
					$name = htmlspecialchars( (string) $m->name, ENT_QUOTES, 'UTF-8' );
					return '<a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '">' . $name . '</a>';
				}
				catch ( \Throwable ) { return '#' . (int) $v; }
			},
			'upc' => function( $v ) {
				return '<span style="font-family:ui-monospace,monospace;font-size:12px">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'state_code' => function( $v ) {
				return '<strong>' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</strong>';
			},
			'reported_classification' => function( $v ) {
				$s = (string) $v;
				$style = match( $s ) {
					'restricted'      => 'background:#fee2e2;color:#991b1b',
					'no_restrictions' => 'background:#dcfce7;color:#14532d',
					'advisory'        => 'background:#fef3c7;color:#78350f',
					default           => 'background:#e5e7eb;color:#374151',
				};
				return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;' . $style . '">'
					. htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'note' => function( $v ) {
				if ( !$v ) { return '<span style="color:#cbd5e1">—</span>'; }
				$s = (string) $v;
				$trunc = mb_strlen( $s ) > 140 ? ( mb_substr( $s, 0, 140 ) . '…' ) : $s;
				return '<span style="font-size:13px;color:#334155" title="' . htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ) . '">'
					. htmlspecialchars( $trunc, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'status' => function( $v ) {
				$s = (string) $v;
				$style = match( $s ) {
					'pending'   => 'background:#dbeafe;color:#1e3a8a',
					'resolved'  => 'background:#dcfce7;color:#14532d',
					'dismissed' => 'background:#f1f5f9;color:#475569',
					default     => 'background:#e5e7eb;color:#374151',
				};
				return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;' . $style . '">'
					. htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'created_at' => function( $v ) {
				return $v ? htmlspecialchars( date( 'Y-m-d H:i', (int) $v ), ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">—</span>';
			},
		];

		$table->rowButtons = function( $row ) {
			if ( ( $row['status'] ?? 'pending' ) !== 'pending' )
			{
				return [
					'view' => [
						'icon'  => 'eye',
						'title' => 'gdcompliance_acp_reports_action_view',
						'link'  => \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=reports&do=viewRow&id=' . (int) $row['id'] ),
					],
				];
			}
			$base = 'app=gdcompliance&module=compliance&controller=reports';
			return [
				'resolve' => [
					'icon'  => 'check',
					'title' => 'gdcompliance_acp_reports_action_resolve',
					'link'  => \IPS\Http\Url::internal( $base . '&do=resolveForm&id=' . (int) $row['id'] ),
				],
				'dismiss' => [
					'icon'  => 'times-circle',
					'title' => 'gdcompliance_acp_reports_action_dismiss',
					'link'  => \IPS\Http\Url::internal( $base . '&do=dismissForm&id=' . (int) $row['id'] ),
				],
			];
		};

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_reports_title' );
		\IPS\Output::i()->output = $intro . $tabHtml . (string) $table;
	}

	/**
	 * Fetch a report row by id — helper for form / act.
	 */
	protected function loadReport( int $id ): ?array
	{
		if ( $id <= 0 ) { return null; }
		try
		{
			$r = \IPS\Db::i()->select( '*', 'gd_compliance_reports', [ 'id=?', $id ] )->first();
			return is_array( $r ) ? $r : null;
		}
		catch ( \Throwable ) { return null; }
	}

	/**
	 * GET: render the Resolve form (with optional override create).
	 */
	protected function resolveForm(): void
	{
		$this->renderResolveOrDismissForm( 'resolve' );
	}

	/**
	 * GET: render the Dismiss form.
	 */
	protected function dismissForm(): void
	{
		$this->renderResolveOrDismissForm( 'dismiss' );
	}

	protected function renderResolveOrDismissForm( string $kind ): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $k ) => htmlspecialchars( (string) $lang->addToStack( $k ), ENT_QUOTES, 'UTF-8' );
		$esc  = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		$report = $this->loadReport( $id );
		if ( !$report )
		{
			\IPS\Output::i()->error( 'Report not found', '2GDR/1', 404 );
			return;
		}

		if ( (string) ( $report['status'] ?? 'pending' ) !== 'pending' )
		{
			$backUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=reports' );
			\IPS\Output::i()->redirect( $backUrl );
			return;
		}

		$upc          = (string) ( $report['upc']        ?? '' );
		$stateCode    = (string) ( $report['state_code'] ?? '' );
		$reportedCls  = (string) ( $report['reported_classification'] ?? '' );
		$note         = (string) ( $report['note']       ?? '' );

		/* Product context for the admin — best-effort. */
		$productLine = '';
		try
		{
			$p = \IPS\Db::i()->select( 'title, brand', 'gd_catalog', [ 'upc=?', $upc ] )->first();
			if ( is_array( $p ) )
			{
				$productLine = trim( ( (string) ( $p['brand'] ?? '' ) !== '' ? $p['brand'] . ' — ' : '' ) . (string) ( $p['title'] ?? '' ) );
			}
		}
		catch ( \Throwable ) {}

		$actionUrl = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=compliance&controller=reports&do=' . ( $kind === 'resolve' ? 'resolveAct' : 'dismissAct' )
		);
		$csrfKey = (string) \IPS\Session::i()->csrfKey;

		$title = $kind === 'resolve'
			? $lang->addToStack( 'gdcompliance_acp_reports_resolve_title' )
			: $lang->addToStack( 'gdcompliance_acp_reports_dismiss_title' );

		$overrideBlock = '';
		if ( $kind === 'resolve' )
		{
			$overrideBlock = ''
				. '<fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:14px">'
				. '<legend style="padding:0 6px;font-weight:600;color:#334155">' . $h( 'gdcompliance_acp_reports_resolve_override' ) . '</legend>'
				. '<label style="display:block;margin-bottom:6px">'
				. '<input type="radio" name="override" value="none" checked> '
				. $h( 'gdcompliance_acp_reports_resolve_override_none' )
				. '</label>'
				. '<label style="display:block;margin-bottom:6px">'
				. '<input type="radio" name="override" value="force_clear"> '
				. $h( 'gdcompliance_acp_reports_resolve_override_force_clear' )
				. '</label>'
				. '<label style="display:block">'
				. '<input type="radio" name="override" value="force_restrict"> '
				. $h( 'gdcompliance_acp_reports_resolve_override_force_restrict' )
				. '</label>'
				. '</fieldset>';
		}

		$html = ''
			. '<div class="ipsBox" style="max-width:720px;margin:12px auto"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 12px">' . $esc( (string) $title ) . '</h2>'
			. '<dl style="margin:0 0 16px;font-size:14px;color:#334155">'
			. '<dt style="display:inline-block;width:110px;font-weight:600">UPC</dt><dd style="display:inline">' . $esc( $upc ) . '</dd><br>'
			. '<dt style="display:inline-block;width:110px;font-weight:600">State</dt><dd style="display:inline">' . $esc( $stateCode ) . '</dd><br>'
			. '<dt style="display:inline-block;width:110px;font-weight:600">Reported</dt><dd style="display:inline">' . $esc( $reportedCls ) . '</dd><br>'
			. ( $productLine !== '' ? '<dt style="display:inline-block;width:110px;font-weight:600">Product</dt><dd style="display:inline">' . $esc( $productLine ) . '</dd><br>' : '' )
			. '<dt style="display:inline-block;width:110px;vertical-align:top;font-weight:600">Note</dt><dd style="display:inline-block;max-width:520px;color:#475569">' . nl2br( $esc( $note !== '' ? $note : '—' ) ) . '</dd>'
			. '</dl>'
			. '<form method="post" action="' . $esc( $actionUrl ) . '">'
			. '<input type="hidden" name="csrfKey" value="' . $esc( $csrfKey ) . '">'
			. '<input type="hidden" name="id" value="' . (int) $id . '">'
			. $overrideBlock
			. '<div style="margin-bottom:12px">'
			. '<label for="gdcr-note" style="display:block;font-weight:600;margin-bottom:4px">' . $h( 'gdcompliance_acp_reports_resolution_note' ) . '</label>'
			. '<textarea id="gdcr-note" name="resolution_note" rows="4" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px" maxlength="2000"></textarea>'
			. '<p style="margin:4px 0 0;font-size:12px;color:#64748b">' . $h( 'gdcompliance_acp_reports_resolution_note_hint' ) . '</p>'
			. '</div>'
			. '<button type="submit" class="ipsButton ipsButton--primary">'
			. ( $kind === 'resolve' ? $h( 'gdcompliance_acp_reports_action_resolve' ) : $h( 'gdcompliance_acp_reports_action_dismiss' ) )
			. '</button> '
			. '<a href="' . $esc( (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=reports' ) ) . '" class="ipsButton ipsButton--link">' . $h( 'gdcompliance_acp_reports_cancel' ) . '</a>'
			. '</form>'
			. '</div></div>';

		\IPS\Output::i()->title  = $title;
		\IPS\Output::i()->output = $html;
	}

	/**
	 * POST: apply the resolve. Optionally creates an override.
	 */
	protected function resolveAct(): void
	{
		\IPS\Session::i()->csrfCheck();
		$this->applyResolution( 'resolved' );
	}

	/**
	 * POST: apply the dismiss.
	 */
	protected function dismissAct(): void
	{
		\IPS\Session::i()->csrfCheck();
		$this->applyResolution( 'dismissed' );
	}

	protected function applyResolution( string $newStatus ): void
	{
		if ( !in_array( $newStatus, [ 'resolved', 'dismissed' ], true ) )
		{
			\IPS\Output::i()->error( 'Bad status', '2GDR/2', 400 );
			return;
		}

		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		$report = $this->loadReport( $id );
		if ( !$report )
		{
			\IPS\Output::i()->error( 'Report not found', '2GDR/3', 404 );
			return;
		}
		if ( (string) ( $report['status'] ?? 'pending' ) !== 'pending' )
		{
			\IPS\Output::i()->redirect(
				(string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=reports' )
			);
			return;
		}

		$resolutionNote = trim( substr( (string) ( \IPS\Request::i()->resolution_note ?? '' ), 0, 2000 ) );
		$overrideAction = (string) ( \IPS\Request::i()->override ?? 'none' );
		if ( !in_array( $overrideAction, [ 'none', 'force_clear', 'force_restrict' ], true ) )
		{
			$overrideAction = 'none';
		}
		if ( $newStatus === 'dismissed' )
		{
			$overrideAction = 'none';
		}

		$upc       = (string) ( $report['upc']        ?? '' );
		$stateCode = (string) ( $report['state_code'] ?? '' );
		$memberId  = (int)    ( $report['member_id']  ?? 0 );

		/* 1) Update report row. */
		try
		{
			\IPS\Db::i()->update( 'gd_compliance_reports', [
				'status'          => $newStatus,
				'resolved_by'     => (int) \IPS\Member::loggedIn()->member_id,
				'resolved_at'     => time(),
				'resolution_note' => $resolutionNote !== '' ? $resolutionNote : null,
			], [ 'id=?', $id ] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'reports applyResolution update: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		/* 2) Override — only when resolving with a non-none action. */
		$overrideCreated = false;
		if ( $newStatus === 'resolved' && in_array( $overrideAction, [ 'force_clear', 'force_restrict' ], true ) && $upc !== '' && $stateCode !== '' )
		{
			try
			{
				$reason = trim( 'From report #' . (int) $id . ( $resolutionNote !== '' ? ': ' . $resolutionNote : '' ) );
				\IPS\Db::i()->replace( 'gd_compliance_overrides', [
					'upc'        => $upc,
					'state_code' => strtoupper( $stateCode ),
					'action'     => $overrideAction,
					'reason'     => substr( $reason, 0, 255 ),
					'created_by' => (int) \IPS\Member::loggedIn()->member_id,
					'created_at' => time(),
				] );
				$overrideCreated = true;
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'reports applyResolution override: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			}
		}

		/* 3) Notify the reporting member (independent try/catch — rule #25). */
		try
		{
			if ( $memberId > 0 )
			{
				$member = \IPS\Member::load( $memberId );
				if ( $member && $member->member_id )
				{
					$key = $newStatus === 'resolved' ? 'gdcompliance_report_resolved' : 'gdcompliance_report_dismissed';
					$notification = new \IPS\Notification(
						\IPS\Application::load( 'gdcompliance' ),
						$key,
						$member,
						[ $member ],
						[
							'upc'             => $upc,
							'state_code'      => strtoupper( $stateCode ),
							'resolution_note' => $resolutionNote,
							'has_override'    => $overrideCreated,
							'outcome_label'   => ucfirst( $newStatus ),
						]
					);
					$notification->recipients->attach( $member );
					$notification->send();
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'reports applyResolution notify: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect(
			(string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=reports&status=' . $newStatus )
		);
	}

	/**
	 * Read-only detail view of an already-resolved / dismissed report.
	 */
	protected function viewRow(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$esc  = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$id = (int) ( \IPS\Request::i()->id ?? 0 );
		$report = $this->loadReport( $id );
		if ( !$report )
		{
			\IPS\Output::i()->error( 'Report not found', '2GDR/4', 404 );
			return;
		}

		$resolverName = '';
		if ( !empty( $report['resolved_by'] ) )
		{
			try { $resolverName = (string) \IPS\Member::load( (int) $report['resolved_by'] )->name; }
			catch ( \Throwable ) {}
		}
		$reporterName = '';
		if ( !empty( $report['member_id'] ) )
		{
			try { $reporterName = (string) \IPS\Member::load( (int) $report['member_id'] )->name; }
			catch ( \Throwable ) {}
		}

		$row = fn( string $label, string $val ) =>
			'<div style="margin-bottom:8px"><strong style="display:inline-block;min-width:140px;color:#334155">' . $esc( $label ) . '</strong><span>' . $esc( $val ) . '</span></div>';

		$html = '<div class="ipsBox" style="max-width:720px;margin:12px auto"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 12px">Report #' . (int) $id . '</h2>'
			. $row( 'Reporter',    $reporterName !== '' ? $reporterName : '#' . (int) ( $report['member_id'] ?? 0 ) )
			. $row( 'UPC',         (string) ( $report['upc'] ?? '' ) )
			. $row( 'State',       (string) ( $report['state_code'] ?? '' ) )
			. $row( 'Reported',    (string) ( $report['reported_classification'] ?? '' ) )
			. $row( 'Status',      (string) ( $report['status'] ?? '' ) )
			. $row( 'Created',     !empty( $report['created_at'] ) ? date( 'Y-m-d H:i', (int) $report['created_at'] ) : '' )
			. $row( 'Resolved by', $resolverName !== '' ? $resolverName : ( !empty( $report['resolved_by'] ) ? '#' . (int) $report['resolved_by'] : '—' ) )
			. $row( 'Resolved at', !empty( $report['resolved_at'] ) ? date( 'Y-m-d H:i', (int) $report['resolved_at'] ) : '—' )
			. '<div style="margin-top:14px"><strong style="display:block;color:#334155;margin-bottom:4px">Note</strong><div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;color:#334155">' . nl2br( $esc( (string) ( $report['note'] ?? '' ) ) ) . '</div></div>'
			. '<div style="margin-top:14px"><strong style="display:block;color:#334155;margin-bottom:4px">Resolution note</strong><div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;color:#334155">' . nl2br( $esc( (string) ( $report['resolution_note'] ?? '' ) ) ) . '</div></div>'
			. '<p style="margin-top:16px"><a href="' . $esc( (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=reports' ) ) . '" class="ipsButton ipsButton--link">← Back to reports</a></p>'
			. '</div></div>';

		\IPS\Output::i()->title  = 'Report #' . (int) $id;
		\IPS\Output::i()->output = $html;
	}
}

class reports extends _reports {}
