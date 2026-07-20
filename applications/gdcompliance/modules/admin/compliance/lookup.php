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

	/* Category IDs used for smart category narrowing. Sourced from
	   the classifier constants where they exist so this and the
	   engine can't drift apart. Ammo + knife lists mirror the
	   lists in Advisories.php v1.6.47. */
	protected const CAT_MAGAZINE_STANDALONE = 38;   /* cat38 Magazines — Engine ~582 */
	protected const CATS_AMMO               = [ 23, 24, 25, 26, 27, 28, 29, 30 ];
	protected const CATS_KNIFE              = [ 138, 150 ];

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

	/**
	 * v1.6.52 — POST endpoint for the consolidated Manual Flag tool.
	 * Receives (upc, apply_action=flag|clear, items[]={state,cat,ftype,
	 * reason}) tuples. For each tuple calls Override::save() with the
	 * firearm_type matching what auto-compute would have used
	 * (byte-identical rows in gd_compliance_flags). Clears
	 * gd_compliance_review for the UPC on success. Redirects back to
	 * the UPC detail with a flash message.
	 */
	protected function mflagApply(): void
	{
		\IPS\Session::i()->csrfCheck();

		$upc = trim( (string) ( \IPS\Request::i()->upc ?? '' ) );
		$upc = preg_replace( '/[^A-Za-z0-9]/', '', $upc );

		$actionPost = (string) ( \IPS\Request::i()->apply_action ?? 'flag' );
		$act = ( $actionPost === 'clear' )
			? \IPS\gdcompliance\Override::ACTION_CLEAR
			: \IPS\gdcompliance\Override::ACTION_RESTRICT;

		$items = (array) ( \IPS\Request::i()->items ?? [] );
		$reasons = (array) ( \IPS\Request::i()->reasons ?? [] );

		$memberId = (int) \IPS\Member::loggedIn()->member_id;
		$applied  = [];
		$failed   = [];

		foreach ( $items as $key )
		{
			$key = (string) $key;
			/* key format: "STATE|firearm_type" so per-(state,category) is
			   uniquely identifiable and duplicates in the same state
			   across categories are supported. */
			if ( !preg_match( '/^([A-Z]{2})\|([a-z_]{1,20})$/', $key, $m ) ) { continue; }
			$sc    = $m[1];
			$ftype = $m[2];
			$reason = trim( (string) ( $reasons[ $key ] ?? '' ) );

			try
			{
				$res = \IPS\gdcompliance\Override::save(
					$upc,
					$sc,
					$act,
					$reason !== '' ? $reason : null,
					$memberId,
					true,
					$ftype
				);
				if ( !empty( $res['ok'] ) ) { $applied[] = $sc . '/' . $ftype; }
				else                        { $failed[]  = $sc . '/' . $ftype . ' (' . (string) ( $res['error'] ?? 'unknown' ) . ')'; }
			}
			catch ( \Throwable $e )
			{
				$failed[] = $sc . '/' . $ftype . ' (' . $e->getMessage() . ')';
			}
		}

		if ( $upc !== '' && !empty( $applied ) )
		{
			try { \IPS\Db::i()->delete( 'gd_compliance_review', [ 'upc=?', $upc ] ); }
			catch ( \Throwable ) {}
		}
		try { \IPS\gdcompliance\Lowers::clearCache(); }     catch ( \Throwable ) {}
		try { \IPS\gdcompliance\Advisories::clearCache(); } catch ( \Throwable ) {}

		$verb = $act === \IPS\gdcompliance\Override::ACTION_CLEAR ? 'cleared' : 'flagged';
		$msg = 'UPC ' . $upc . ' — ' . $verb . ' in ' . count( $applied ) . ' (state, category) pair(s)'
			. ( !empty( $applied ) ? ': ' . implode( ', ', $applied ) : '' )
			. ( !empty( $failed )  ? '. FAILED: ' . implode( '; ', $failed ) : '.' );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lookup' )
				->setQueryString( 'upc', $upc ),
			$msg
		);
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
			$product = \IPS\Db::i()->select( 'upc, title, manufacturer, brand, model, mpn, caliber, capacity, category_id, action_type', 'gd_catalog', [ 'upc=?', $upc ] )->first();
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
			if ( $derivedType === null && $categoryId !== self::CAT_MAGAZINE_STANDALONE
				&& $categoryId !== \IPS\gdcompliance\Lowers::CATEGORY_LOWER
				&& $categoryId !== \IPS\gdcompliance\Lowers::CATEGORY_FRAMES_JUNK
				&& !in_array( $categoryId, self::CATS_AMMO, true )
				&& !in_array( $categoryId, self::CATS_KNIFE, true ) )
			{
				$reasons[] = 'This product\'s category (id ' . $categoryId . ') is not mapped to any compliance pass. Only firearm / lower / mag / ammo / knife categories are evaluated.';
			}
			if ( $parsedCap === null && $capRaw !== '' )
			{
				$reasons[] = 'Capacity "' . $h( $capRaw ) . '" could not be parsed to an integer.';
			}
			if ( $capRaw === '' && $derivedType !== null && !in_array( $derivedType, [ 'ammo', 'knife' ], true ) )
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
		$out .= '<a href="' . $h( $addUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--small" style="margin-right:6px">Single-state manual override (advanced)</a>';
		$out .= '</div></div>';

		/* v1.6.52 — Manual Flag / Clear panel (consolidated tool).
		   Category-narrowed picker per this product's type. */
		$out .= $this->renderManualFlagPanel( $product, $categoryId, $derivedType, $parsedCap );

		return $out;
	}

	/**
	 * v1.6.52 — Consolidated Manual Flag tool.
	 *
	 * Given a product, compute the categories that are PLAUSIBLE for
	 * its category_id, then for each of those categories query the
	 * enabled per-state rule table and offer a (state × category)
	 * checkbox with a reason preview auto-filled via ReasonBuilder
	 * — the SAME sprintf() Engine::computeFlags() uses so manual +
	 * auto flags are byte-identical in gd_compliance_flags.
	 *
	 * Narrowing rules (matches the ticket's spec):
	 *   cat154 / cat69   -> AWB Lower only.
	 *   $type='rifle'    -> AWB Rifle Tier 1/2 + Rate of Fire +
	 *                       Fixed-mag Capacity (if capacity parseable).
	 *   $type='handgun'  -> Melting Point + Fixed-mag Capacity
	 *                       (if capacity parseable) + AWB Pistol (if
	 *                       any state has a pistol-class enabled rule).
	 *   $type='shotgun'  -> Fixed-mag Capacity only.
	 *   cat==38          -> Magazine Capacity only.
	 *   $type='ammo'     -> Ammo Advisory only.
	 *   $type='knife'    -> Knife Advisory only.
	 *   otherwise        -> panel shows "No compliance categories
	 *                       apply to this product type" (nothing to
	 *                       flag).
	 */
	protected function renderManualFlagPanel( array $product, int $categoryId, ?string $type, ?int $parsedCap ): string
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		$csrf = (string) \IPS\Session::i()->csrfKey;

		$upc     = (string) ( $product['upc'] ?? '' );
		$brand   = trim( (string) ( $product['brand']        ?? '' ) );
		$model   = trim( (string) ( $product['model']        ?? '' ) );
		$mfg     = trim( (string) ( $product['manufacturer'] ?? '' ) );
		$pattern = trim( ( $mfg !== '' ? $mfg : $brand ) . ' ' . $model );

		/* Determine which categories apply (never show irrelevant
		   pickers for the product type). */
		$applies = [
			'awb_lower'   => false,
			'awb_rifle'   => false,
			'awb_pistol'  => false,
			'rate_of_fire'=> false,
			'fixed_mag'   => false,
			'magazine'    => false,
			'melting_point'=> false,
			'ammo'        => false,
			'knife'       => false,
		];

		if ( $categoryId === \IPS\gdcompliance\Lowers::CATEGORY_LOWER
		  || $categoryId === \IPS\gdcompliance\Lowers::CATEGORY_FRAMES_JUNK )
		{
			$applies['awb_lower'] = true;
		}
		elseif ( $categoryId === self::CAT_MAGAZINE_STANDALONE )
		{
			$applies['magazine'] = true;
		}
		elseif ( in_array( $categoryId, self::CATS_AMMO, true ) || $type === 'ammo' )
		{
			$applies['ammo'] = true;
		}
		elseif ( in_array( $categoryId, self::CATS_KNIFE, true ) || $type === 'knife' )
		{
			$applies['knife'] = true;
		}
		elseif ( $type === 'rifle' )
		{
			$applies['awb_rifle']    = true;
			$applies['rate_of_fire'] = true;
			if ( $parsedCap !== null ) { $applies['fixed_mag'] = true; }
		}
		elseif ( $type === 'handgun' )
		{
			$applies['awb_pistol']    = true;
			$applies['melting_point'] = true;
			if ( $parsedCap !== null ) { $applies['fixed_mag'] = true; }
		}
		elseif ( $type === 'shotgun' )
		{
			if ( $parsedCap !== null ) { $applies['fixed_mag'] = true; }
		}

		$appliesActive = array_keys( array_filter( $applies ) );

		$hdr = '<div class="ipsBox" style="margin-bottom:14px;border-left:4px solid #059669"><div class="ipsBox_body ipsPad" style="background:#ecfdf5">'
			. '<h3 style="margin:0 0 6px;font-size:15px;color:#065f46">' . $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_title' ) ) . '</h3>'
			. '<p style="margin:0 0 8px;color:#065f46;font-size:13px">' . $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_intro' ) ) . '</p>'
			. '<p style="margin:0;color:#065f46;font-size:12px"><strong>' . $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_applies' ) ) . ':</strong> '
			. ( empty( $appliesActive ) ? '<em>none</em>' : $h( implode( ', ', $appliesActive ) ) )
			. '</p>'
			. '</div></div>';

		if ( empty( $appliesActive ) )
		{
			return $hdr . '<div class="ipsBox"><div class="ipsBox_body ipsPad" style="text-align:center;color:#94a3b8">'
				. $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_none' ) )
				. ' (' . $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_type' ) ) . ': ' . $h( (string) ( $type ?? 'unknown' ) ) . ', category ' . $categoryId . ')'
				. '</div></div>';
		}

		/* Assemble (state, category, ftype, reason, cite) tuples. */
		$tuples = [];

		if ( $applies['awb_lower'] )
		{
			foreach ( $this->fetchAwbRules( 'rifle' ) as $sc => $r )
			{
				$tuples[] = [
					'key'    => $sc . '|awb_lower',
					'state'  => $sc,
					'cat'    => 'awb_lower',
					'ftype'  => 'awb_lower',
					'label'  => 'AWB Lower',
					'cite'   => (string) ( $r['citation'] ?? '' ),
					'reason' => \IPS\gdcompliance\ReasonBuilder::awbLower( $sc, (string) ( $r['citation'] ?? '' ), $pattern, false ),
				];
			}
		}
		if ( $applies['awb_rifle'] )
		{
			foreach ( $this->fetchAwbRules( 'rifle' ) as $sc => $r )
			{
				$tuples[] = [
					'key'    => $sc . '|awb_rifle',
					'state'  => $sc,
					'cat'    => 'awb_rifle',
					'ftype'  => 'awb_rifle',
					'label'  => 'AWB Rifle',
					'cite'   => (string) ( $r['citation'] ?? '' ),
					'reason' => \IPS\gdcompliance\ReasonBuilder::awbRifleTier1( $sc, (string) ( $r['citation'] ?? '' ), $pattern ),
				];
			}
		}
		if ( $applies['awb_pistol'] )
		{
			foreach ( $this->fetchAwbRules( 'pistol' ) as $sc => $r )
			{
				$tuples[] = [
					'key'    => $sc . '|awb_pistol',
					'state'  => $sc,
					'cat'    => 'awb_pistol',
					'ftype'  => 'awb_pistol',
					'label'  => 'AWB Pistol',
					'cite'   => (string) ( $r['citation'] ?? '' ),
					'reason' => \IPS\gdcompliance\ReasonBuilder::awbRifleTier1( $sc, (string) ( $r['citation'] ?? '' ), $pattern ),
				];
			}
		}
		if ( $applies['rate_of_fire'] )
		{
			foreach ( $this->fetchStateRules( 'gd_compliance_rof_rules' ) as $sc => $r )
			{
				$tuples[] = [
					'key'    => $sc . '|rate_of_fire',
					'state'  => $sc,
					'cat'    => 'rate_of_fire',
					'ftype'  => 'rate_of_fire',
					'label'  => 'Rate of Fire',
					'cite'   => (string) ( $r['citation'] ?? '' ),
					'reason' => \IPS\gdcompliance\ReasonBuilder::rateOfFire( $sc, (string) ( $r['reason'] ?? '' ), '', 'manual' ),
				];
			}
		}
		if ( $applies['melting_point'] )
		{
			foreach ( $this->fetchStateRules( 'gd_compliance_melting_rules' ) as $sc => $r )
			{
				$tuples[] = [
					'key'    => $sc . '|melting_point',
					'state'  => $sc,
					'cat'    => 'melting_point',
					'ftype'  => 'melting_point',
					'label'  => 'Melting Point',
					'cite'   => (string) ( $r['citation'] ?? '' ),
					'reason' => \IPS\gdcompliance\ReasonBuilder::meltingPoint( $sc, (string) ( $r['reason'] ?? '' ), '', 'manual' ),
				];
			}
		}
		if ( $applies['fixed_mag'] && $parsedCap !== null && $type !== null )
		{
			foreach ( $this->fetchCapacityRulesForType( $type ) as $sc => $r )
			{
				$limit = (int) ( $r['max_capacity'] ?? 0 );
				if ( $limit <= 0 || $parsedCap <= $limit ) { continue; }
				$tuples[] = [
					'key'    => $sc . '|' . $type,
					'state'  => $sc,
					'cat'    => 'fixed_mag',
					'ftype'  => $type,
					'label'  => 'Fixed-mag capacity (' . $type . ')',
					'cite'   => (string) ( $r['source_note'] ?? '' ),
					'reason' => \IPS\gdcompliance\ReasonBuilder::fixedMag( $type, (int) $parsedCap, $sc, $limit ),
				];
			}
		}
		if ( $applies['magazine'] && $parsedCap !== null )
		{
			/* Standalone magazine — classify from caliber/title and use
			   the matching class's limit per state. Mirrors Engine ~598. */
			$magClass = 'ambiguous';
			try
			{
				$magClass = \IPS\gdcompliance\Engine::classifyMagClass(
					(string) ( $product['caliber'] ?? '' ),
					(string) ( $product['title']   ?? '' )
				);
			}
			catch ( \Throwable ) {}
			$effClass = in_array( $magClass, [ 'rifle', 'handgun', 'shotgun' ], true ) ? $magClass : 'rifle';
			foreach ( $this->fetchCapacityRulesForType( $effClass ) as $sc => $r )
			{
				$limit = (int) ( $r['max_capacity'] ?? 0 );
				if ( $limit <= 0 || $parsedCap <= $limit ) { continue; }
				$tuples[] = [
					'key'    => $sc . '|magazine',
					'state'  => $sc,
					'cat'    => 'magazine',
					'ftype'  => 'magazine',
					'label'  => 'Magazine capacity (' . $effClass . ')',
					'cite'   => (string) ( $r['source_note'] ?? '' ),
					'reason' => \IPS\gdcompliance\ReasonBuilder::magazine( (int) $parsedCap, $sc, $effClass, $limit, (string) ( $r['source_note'] ?? '' ) ),
				];
			}
		}
		if ( $applies['ammo'] || $applies['knife'] )
		{
			$cls = $applies['ammo'] ? 'ammo' : 'knife';
			$rows = [];
			try
			{
				foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_advisory_rules',
					[ 'enabled=1 AND firearm_class=?', $cls ], 'state_code ASC' ) as $r )
				{
					$sc = strtoupper( (string) ( $r['state_code'] ?? '' ) );
					if ( strlen( $sc ) === 2 ) { $rows[ $sc ] = $r; }
				}
			}
			catch ( \Throwable ) {}
			foreach ( $rows as $sc => $r )
			{
				$tuples[] = [
					'key'    => $sc . '|advisory',
					'state'  => $sc,
					'cat'    => 'advisory',
					'ftype'  => 'advisory',
					'label'  => ucfirst( $cls ) . ' Advisory',
					'cite'   => (string) ( $r['citation'] ?? '' ),
					'reason' => \IPS\gdcompliance\ReasonBuilder::advisory( $r ),
				];
			}
		}

		if ( empty( $tuples ) )
		{
			return $hdr . '<div class="ipsBox"><div class="ipsBox_body ipsPad" style="text-align:center;color:#94a3b8">'
				. $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_no_rules' ) )
				. '</div></div>';
		}

		/* Group tuples by state for a cleaner layout. */
		$byState = [];
		foreach ( $tuples as $t ) { $byState[ $t['state'] ][] = $t; }
		ksort( $byState );

		$applyUrl = (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=compliance&controller=lookup&do=mflagApply' );

		$form  = '<form method="post" action="' . $h( $applyUrl ) . '">'
			. '<input type="hidden" name="csrfKey" value="' . $h( $csrf ) . '">'
			. '<input type="hidden" name="upc" value="' . $h( $upc ) . '">'
			. '<div class="ipsBox"><div class="ipsBox_body ipsPad">'
			. '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;font-size:13px">'
			. '<label style="display:inline-flex;align-items:center;gap:4px"><input type="radio" name="apply_action" value="flag" checked> <strong style="color:#991b1b">' . $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_action_flag' ) ) . '</strong></label>'
			. '<label style="display:inline-flex;align-items:center;gap:4px"><input type="radio" name="apply_action" value="clear"> <strong style="color:#065f46">' . $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_action_clear' ) ) . '</strong></label>'
			. '<span style="color:#94a3b8">|</span>'
			. '<a href="#" onclick="var b=this.closest(\'form\').querySelectorAll(\'input[name=items\\\\[\\\\]]\');for(var i=0;i<b.length;i++){b[i].checked=true;}return false;" style="font-size:12px">' . $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_select_all' ) ) . '</a>'
			. ' &middot; <a href="#" onclick="var b=this.closest(\'form\').querySelectorAll(\'input[name=items\\\\[\\\\]]\');for(var i=0;i<b.length;i++){b[i].checked=false;}return false;" style="font-size:12px">' . $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_select_none' ) ) . '</a>'
			. '</div>'
			. '<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(400px, 1fr));gap:10px">';

		foreach ( $byState as $sc => $stateTuples )
		{
			$form .= '<div style="border:1px solid #e2e8f0;border-radius:6px;padding:10px;background:#f8fafc">'
				. '<div style="font-weight:800;font-size:13px;margin-bottom:6px;color:#0f172a">' . $h( $sc ) . '</div>';
			foreach ( $stateTuples as $t )
			{
				$form .= '<div style="margin-bottom:8px">'
					. '<label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:3px">'
					. '<input type="checkbox" name="items[]" value="' . $h( $t['key'] ) . '"> '
					. '<span style="font-weight:600">' . $h( $t['label'] ) . '</span>'
					. ( $t['cite'] !== '' ? ' <span style="color:#64748b;font-size:11px;font-family:ui-monospace,monospace">' . $h( $t['cite'] ) . '</span>' : '' )
					. '</label>'
					. '<textarea name="reasons[' . $h( $t['key'] ) . ']" rows="2" style="width:100%;font-family:ui-monospace,monospace;font-size:11px;padding:5px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;box-sizing:border-box">' . $h( $t['reason'] ) . '</textarea>'
					. '</div>';
			}
			$form .= '</div>';
		}

		$form .= '</div>'
			. '<div style="margin-top:12px;display:flex;gap:8px;align-items:center">'
			. '<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">' . $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_apply_now' ) ) . '</button>'
			. '<span style="color:#64748b;font-size:12px">' . $h( $lang->addToStack( 'gdcompliance_acp_lookup_mflag_apply_note' ) ) . '</span>'
			. '</div>'
			. '</div></div>'
			. '</form>';

		return $hdr . $form;
	}

	/**
	 * Fetch enabled AWB rules keyed by state_code for a given firearm_class.
	 * @return array<string, array<string,mixed>>
	 */
	protected function fetchAwbRules( string $firearmClass ): array
	{
		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_awb_rules',
				[ 'enabled=1 AND firearm_class=?', $firearmClass ], 'state_code ASC' ) as $r )
			{
				$sc = strtoupper( (string) ( $r['state_code'] ?? '' ) );
				if ( strlen( $sc ) === 2 ) { $out[ $sc ] = $r; }
			}
		}
		catch ( \Throwable ) {}
		return $out;
	}

	/**
	 * Fetch enabled per-state rows keyed by state_code from a per-state
	 * rules table (gd_compliance_melting_rules / gd_compliance_rof_rules).
	 * The table may not exist on partial upgrades — silent fallback to
	 * empty array so the caller no-ops for that category.
	 * @return array<string, array<string,mixed>>
	 */
	protected function fetchStateRules( string $table ): array
	{
		$out = [];
		try
		{
			foreach ( \IPS\Db::i()->select( '*', $table, [ 'enabled=1' ], 'state_code ASC' ) as $r )
			{
				$sc = strtoupper( (string) ( $r['state_code'] ?? '' ) );
				if ( strlen( $sc ) === 2 ) { $out[ $sc ] = $r; }
			}
		}
		catch ( \Throwable ) {}
		return $out;
	}

	/**
	 * Enabled capacity-rule rows keyed by state, filtered to the given
	 * firearm_type (or 'all'). Reads gd_compliance_rules — the same
	 * table Engine::activeRules() reads.
	 * @return array<string, array<string,mixed>>
	 */
	protected function fetchCapacityRulesForType( string $firearmType ): array
	{
		$out = [];
		try
		{
			$today = date( 'Y-m-d' );
			foreach ( \IPS\Db::i()->select( '*', 'gd_compliance_rules',
				[ 'enabled=1 AND firearm_type IN (?,?) AND (effective_date IS NULL OR effective_date<=?) AND (expires_date IS NULL OR expires_date>=?)',
				  $firearmType, 'all', $today, $today ], 'state_code ASC' ) as $r )
			{
				$sc = strtoupper( (string) ( $r['state_code'] ?? '' ) );
				if ( strlen( $sc ) !== 2 ) { continue; }
				/* Keep the tightest limit if we've already stored a row
				   for this state (e.g. 'all' + type-specific). */
				if ( isset( $out[ $sc ] ) && (int) $out[ $sc ]['max_capacity'] <= (int) $r['max_capacity'] )
				{
					continue;
				}
				$out[ $sc ] = $r;
			}
		}
		catch ( \Throwable ) {}
		return $out;
	}
}

class lookup extends _lookup {}
