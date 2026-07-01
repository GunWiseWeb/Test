<?php
namespace IPS\gdcompliance\modules\admin\compliance;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _lookup extends \IPS\Dispatcher\Controller
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

		$q      = trim( (string) ( \IPS\Request::i()->q ?? '' ) );
		$upcArg = trim( (string) ( \IPS\Request::i()->upc ?? '' ) );

		$baseUrl = \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lookup' );

		$searchForm = '<form method="get" action="' . htmlspecialchars( (string) $baseUrl, ENT_QUOTES, 'UTF-8' ) . '" class="ipsBox ipsPad" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:0 0 14px">'
			. '<input type="hidden" name="app" value="gdcompliance">'
			. '<input type="hidden" name="module" value="compliance">'
			. '<input type="hidden" name="controller" value="lookup">'
			. '<label style="display:flex;flex-direction:column;gap:3px;font-size:12px;flex:1 1 260px"><span>' . $h( 'gdcompliance_acp_lookup_search' ) . '</span><input type="search" name="q" value="' . htmlspecialchars( $q, ENT_QUOTES, 'UTF-8' ) . '" placeholder="UPC or title"></label>'
			. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">' . $h( 'gdcompliance_acp_search_go' ) . '</button>'
			. '</form>';

		$intro = '<div class="ipsBox" style="margin-bottom:16px"><div class="ipsBox_body ipsPad">'
			. '<h2 class="ipsType_sectionHead" style="margin:0 0 10px">' . $h( 'gdcompliance_acp_lookup_title' ) . '</h2>'
			. '<p style="margin:0 0 10px">' . $h( 'gdcompliance_acp_lookup_intro' ) . '</p>'
			. $searchForm
			. '</div></div>';

		$body = '';

		if ( $upcArg !== '' )
		{
			$body .= $this->renderUpcDetail( $upcArg );
		}
		elseif ( $q !== '' )
		{
			$body .= $this->renderResults( $q );
		}

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_acp_lookup_title' );
		\IPS\Output::i()->output = $intro . $body;
	}

	protected function renderResults( string $q ): string
	{
		$like = '%' . $q . '%';
		$rows = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'upc, title, manufacturer, model, caliber, category_id', 'gd_catalog',
				[ '(upc=? OR upc LIKE ? OR title LIKE ? OR model LIKE ?)', $q, $like, $like, $like ],
				'title ASC',
				[ 0, 50 ]
			) as $r )
			{
				$rows[] = $r;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'lookup search: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$h = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$out = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">';
		if ( empty( $rows ) )
		{
			$out .= '<p style="margin:0;color:#94a3b8">No products matched "' . $h( $q ) . '".</p>';
		}
		else
		{
			$out .= '<p style="margin:0 0 8px;color:#475569">Showing ' . count( $rows ) . ' products matching "' . $h( $q ) . '":</p>';
			$out .= '<table class="ipsTable ipsTable_responsive" style="width:100%;border-collapse:collapse">'
				. '<thead><tr>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">UPC</th>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Product</th>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Caliber</th>'
				. '<th style="text-align:right;padding:6px 10px;border-bottom:2px solid #e6e9ee"></th>'
				. '</tr></thead><tbody>';
			foreach ( $rows as $r )
			{
				$detailUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lookup' )->setQueryString( 'upc', (string) $r['upc'] );
				$out .= '<tr>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-family:ui-monospace,monospace;font-size:12px">' . $h( (string) $r['upc'] ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9"><strong>' . $h( (string) ( $r['manufacturer'] ?? '' ) ) . '</strong> ' . $h( (string) ( $r['model'] ?? '' ) ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9">' . $h( (string) ( $r['caliber'] ?? '' ) ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right"><a href="' . $h( $detailUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--verySmall">View overrides</a></td>'
					. '</tr>';
			}
			$out .= '</tbody></table>';
		}
		$out .= '</div></div>';
		return $out;
	}

	protected function renderUpcDetail( string $upc ): string
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$product = null;
		try
		{
			$product = \IPS\Db::i()->select( 'upc, title, manufacturer, model, caliber, capacity', 'gd_catalog', [ 'upc=?', $upc ] )->first();
		}
		catch ( \Throwable ) { $product = null; }

		if ( !is_array( $product ) )
		{
			return '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad"><p style="margin:0;color:#94a3b8">UPC "' . $h( $upc ) . '" not found in catalog.</p></div></div>';
		}

		/* Current computed flags + overrides. */
		$flags = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'state_code, reason', 'gd_compliance_flags', [ 'upc=?', $upc ] ) as $f )
			{
				$flags[ (string) $f['state_code'] ] = (string) $f['reason'];
			}
		}
		catch ( \Throwable ) {}
		$overrides = \IPS\gdcompliance\Override::forUpc( $upc );

		$states = array_unique( array_merge( array_keys( $flags ), array_keys( $overrides ) ) );
		sort( $states );

		$out  = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">';
		$out .= '<h2 class="ipsType_sectionHead" style="margin:0 0 10px"><span style="font-family:ui-monospace,monospace">' . $h( $product['upc'] ) . '</span></h2>';
		$out .= '<p style="margin:0 0 4px"><strong>' . $h( (string) $product['manufacturer'] ) . '</strong> ' . $h( (string) $product['model'] ) . '</p>';
		$out .= '<p style="margin:0 0 4px;color:#475569;font-size:13px">' . $h( (string) $product['title'] ) . '</p>';
		$out .= '<p style="margin:0 0 14px;color:#64748b;font-size:12px">'
			. 'Caliber: ' . $h( (string) ( $product['caliber'] ?? '—' ) )
			. ' &middot; Capacity: ' . $h( (string) ( $product['capacity'] ?? '—' ) )
			. '</p>';

		/* Per-state status + override buttons. */
		if ( empty( $states ) )
		{
			$out .= '<p style="margin:0 0 10px;color:#94a3b8;font-style:italic">No computed flags or overrides for this UPC yet.</p>';
		}
		else
		{
			$out .= '<table class="ipsTable ipsTable_responsive" style="width:100%;border-collapse:collapse;margin-bottom:12px">'
				. '<thead><tr>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">State</th>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Current flag reason</th>'
				. '<th style="text-align:left;padding:6px 10px;border-bottom:2px solid #e6e9ee">Override</th>'
				. '<th style="text-align:right;padding:6px 10px;border-bottom:2px solid #e6e9ee"></th>'
				. '</tr></thead><tbody>';
			foreach ( $states as $st )
			{
				$flagReason = $flags[ $st ] ?? '';
				$ov         = $overrides[ $st ] ?? null;
				$ovLabel    = $ov ? '<strong style="color:' . ( $ov['action'] === 'force_restrict' ? '#991b1b' : '#14532d' ) . '">' . strtoupper( (string) $ov['action'] ) . '</strong>' . ( $ov['reason'] ? '<br><span style="color:#64748b;font-size:12px">' . $h( (string) $ov['reason'] ) . '</span>' : '' ) : '<span style="color:#cbd5e1">—</span>';

				$restrictUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=overrides&do=form' )
					->setQueryString( [ 'upc' => $upc, 'state' => $st ] );

				$out .= '<tr>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-weight:700">' . $h( $st ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:13px">' . $h( $flagReason ?: '' ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9">' . $ovLabel . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right"><a href="' . $h( $restrictUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--verySmall">' . ( $ov ? 'Edit override' : 'Set override' ) . '</a></td>'
					. '</tr>';
			}
			$out .= '</tbody></table>';
		}

		/* Add-override quick shortcut. */
		$addUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=overrides&do=form' )
			->setQueryString( 'upc', $upc );
		$out .= '<a href="' . $h( $addUrl ) . '" class="ipsButton ipsButton--primary ipsButton--small">Add manual override for this UPC</a>';
		$out .= '</div></div>';
		return $out;
	}
}

class lookup extends _lookup {}
