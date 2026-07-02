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
			$product = \IPS\Db::i()->select( 'upc, title, manufacturer, model, caliber, capacity, category_id', 'gd_catalog', [ 'upc=?', $upc ] )->first();
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
			foreach ( \IPS\Db::i()->select( 'state_code, reason, rule_id, firearm_type, parsed_capacity', 'gd_compliance_flags', [ 'upc=?', $upc ] ) as $f )
			{
				$flags[ (string) $f['state_code'] ] = [
					'reason'          => (string) $f['reason'],
					'rule_id'         => (int)    ( $f['rule_id'] ?? 0 ),
					'firearm_type'    => (string) ( $f['firearm_type'] ?? '' ),
					'parsed_capacity' => $f['parsed_capacity'],
				];
			}
		}
		catch ( \Throwable ) {}
		$overrides = \IPS\gdcompliance\Override::forUpc( $upc );

		/* Per-state AWB exemption_note. Rifle wins over pistol when both
		   exist for a state (matches how AWB flags render per state). */
		$awbExemption = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'state_code, firearm_class, exemption_note',
				'gd_compliance_awb_rules', [ "exemption_note IS NOT NULL AND exemption_note<>''" ] ) as $er )
			{
				$st  = strtoupper( (string) ( $er['state_code'] ?? '' ) );
				$cls = strtolower( (string) ( $er['firearm_class'] ?? '' ) );
				$en  = trim( (string) ( $er['exemption_note'] ?? '' ) );
				if ( $st === '' || $en === '' ) { continue; }
				if ( !isset( $awbExemption[ $st ] ) || $cls === 'rifle' )
				{
					$awbExemption[ $st ] = $en;
				}
			}
		}
		catch ( \Throwable ) { $awbExemption = []; }

		$states = array_unique( array_merge( array_keys( $flags ), array_keys( $overrides ) ) );
		sort( $states );

		/* --- Diagnose why flags/type/capacity look the way they do --- */
		$categoryId    = (int) ( $product['category_id'] ?? 0 );
		$derivedType   = null;
		try
		{
			$typeMap     = \IPS\gdcompliance\Engine::buildTypeMap();
			$derivedType = $typeMap[ $categoryId ] ?? null;
		}
		catch ( \Throwable ) {}
		$capRaw    = (string) ( $product['capacity'] ?? '' );
		$parsedCap = null;
		try { $parsedCap = \IPS\gdcompliance\Engine::parseCapacity( $capRaw ); } catch ( \Throwable ) {}

		$totalFlagsInSystem = 0;
		try { $totalFlagsInSystem = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_compliance_flags' )->first(); }
		catch ( \Throwable ) {}

		$out  = '<div class="ipsBox" style="margin-bottom:14px"><div class="ipsBox_body ipsPad">';
		$out .= '<h2 class="ipsType_sectionHead" style="margin:0 0 10px"><span style="font-family:ui-monospace,monospace">' . $h( $product['upc'] ) . '</span></h2>';
		$out .= '<p style="margin:0 0 4px"><strong>' . $h( (string) $product['manufacturer'] ) . '</strong> ' . $h( (string) $product['model'] ) . '</p>';
		$out .= '<p style="margin:0 0 4px;color:#475569;font-size:13px">' . $h( (string) $product['title'] ) . '</p>';
		$out .= '<p style="margin:0 0 12px;color:#64748b;font-size:12px">'
			. 'Caliber: ' . $h( (string) ( $product['caliber'] ?? '—' ) )
			. ' &middot; Raw capacity: ' . $h( $capRaw !== '' ? $capRaw : '—' )
			. ' &middot; Parsed capacity: ' . ( $parsedCap === null ? '<em style="color:#dc2626">unparsed</em>' : '<strong>' . (int) $parsedCap . '</strong>' )
			. ' &middot; Firearm type: ' . ( $derivedType === null ? '<em style="color:#dc2626">not a firearm category</em>' : '<strong>' . $h( $derivedType ) . '</strong>' )
			. ' &middot; Category id: ' . $categoryId
			. '</p>';

		/* Per-state status + override buttons. */
		if ( empty( $states ) )
		{
			$reasons = [];
			if ( $totalFlagsInSystem === 0 )
			{
				$reasons[] = '<strong>Flags table is empty</strong> — run Compute Flags in the ACP first.';
			}
			if ( $derivedType === null )
			{
				$reasons[] = 'This product\'s category (id ' . $categoryId . ') is not mapped to a firearm type (handgun / rifle / shotgun). Only firearm-category products are evaluated.';
			}
			if ( $parsedCap === null && $capRaw !== '' )
			{
				$reasons[] = 'Capacity "' . $h( $capRaw ) . '" could not be parsed to an integer.';
			}
			if ( $capRaw === '' && $derivedType !== null )
			{
				$reasons[] = 'Product has no capacity value in the catalog — capacity rules cannot apply.';
			}
			if ( $derivedType !== null && $parsedCap !== null )
			{
				$reasons[] = 'Capacity ' . (int) $parsedCap . ' falls at or below every enabled rule limit for firearm type <strong>' . $h( $derivedType ) . '</strong>.';
			}
			if ( empty( $reasons ) )
			{
				$reasons[] = 'No restrictions computed and no override set.';
			}

			$out .= '<div style="margin:0 0 12px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">'
				. '<p style="margin:0 0 8px;font-weight:700;color:#334155">' . $h( $lang->addToStack( 'gdcompliance_acp_lookup_no_flags' ) ) . '</p>'
				. '<ul style="margin:0;padding-left:20px;color:#475569;font-size:13px;line-height:1.5">';
			foreach ( $reasons as $why )
			{
				$out .= '<li>' . $why . '</li>';
			}
			$out .= '</ul></div>';
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
				$flagRow    = $flags[ $st ] ?? null;
				$flagReason = is_array( $flagRow ) ? (string) ( $flagRow['reason'] ?? '' ) : (string) $flagRow;
				$ftype      = is_array( $flagRow ) ? (string) ( $flagRow['firearm_type'] ?? '' ) : '';
				$isAwbRow   = ( strncmp( $ftype, 'awb_', 4 ) === 0 || strncmp( $ftype, 'pica_', 5 ) === 0 );
				$exemption  = ( $isAwbRow && isset( $awbExemption[ $st ] ) ) ? (string) $awbExemption[ $st ] : '';
				$ov         = $overrides[ $st ] ?? null;
				$ovLabel    = $ov ? '<strong style="color:' . ( $ov['action'] === 'force_restrict' ? '#991b1b' : '#14532d' ) . '">' . strtoupper( (string) $ov['action'] ) . '</strong>' . ( $ov['reason'] ? '<br><span style="color:#64748b;font-size:12px">' . $h( (string) $ov['reason'] ) . '</span>' : '' ) : '<span style="color:#cbd5e1">—</span>';

				$restrictUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=overrides&do=form' )
					->setQueryString( [ 'upc' => $upc, 'state' => $st ] );

				$reasonCell = $h( $flagReason ?: '' );
				if ( $exemption !== '' )
				{
					$reasonCell .= '<div style="margin-top:6px;padding:6px 8px;background:#fff7ed;border:1px solid #fdba74;border-radius:6px;color:#7c2d12;font-size:12px;line-height:1.4">'
						. '<strong style="font-size:11px;letter-spacing:.03em;text-transform:uppercase;color:#9a3412">Exemption note</strong><br>'
						. nl2br( $h( $exemption ) )
						. '</div>';
				}

				$out .= '<tr>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;font-weight:700;vertical-align:top">' . $h( $st ) . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:13px;vertical-align:top">' . $reasonCell . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top">' . $ovLabel . '</td>'
					. '<td style="padding:6px 10px;border-bottom:1px solid #f1f5f9;text-align:right;vertical-align:top"><a href="' . $h( $restrictUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--verySmall">' . ( $ov ? 'Edit override' : 'Set override' ) . '</a></td>'
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
