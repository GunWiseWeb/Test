<?php
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

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'compliance_manage' );
		parent::execute();
	}

	protected function manage(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $k ) => htmlspecialchars( (string) $lang->addToStack( $k ), ENT_QUOTES, 'UTF-8' );

		$showResolved = (int) ( \IPS\Request::i()->resolved ?? 0 ) === 1;

		$pending = $resolved = 0;
		try { $pending  = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_review', [ 'resolved=0' ] )->first(); } catch ( \Throwable ) {}
		try { $resolved = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_review', [ 'resolved=1' ] )->first(); } catch ( \Throwable ) {}

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review' );
		$tabs    = '<div style="margin:0 0 14px">'
			. "<a class='ipsButton ipsButton--small" . ( $showResolved ? ' ipsButton--soft' : ' ipsButton--primary' ) . "' href='"
			. htmlspecialchars( (string) $baseUrl, ENT_QUOTES, 'UTF-8' ) . "'>" . $h( 'gdcompliance_acp_review_pending' ) . " ({$pending})</a> "
			. "<a class='ipsButton ipsButton--small" . ( $showResolved ? ' ipsButton--primary' : ' ipsButton--soft' ) . "' href='"
			. htmlspecialchars( (string) $baseUrl->setQueryString( 'resolved', 1 ), ENT_QUOTES, 'UTF-8' ) . "'>" . $h( 'gdcompliance_acp_review_resolved' ) . " ({$resolved})</a>"
			. '</div>';

		$intro = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px">' . $h( 'gdcompliance_acp_review_title' ) . '</h2>'
			. '<p style="margin:0 0 10px">' . $h( 'gdcompliance_acp_review_intro' ) . '</p>'
			. $tabs
			. '</div></div>';

		$where = $showResolved ? [ 'resolved=1' ] : [ 'resolved=0' ];
		$tableUrl = $showResolved ? $baseUrl->setQueryString( 'resolved', 1 ) : $baseUrl;
		$table    = new \IPS\Helpers\Table\Db( 'gd_compliance_review', $tableUrl, $where );
		$table->langPrefix    = 'gdcompliance_acp_review_col_';
		$table->include       = [ 'upc', 'manufacturer', 'model_title', 'caliber', 'suggested_status', 'resolved_status' ];
		$table->sortBy        = $table->sortBy ?: 'id';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->parsers = [
			'upc'              => function( $v ) { return '<span style="font-family:ui-monospace,monospace;font-size:12px">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>'; },
			'manufacturer'     => function( $v ) { return '<strong>' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</strong>'; },
			'model_title'      => function( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); },
			'caliber'          => function( $v ) { return $v ? htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) : '<span style="color:#cbd5e1">—</span>'; },
			'suggested_status' => function( $v ) {
				$lbl = strtolower( (string) $v );
				$pill = match( $lbl ) {
					'off_roster'       => 'background:#fee2e2;color:#991b1b',
					'on_roster'        => 'background:#dcfce7;color:#14532d',
					default            => 'background:#fef3c7;color:#92400e',
				};
				return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;' . $pill . '">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
			'resolved_status'  => function( $v ) {
				if ( !$v ) { return '<span style="color:#cbd5e1">—</span>'; }
				$lbl = strtolower( (string) $v );
				$pill = $lbl === 'on_roster' ? 'background:#dcfce7;color:#14532d' : 'background:#fee2e2;color:#991b1b';
				return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;' . $pill . '">' . htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) . '</span>';
			},
		];

		$table->rowButtons = function( $row ) {
			$base = 'app=gdcompliance&module=compliance&controller=review';
			$btns = [
				'view' => [
					'icon'  => 'eye',
					'title' => 'view',
					'link'  => \IPS\Http\Url::internal( $base . '&do=view&id=' . (int) $row['id'] ),
				],
			];
			if ( (int) ( $row['resolved'] ?? 0 ) === 0 )
			{
				$btns['on'] = [
					'icon'  => 'check',
					'title' => 'gdcompliance_acp_review_mark_on',
					'link'  => \IPS\Http\Url::internal( $base . '&do=resolve&id=' . (int) $row['id'] . '&status=on_roster' )->csrf(),
				];
				$btns['off'] = [
					'icon'  => 'times',
					'title' => 'gdcompliance_acp_review_mark_off',
					'link'  => \IPS\Http\Url::internal( $base . '&do=resolve&id=' . (int) $row['id'] . '&status=off_roster' )->csrf(),
				];
			}
			else
			{
				$btns['reopen'] = [
					'icon'  => 'undo',
					'title' => 'gdcompliance_acp_review_reopen',
					'link'  => \IPS\Http\Url::internal( $base . '&do=reopen&id=' . (int) $row['id'] )->csrf(),
				];
			}
			return $btns;
		};

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_review_title' );
		\IPS\Output::i()->output = $intro . (string) $table;
	}

	protected function view(): void
	{
		$id = (int) ( \IPS\Request::i()->id ?? 0 );
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

		$candidates = json_decode( (string) ( $row['candidates_json'] ?? '[]' ), true );
		if ( !is_array( $candidates ) ) { $candidates = []; }

		$ce = fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
		$candHtml = '';
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

		$onUrl  = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review&do=resolve&id=' . $id . '&status=on_roster' )->csrf();
		$offUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review&do=resolve&id=' . $id . '&status=off_roster' )->csrf();

		$out  = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">';
		$out .= '<h2 class="ipsType_sectionHead" style="margin:0 0 10px">' . $ce( $row['manufacturer'] ) . ' &mdash; ' . $ce( $row['model_title'] ) . '</h2>';
		$out .= '<p style="margin:0 0 4px"><strong>UPC:</strong> <span style="font-family:ui-monospace,monospace">' . $ce( $row['upc'] ) . '</span></p>';
		$out .= '<p style="margin:0 0 4px"><strong>Caliber:</strong> ' . $ce( $row['caliber'] ?: '—' ) . '</p>';
		$out .= '<p style="margin:0 0 14px"><strong>Suggested:</strong> ' . $ce( $row['suggested_status'] ) . '</p>';

		if ( (int) ( $row['resolved'] ?? 0 ) === 0 )
		{
			$out .= '<div style="display:flex;gap:8px">'
				. '<a href="' . $ce( $onUrl ) . '" class="ipsButton ipsButton--primary">' . $h( 'gdcompliance_acp_review_mark_on' ) . '</a>'
				. '<a href="' . $ce( $offUrl ) . '" class="ipsButton ipsButton--soft">' . $h( 'gdcompliance_acp_review_mark_off' ) . '</a>'
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
		if ( !in_array( $status, [ 'on_roster', 'off_roster' ], true ) )
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

		/* Mirror the decision into gd_compliance_flags so the CA restriction
		   either lights up or clears for that UPC immediately. */
		try
		{
			\IPS\Db::i()->delete( 'gd_compliance_flags', [ 'upc=? AND state_code=? AND firearm_type=?', (string) $row['upc'], 'CA', 'handgun' ] );
		}
		catch ( \Throwable ) {}

		if ( $status === 'off_roster' )
		{
			try
			{
				\IPS\Db::i()->insert( 'gd_compliance_flags', [
					'upc'             => (string) $row['upc'],
					'state_code'      => 'CA',
					'firearm_type'    => 'handgun',
					'parsed_capacity' => null,
					'rule_id'         => 0,
					'reason'          => 'Not on CA DOJ roster (manual review)',
					'computed_at'     => time(),
				] );
			}
			catch ( \Throwable ) {}
		}

		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=review' ), 'saved' );
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
