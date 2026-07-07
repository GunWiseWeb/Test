<?php

namespace IPS\gdloadout\modules\front\loadouts;

use IPS\Db;
use IPS\Http\Url;
use IPS\Member;
use IPS\Output;
use IPS\Request;
use IPS\Session;
use IPS\Theme;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _builder extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	/**
	 * v1.0.62 — USPS state codes → names. Used by the compliance
	 * panel to (a) build the state selector, (b) turn state_code
	 * from gd_compliance_flags into a human-readable label.
	 * Kept as an inline constant so no extra DB / file read is
	 * needed at page-load time.
	 */
	private const COMPLIANCE_STATES = [
		'AL' => 'Alabama',        'AK' => 'Alaska',        'AZ' => 'Arizona',    'AR' => 'Arkansas',
		'CA' => 'California',     'CO' => 'Colorado',      'CT' => 'Connecticut','DE' => 'Delaware',
		'DC' => 'District of Columbia',
		'FL' => 'Florida',        'GA' => 'Georgia',       'HI' => 'Hawaii',     'ID' => 'Idaho',
		'IL' => 'Illinois',       'IN' => 'Indiana',       'IA' => 'Iowa',       'KS' => 'Kansas',
		'KY' => 'Kentucky',       'LA' => 'Louisiana',     'ME' => 'Maine',      'MD' => 'Maryland',
		'MA' => 'Massachusetts',  'MI' => 'Michigan',      'MN' => 'Minnesota',  'MS' => 'Mississippi',
		'MO' => 'Missouri',       'MT' => 'Montana',       'NE' => 'Nebraska',   'NV' => 'Nevada',
		'NH' => 'New Hampshire',  'NJ' => 'New Jersey',    'NM' => 'New Mexico', 'NY' => 'New York',
		'NC' => 'North Carolina', 'ND' => 'North Dakota',  'OH' => 'Ohio',       'OK' => 'Oklahoma',
		'OR' => 'Oregon',         'PA' => 'Pennsylvania',  'RI' => 'Rhode Island',
		'SC' => 'South Carolina', 'SD' => 'South Dakota',  'TN' => 'Tennessee',  'TX' => 'Texas',
		'UT' => 'Utah',           'VT' => 'Vermont',       'VA' => 'Virginia',   'WA' => 'Washington',
		'WV' => 'West Virginia',  'WI' => 'Wisconsin',     'WY' => 'Wyoming',
	];

	public function execute(): void
	{
		if ( !Member::loggedIn()->member_id )
		{
			Output::i()->error( 'no_module_permission', '2GL02/1', 403 );
			return;
		}
		parent::execute();
	}

	/**
	 * v1.0.62 — read the buyer's chosen state from cookie. Empty
	 * (no cookie / invalid code) means "no state selected yet."
	 */
	private function currentComplianceState(): string
	{
		$raw = strtoupper( preg_replace( '/[^A-Z]/', '', (string) ( $_COOKIE['gdlo_state'] ?? '' ) ) );
		return isset( self::COMPLIANCE_STATES[ $raw ] ) ? $raw : '';
	}

	/**
	 * v1.0.62 — for a given batch of UPCs, pull every matching
	 * gd_compliance_flags row keyed by UPC. Wrapped in try/catch
	 * so a missing gdcompliance install (or a locked flags table)
	 * cannot break the loadout builder. gd_compliance_flags is
	 * READ-ONLY here — never written.
	 */
	private function fetchComplianceFlags( array $upcs ): array
	{
		$out = [];
		$upcs = array_values( array_unique( array_filter( array_map( 'strval', $upcs ) ) ) );
		if ( empty( $upcs ) ) { return $out; }

		foreach ( $upcs as $upc )
		{
			try
			{
				foreach ( Db::i()->select(
					'state_code, firearm_type, reason, citation',
					'gd_compliance_flags',
					[ 'upc=?', $upc ]
				) as $f )
				{
					$out[ $upc ][] = $f;
				}
			}
			catch ( \Throwable ) { /* gdcompliance optional — silent fallback */ }
		}
		return $out;
	}

	/**
	 * v1.0.62 — decorate a loadout-item array with compliance
	 * fields for the current buyer's state:
	 *   compliance_restricted_here       bool   restricted (non-advisory) in $state
	 *   compliance_advisory_here         bool   advisory (buyer permit) in $state
	 *   compliance_reason_here           string reason text for $state
	 *   compliance_all_states            array  every state where flagged
	 *   compliance_restricted_state_codes array distinct restricted state codes
	 */
	private function decorateItem( array $item, array $flags, string $state ): array
	{
		$all      = [];
		$codes    = [];
		$here_r   = false;
		$here_a   = false;
		$here_msg = '';

		foreach ( $flags as $f )
		{
			$ftype      = (string) ( $f['firearm_type'] ?? '' );
			$isAdvisory = ( $ftype === 'advisory' );
			$sc         = strtoupper( (string) ( $f['state_code'] ?? '' ) );

			$all[] = [
				'state'      => $sc,
				'state_name' => self::COMPLIANCE_STATES[ $sc ] ?? $sc,
				'type'       => $isAdvisory ? 'advisory' : 'restricted',
				'reason'     => (string) ( $f['reason'] ?? '' ),
				'citation'   => (string) ( $f['citation'] ?? '' ),
			];

			if ( !$isAdvisory ) { $codes[ $sc ] = true; }

			if ( $state !== '' && $sc === $state )
			{
				if ( $isAdvisory )
				{
					$here_a = true;
					if ( $here_msg === '' ) { $here_msg = (string) ( $f['reason'] ?? '' ); }
				}
				else
				{
					$here_r   = true;
					$here_msg = (string) ( $f['reason'] ?? '' );
				}
			}
		}

		$item['compliance_restricted_here']        = $here_r;
		$item['compliance_advisory_here']          = $here_a;
		$item['compliance_reason_here']            = $here_msg;
		$item['compliance_all_states']             = $all;
		$item['compliance_restricted_state_codes'] = array_keys( $codes );
		return $item;
	}

	/**
	 * v1.0.62 — set/clear the compliance-state cookie and bounce
	 * back to the builder with the loadout_id preserved. CSRF
	 * checked so a stray link can't retarget someone else's
	 * cookie via a same-origin request. Never writes to
	 * gd_compliance_flags or gd_catalog.
	 */
	protected function setComplianceState(): void
	{
		Session::i()->csrfCheck();

		$state = strtoupper( preg_replace( '/[^A-Z]/', '', (string) ( Request::i()->state ?? '' ) ) );
		if ( strlen( $state ) === 2 && isset( self::COMPLIANCE_STATES[ $state ] ) )
		{
			setcookie( 'gdlo_state', $state, [
				'expires'  => time() + 86400 * 365,
				'path'     => '/',
				'httponly' => TRUE,
				'samesite' => 'Lax',
			] );
		}
		else
		{
			setcookie( 'gdlo_state', '', [
				'expires' => time() - 3600,
				'path'    => '/',
			] );
		}

		$url = Url::internal( 'app=gdloadout&module=loadouts&controller=builder', 'front' );
		$editId = (int) ( Request::i()->loadout_id ?? 0 );
		if ( $editId > 0 ) { $url = $url->setQueryString( 'loadout_id', $editId ); }
		Output::i()->redirect( (string) $url );
	}

	/**
	 * v1.0.62 — build-level compliance summary in one pass over
	 * the decorated items. Returns { total, restricted, advisory,
	 * restricted_upcs }.
	 */
	private function summarizeCompliance( array $items ): array
	{
		$total = 0; $restricted = 0; $advisory = 0; $restrictedUpcs = [];
		foreach ( $items as $it )
		{
			if ( empty( $it['upc'] ) ) { continue; }
			$total++;
			if ( !empty( $it['compliance_restricted_here'] ) )
			{
				$restricted++;
				$restrictedUpcs[] = (string) $it['upc'];
			}
			elseif ( !empty( $it['compliance_advisory_here'] ) )
			{
				$advisory++;
			}
		}
		return [
			'total'            => $total,
			'restricted'       => $restricted,
			'advisory'         => $advisory,
			'restricted_upcs'  => $restrictedUpcs,
		];
	}

	protected function isVip( Member $member ): bool
	{
		try
		{
			$vipGroupIds = [];
			foreach ( \IPS\Member\Group::groups( TRUE, FALSE ) as $g )
			{
				if ( mb_stripos( (string) $g->name, 'VIP' ) !== FALSE )
				{
					$vipGroupIds[] = (int) $g->g_id;
				}
			}
			if ( \in_array( (int) $member->member_group_id, $vipGroupIds, true ) ) return true;
			$secondary = $member->mgroup_others ?? '';
			if ( $secondary )
			{
				foreach ( explode( ',', $secondary ) as $sg )
				{
					if ( \in_array( (int) trim( $sg ), $vipGroupIds, true ) ) return true;
				}
			}
		}
		catch ( \Throwable ) {}
		return false;
	}

	protected function manage(): void
	{
		$member = Member::loggedIn();
		$editId = (int) ( Request::i()->loadout_id ?? Request::i()->id ?? 0 );
		$suggestMode = (bool) ( Request::i()->suggest ?? 0 );
		$loadout = NULL;
		$items   = [];

		if ( $editId && $suggestMode )
		{
			try
			{
				$loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', $editId ] )->first();
			}
			catch ( \Throwable )
			{
				Output::i()->error( 'gdloadout_err_not_found', '2GL02/2', 404 );
				return;
			}

			$isOwner = (int) $member->member_id === (int) $loadout['member_id'];
			if ( $isOwner || !\IPS\gdloadout\Loadout\Loadout::canSuggest( $member, $loadout ) )
			{
				Output::i()->error( 'gdloadout_suggest_not_eligible', '2GL02/4', 403 );
				return;
			}

			foreach ( Db::i()->select( '*', 'gd_loadout_items', [ 'loadout_id=?', $editId ], 'sort_order ASC' ) as $item )
			{
				if ( !empty( $item['upc'] ) )
				{
					try
					{
						$cat = Db::i()->select( 'title, brand, image_url', 'gd_catalog', [ 'upc=?', $item['upc'] ] )->first();
						$item['title'] = $cat['title'] ?? '';
						$item['brand'] = $cat['brand'] ?? '';
						$item['image_url'] = $cat['image_url'] ?? '';
					}
					catch ( \UnderflowException ) { $item['title'] = ''; }
					try
					{
						$item['price_snapshot'] = (float) Db::i()->select( 'MIN(dealer_price)', 'gd_dealer_listings', [ 'upc=? AND listing_status=?', $item['upc'], 'active' ] )->first();
					}
					catch ( \Throwable ) { $item['price_snapshot'] = null; }
				}
				$items[] = $item;
			}
		}
		elseif ( $editId )
		{
			try
			{
				$loadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND member_id=?', $editId, (int) $member->member_id ] )->first();
				foreach ( Db::i()->select( '*', 'gd_loadout_items', [ 'loadout_id=?', $editId ], 'sort_order ASC' ) as $item )
				{
					if ( !empty( $item['upc'] ) )
					{
						try
						{
							$cat = Db::i()->select( 'title, brand, image_url', 'gd_catalog', [ 'upc=?', $item['upc'] ] )->first();
							$item['title'] = $cat['title'] ?? '';
							$item['brand'] = $cat['brand'] ?? '';
							$item['image_url'] = $cat['image_url'] ?? '';
						}
						catch ( \UnderflowException ) { $item['title'] = ''; }
						try
						{
							$item['price_snapshot'] = (float) Db::i()->select( 'MIN(dealer_price)', 'gd_dealer_listings', [ 'upc=? AND listing_status=?', $item['upc'], 'active' ] )->first();
						}
						catch ( \Throwable ) { $item['price_snapshot'] = null; }
					}
					$items[] = $item;
				}
			}
			catch ( \Throwable )
			{
				Output::i()->error( 'gdloadout_err_not_found', '2GL02/2', 404 );
				return;
			}
		}
		else
		{
			if ( !\IPS\gdloadout\Loadout\Limits::canCreateLoadout( $member ) )
			{
				Output::i()->error( 'gdloadout_err_limit_loadouts', '2GL02/3', 403 );
				return;
			}
		}

		/* v1.0.62 — enrich items with compliance flags for the
		   selected state. Every step is guarded: a missing
		   gdcompliance install, an empty flags table, or a corrupt
		   row must never break the builder. gd_compliance_flags
		   and gd_catalog remain READ-ONLY throughout. */
		$currentState = $this->currentComplianceState();
		$upcList = [];
		foreach ( $items as $it )
		{
			if ( !empty( $it['upc'] ) ) { $upcList[] = (string) $it['upc']; }
		}
		$flagsByUpc = $this->fetchComplianceFlags( $upcList );
		foreach ( $items as &$it )
		{
			$upc = (string) ( $it['upc'] ?? '' );
			$it  = $this->decorateItem( $it, $flagsByUpc[ $upc ] ?? [], $currentState );
			/* v1.0.64 — also attach a `compliance` sub-object with
			   the same shape search() returns on each result, so
			   builder.js can copy it directly into slots[key] and
			   render filled-slot badges without a second server
			   round-trip. */
			$it['compliance'] = $this->complianceForResult( $upc, $currentState );
		}
		unset( $it );
		$complianceSummary = $this->summarizeCompliance( $items );

		$limits  = \IPS\gdloadout\Loadout\Limits::forMember( $member );
		$isVip   = $this->isVip( $member );

		$saveUrl   = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=save', 'front', 'gdloadout_builder' );
		$deleteUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=delete', 'front', 'gdloadout_builder' );
		$searchUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=search', 'front', 'gdloadout_builder' );
		$submitSuggestionUrl = (string) Url::internal( 'app=gdloadout&module=loadouts&controller=hub&do=submitSuggestion', 'front' );
		$csrfKey   = Session::i()->csrfKey;

		$initData = json_encode( [
			'loadout'            => $loadout,
			'items'              => $items,
			'completeFirearmCore' => \IPS\gdloadout\Loadout\Slots::COMPLETE_FIREARM_CORE,
			'componentCore'      => \IPS\gdloadout\Loadout\Slots::COMPONENT_CORE,
			'accessorySlots'     => \IPS\gdloadout\Loadout\Slots::ACCESSORY_SLOTS,
			'slotCategories'     => \IPS\gdloadout\Loadout\Slots::SLOT_CATEGORIES,
			'platforms'          => \IPS\gdloadout\Loadout\Slots::PLATFORMS,
			'limits'             => $limits,
			'isVip'              => $isVip,
			'saveUrl'            => $saveUrl,
			'deleteUrl'          => $deleteUrl,
			'searchUrl'          => $searchUrl,
			'csrfKey'            => $csrfKey,
			'suggestMode'        => $suggestMode,
			'submitSuggestionUrl' => $submitSuggestionUrl,
			'lang_suggest_submitted_title' => Member::loggedIn()->language()->addToStack( 'gdloadout_suggest_submitted_title' ),
			'lang_suggest_submitted_desc'  => Member::loggedIn()->language()->addToStack( 'gdloadout_suggest_submitted_desc' ),
			/* v1.0.62 — expose compliance data to the JS builder
			   so a future client-side integration can render
			   badges as users add/remove items. Stage-1 render
			   is server-side (panelHtml below). */
			'complianceState'   => $currentState,
			'complianceSummary' => $complianceSummary,
		], JSON_HEX_TAG | JSON_HEX_AMP );

		Output::i()->cssFiles = array_merge( Output::i()->cssFiles, Theme::i()->css( 'loadouts.css', 'gdloadout', 'interface' ) );
		Output::i()->jsFiles  = array_merge( Output::i()->jsFiles, Output::i()->js( 'builder.js', 'gdloadout', 'interface' ) );
		Output::i()->title    = Member::loggedIn()->language()->addToStack( 'gdloadout_builder_title' );

		/* v1.0.62 — render the compliance panel ABOVE the JS
		   builder. Panel is a snapshot of the currently-loaded
		   items; the JS builder itself is left untouched, so
		   save / delete / search still work exactly as they did. */
		$compliancePanel = $this->renderCompliancePanel( $items, $complianceSummary, $currentState, $editId );

		Output::i()->output   = $compliancePanel
			. Theme::i()->getTemplate( 'loadouts', 'gdloadout', 'front' )->builder( $initData );
	}

	/**
	 * v1.0.62 — server-side compliance panel. Renders a state
	 * selector, a build-level summary, and per-item badges for
	 * the items currently loaded on the loadout. Scoped CSS with
	 * `.gdlc-` prefix so it can't clash with the JS builder's
	 * own DOM.
	 */
	private function renderCompliancePanel( array $items, array $summary, string $state, int $editId ): string
	{
		$esc  = fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$lang = Member::loggedIn()->language();
		$L    = fn( string $k ): string => $esc( (string) $lang->addToStack( $k ) );

		$stateName = $state !== '' ? ( self::COMPLIANCE_STATES[ $state ] ?? $state ) : '';

		$setUrl = Url::internal( 'app=gdloadout&module=loadouts&controller=builder&do=setComplianceState', 'front' );
		if ( $editId > 0 ) { $setUrl = $setUrl->setQueryString( 'loadout_id', $editId ); }
		$csrfKey = (string) Session::i()->csrfKey;

		$html = '<style>
.gdlc-panel{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;margin:0 auto 16px;max-width:1200px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a}
.gdlc-panel h2{margin:0 0 10px;font-size:1.05em;font-weight:700}
.gdlc-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
.gdlc-select{padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font:inherit;background:#fff;min-width:220px}
.gdlc-btn{padding:6px 12px;border-radius:6px;border:none;font-weight:600;cursor:pointer;background:#0f172a;color:#fff;font-family:inherit;font-size:.95em}
.gdlc-btn--muted{background:#f1f5f9;color:#0f172a}
.gdlc-sum{padding:10px 14px;border-radius:8px;font-size:.95em;margin-top:4px}
.gdlc-sum--danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.gdlc-sum--warn{background:#fef3c7;color:#78350f;border:1px solid #fde68a}
.gdlc-sum--ok{background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0}
.gdlc-sum--info{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0}
.gdlc-items{margin-top:14px;display:grid;gap:8px}
.gdlc-item{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff}
.gdlc-item--restricted{border-color:#fecaca;background:#fef7f7}
.gdlc-item--advisory{border-color:#fde68a;background:#fffdf5}
.gdlc-item-title{font-weight:600;font-size:.95em}
.gdlc-item-upc{color:#94a3b8;font-size:.8em;font-family:ui-monospace,monospace}
.gdlc-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
.gdlc-badge--restricted{background:#7f1d1d;color:#fee2e2}
.gdlc-badge--advisory{background:#78350f;color:#fef3c7}
.gdlc-badge--clear{background:#dcfce7;color:#14532d}
.gdlc-reason{margin-top:6px;color:#334155;font-size:.9em}
.gdlc-expand{margin-top:6px}
.gdlc-expand summary{cursor:pointer;color:#475569;font-size:.85em;list-style:none}
.gdlc-expand summary::-webkit-details-marker{display:none}
.gdlc-expand summary::before{content:"▸ ";color:#94a3b8}
.gdlc-expand[open] summary::before{content:"▾ "}
.gdlc-all{margin-top:6px;padding:8px 10px;background:#f8fafc;border-radius:6px;font-size:.85em}
.gdlc-all-row{padding:4px 0;border-top:1px dashed #e2e8f0}
.gdlc-all-row:first-child{border-top:none}
.gdlc-state-pill{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;letter-spacing:.03em;margin-right:6px;background:#e2e8f0;color:#334155}
</style>';

		$html .= '<div class="gdlc-panel">';
		$html .= '<h2>' . $L( 'gdloadout_compliance_title' ) . '</h2>';

		/* State selector */
		$html .= '<form class="gdlc-row" method="post" action="' . $esc( (string) $setUrl ) . '">';
		$html .= '<input type="hidden" name="csrfKey" value="' . $esc( $csrfKey ) . '">';
		$html .= '<label for="gdlc-state" style="font-weight:600">' . $L( 'gdloadout_compliance_state_label' ) . '</label>';
		$html .= '<select class="gdlc-select" id="gdlc-state" name="state">';
		$html .= '<option value="">' . $L( 'gdloadout_compliance_select_state' ) . '</option>';
		foreach ( self::COMPLIANCE_STATES as $code => $name )
		{
			$sel = ( $code === $state ) ? ' selected' : '';
			$html .= '<option value="' . $esc( $code ) . '"' . $sel . '>' . $esc( $name ) . '</option>';
		}
		$html .= '</select>';
		$html .= '<button type="submit" class="gdlc-btn">' . $L( 'gdloadout_compliance_apply' ) . '</button>';
		$html .= '</form>';

		/* Summary */
		if ( $summary['total'] === 0 )
		{
			$html .= '<div class="gdlc-sum gdlc-sum--info">' . $L( 'gdloadout_compliance_empty' ) . '</div>';
		}
		elseif ( $state === '' )
		{
			$html .= '<div class="gdlc-sum gdlc-sum--info">' . $L( 'gdloadout_compliance_pick_state' ) . '</div>';
		}
		elseif ( $summary['restricted'] > 0 )
		{
			$msg = str_replace(
				[ '{x}', '{total}', '{state}' ],
				[ (string) $summary['restricted'], (string) $summary['total'], $esc( $stateName ) ],
				(string) $lang->addToStack( 'gdloadout_compliance_summary_restricted' )
			);
			$html .= '<div class="gdlc-sum gdlc-sum--danger">' . $msg . '</div>';
		}
		elseif ( $summary['advisory'] > 0 )
		{
			$msg = str_replace(
				[ '{y}', '{state}' ],
				[ (string) $summary['advisory'], $esc( $stateName ) ],
				(string) $lang->addToStack( 'gdloadout_compliance_summary_advisory' )
			);
			$html .= '<div class="gdlc-sum gdlc-sum--warn">' . $msg . '</div>';
		}
		else
		{
			$msg = str_replace(
				'{state}', $esc( $stateName ),
				(string) $lang->addToStack( 'gdloadout_compliance_summary_clear' )
			);
			$html .= '<div class="gdlc-sum gdlc-sum--ok">' . $msg . '</div>';
		}

		/* v1.0.64 — per-item cards removed; badges now live on the
		   filled slot cards in the JS builder. Empty div left as
		   an anchor for the client-side compact summary (updated
		   by builder.js's updateAllSummaries()); the server-side
		   summary above stays as an initial-render fallback. */
		$html .= '<div id="gdlc-summary" style="margin-top:10px"></div>';

		$html .= '</div>';
		return $html;
	}

	private function renderComplianceItem( array $it, string $state, string $stateName, callable $esc, callable $L, $lang ): string
	{
		$upc     = (string) ( $it['upc'] ?? '' );
		$title   = (string) ( $it['title'] ?? '' );
		if ( $title === '' ) { $title = 'UPC ' . $upc; }
		$rHere   = !empty( $it['compliance_restricted_here'] );
		$aHere   = !empty( $it['compliance_advisory_here'] );
		$reason  = (string) ( $it['compliance_reason_here'] ?? '' );
		$all     = (array) ( $it['compliance_all_states'] ?? [] );
		$rCodes  = (array) ( $it['compliance_restricted_state_codes'] ?? [] );
		$rCount  = count( $rCodes );

		$cardClass = 'gdlc-item';
		if ( $rHere )      { $cardClass .= ' gdlc-item--restricted'; }
		elseif ( $aHere )  { $cardClass .= ' gdlc-item--advisory'; }

		$html  = '<div class="' . $cardClass . '"><div style="flex:1">';
		$html .= '<div class="gdlc-item-title">' . $esc( $title ) . '</div>';
		$html .= '<div class="gdlc-item-upc">UPC ' . $esc( $upc ) . '</div>';

		if ( $state === '' )
		{
			if ( $rCount > 0 )
			{
				$html .= '<div style="margin-top:6px;color:#475569;font-size:.9em">'
					. sprintf( (string) $lang->addToStack( 'gdloadout_compliance_item_pick_state' ), $rCount )
					. '</div>';
			}
			else
			{
				$html .= '<div style="margin-top:6px"><span class="gdlc-badge gdlc-badge--clear">' . $L( 'gdloadout_compliance_badge_clear' ) . '</span></div>';
			}
		}
		elseif ( $rHere )
		{
			$html .= '<div style="margin-top:6px"><span class="gdlc-badge gdlc-badge--restricted">⛔ ' . $L( 'gdloadout_compliance_badge_restricted' ) . ' — ' . $esc( $stateName ) . '</span></div>';
			if ( $reason !== '' )
			{
				$html .= '<div class="gdlc-reason">' . $esc( $reason ) . '</div>';
			}
		}
		elseif ( $aHere )
		{
			$html .= '<div style="margin-top:6px"><span class="gdlc-badge gdlc-badge--advisory">ⓘ ' . $L( 'gdloadout_compliance_badge_advisory' ) . ' — ' . $esc( $stateName ) . '</span></div>';
			if ( $reason !== '' )
			{
				$html .= '<div class="gdlc-reason">' . $esc( $reason ) . '</div>';
			}
		}
		else
		{
			$html .= '<div style="margin-top:6px"><span class="gdlc-badge gdlc-badge--clear">✓ ' . $L( 'gdloadout_compliance_badge_clear_here' ) . '</span></div>';
			if ( $rCount > 0 )
			{
				$html .= '<div style="margin-top:6px;color:#64748b;font-size:.85em">'
					. sprintf( (string) $lang->addToStack( 'gdloadout_compliance_item_other_states' ), $rCount )
					. '</div>';
			}
		}

		if ( !empty( $all ) )
		{
			$html .= '<details class="gdlc-expand"><summary>' . $L( 'gdloadout_compliance_expand_all' ) . '</summary>';
			$html .= '<div class="gdlc-all">';
			foreach ( $all as $flag )
			{
				$sc = (string) ( $flag['state'] ?? '' );
				$nm = (string) ( $flag['state_name'] ?? $sc );
				$rz = (string) ( $flag['reason'] ?? '' );
				$ct = (string) ( $flag['citation'] ?? '' );
				$ty = (string) ( $flag['type'] ?? '' );
				$html .= '<div class="gdlc-all-row">'
					. '<span class="gdlc-state-pill">' . $esc( $sc ) . '</span>'
					. '<strong>' . $esc( $nm ) . '</strong>'
					. ' — <em>' . ( $ty === 'advisory' ? $L( 'gdloadout_compliance_all_advisory' ) : $L( 'gdloadout_compliance_all_restricted' ) ) . '</em>';
				if ( $rz !== '' ) { $html .= '<div style="margin-top:2px">' . $esc( $rz ) . '</div>'; }
				if ( $ct !== '' ) { $html .= '<div style="color:#64748b;font-size:.85em">' . $esc( $ct ) . '</div>'; }
				$html .= '</div>';
			}
			$html .= '</div></details>';
		}

		$html .= '</div></div>';
		return $html;
	}

	protected function save(): void
	{
		Session::i()->csrfCheck();

		$member = Member::loggedIn();
		$editId = (int) ( Request::i()->loadout_id ?? 0 );
		$name   = trim( Request::i()->loadout_name ?? '' );

		if ( $name === '' )
		{
			Output::i()->json( [ 'error' => Member::loggedIn()->language()->addToStack( 'gdloadout_err_name_required' ) ], 400 );
			return;
		}

		$slug        = \IPS\gdloadout\Loadout\Loadout::slugify( $name );
		$description = trim( Request::i()->loadout_description ?? '' );
		$useCase     = trim( Request::i()->loadout_use_case ?? '' );
		$visibility  = Request::i()->loadout_visibility ?? 'unlisted';

		$buildModeParam = trim( Request::i()->loadout_build_mode ?? 'complete_firearm' );
		if ( !\in_array( $buildModeParam, [ 'complete_firearm', 'component_build' ], true ) )
		{
			$buildModeParam = 'complete_firearm';
		}

		$platformParam = trim( Request::i()->loadout_platform ?? '' );
		$validPlatforms = array_keys( \IPS\gdloadout\Loadout\Slots::PLATFORMS );
		if ( $platformParam !== '' && !\in_array( $platformParam, $validPlatforms, true ) )
		{
			$platformParam = '';
		}

		if ( !\in_array( $visibility, [ 'public', 'unlisted', 'private' ], true ) )
		{
			$visibility = 'unlisted';
		}

		$isVip = $this->isVip( $member );
		if ( $visibility === 'private' && !$isVip )
		{
			$visibility = 'unlisted';
		}

		$slotsJson = Request::i()->loadout_slots ?? '[]';
		$slots     = json_decode( $slotsJson, true );
		if ( !\is_array( $slots ) ) $slots = [];

		$limits = \IPS\gdloadout\Loadout\Limits::forMember( $member );
		if ( $limits['max_slots'] > 0 && \count( $slots ) > $limits['max_slots'] )
		{
			Output::i()->json( [ 'error' => Member::loggedIn()->language()->addToStack( 'gdloadout_err_limit_slots' ) ], 400 );
			return;
		}

		if ( $editId )
		{
			try
			{
				$existing = Db::i()->select( '*', 'gd_loadouts', [ 'id=? AND member_id=?', $editId, (int) $member->member_id ] )->first();
			}
			catch ( \Throwable )
			{
				Output::i()->json( [ 'error' => Member::loggedIn()->language()->addToStack( 'gdloadout_err_not_found' ) ], 404 );
				return;
			}

			$slotsWithUpc = 0;
			foreach ( $slots as $s )
			{
				if ( !empty( $s['upc'] ) ) $slotsWithUpc++;
			}
			if ( $slotsWithUpc === 0 )
			{
				Output::i()->json( [ 'error' => 'No items to save — cannot overwrite existing build with empty loadout.' ], 400 );
				return;
			}

			$uniqueSlug = $slug;
			$counter = 1;
			while ( true )
			{
				try
				{
					Db::i()->select( 'id', 'gd_loadouts', [ 'member_id=? AND slug=? AND id!=?', (int) $member->member_id, $uniqueSlug, $editId ] )->first();
					$counter++;
					$uniqueSlug = $slug . '-' . $counter;
				}
				catch ( \Throwable ) { break; }
			}

			Db::i()->update( 'gd_loadouts', [
				'name' => $name, 'slug' => $uniqueSlug,
				'description' => $description ?: NULL, 'use_case' => $useCase ?: NULL,
				'visibility' => $visibility, 'build_mode' => $buildModeParam,
				'platform' => $platformParam ?: NULL, 'updated_at' => time(),
			], [ 'id=?', $editId ] );

			$loadoutId = $editId;

			if ( \in_array( $visibility, [ 'public', 'unlisted' ], true ) )
			{
				try
				{
					$followers = [];
					foreach ( Db::i()->select( 'member_id', 'gd_loadout_follows', [ 'loadout_id=?', $editId ] ) as $fid )
					{
						if ( (int) $fid !== (int) $member->member_id ) $followers[] = (int) $fid;
					}
					if ( $followers )
					{
						$notification = new \IPS\Notification(
							\IPS\Application::load( 'gdloadout' ), 'loadout_updated', $member, [],
							[ 'loadout_name' => $name, 'author_name' => $member->name, 'username' => $member->name, 'slug' => $uniqueSlug ]
						);
						foreach ( $followers as $fMemberId )
						{
							try { $notification->recipients->attach( Member::load( $fMemberId ) ); } catch ( \Throwable ) {}
						}
						$notification->send();
					}
				}
				catch ( \Throwable ) {}
			}

			Db::i()->delete( 'gd_loadout_items', [ 'loadout_id=?', $loadoutId ] );
		}
		else
		{
			if ( !\IPS\gdloadout\Loadout\Limits::canCreateLoadout( $member ) )
			{
				Output::i()->json( [ 'error' => Member::loggedIn()->language()->addToStack( 'gdloadout_err_limit_loadouts' ) ], 403 );
				return;
			}

			$uniqueSlug = $slug;
			$counter = 1;
			while ( true )
			{
				try
				{
					Db::i()->select( 'id', 'gd_loadouts', [ 'member_id=? AND slug=?', (int) $member->member_id, $uniqueSlug ] )->first();
					$counter++;
					$uniqueSlug = $slug . '-' . $counter;
				}
				catch ( \Throwable ) { break; }
			}

			$loadoutId = Db::i()->insert( 'gd_loadouts', [
				'member_id' => (int) $member->member_id, 'name' => $name, 'slug' => $uniqueSlug,
				'description' => $description ?: NULL, 'use_case' => $useCase ?: NULL,
				'visibility' => $visibility, 'build_mode' => $buildModeParam,
				'platform' => $platformParam ?: NULL, 'created_at' => time(),
			] );
		}

		$completeSlots  = [ 'base_firearm', 'optic', 'weapon_light', 'laser', 'suppressor', 'sling', 'rail_mount', 'scope_rings' ];
		$componentSlots = [ 'lower_receiver', 'upper_receiver', 'barrel', 'handguard', 'muzzle', 'bcg', 'buffer', 'trigger', 'stock', 'grip', 'optic', 'scope_rings', 'rail_mount', 'weapon_light', 'laser', 'suppressor', 'sling' ];
		$extraSlots     = [ 'magazine', 'holster', 'ear_eye_pro', 'cleaning', 'bipod', 'extra' ];
		$allowedForMode = array_merge(
			( $buildModeParam === 'component_build' ) ? $componentSlots : $completeSlots,
			$extraSlots
		);
		$totalCost = 0; $totalItems = 0; $order = 0;

		foreach ( $slots as $slot )
		{
			if ( empty( $slot['upc'] ) ) continue;
			$slotType = $slot['slot_type'] ?? 'extra';
			if ( !\in_array( $slotType, $allowedForMode, true ) ) continue;

			$notes = NULL;
			if ( $isVip && !empty( $slot['notes'] ) ) $notes = substr( trim( $slot['notes'] ), 0, 300 );

			Db::i()->insert( 'gd_loadout_items', [
				'loadout_id' => (int) $loadoutId, 'upc' => substr( trim( $slot['upc'] ), 0, 20 ),
				'slot_type' => $slotType, 'custom_label' => !empty( $slot['custom_label'] ) ? substr( trim( $slot['custom_label'] ), 0, 100 ) : NULL,
				'sort_order' => $order, 'notes' => $notes, 'added_at' => time(),
			] );
			$totalItems++; $order++;
			if ( isset( $slot['price'] ) && (float) $slot['price'] > 0 ) $totalCost += (float) $slot['price'];
		}

		Db::i()->update( 'gd_loadouts', [
			'total_items' => $totalItems,
			'total_min_price' => $totalCost > 0 ? round( $totalCost, 2 ) : NULL,
		], [ 'id=?', (int) $loadoutId ] );

		if ( \in_array( $visibility, [ 'public', 'unlisted' ], true ) )
		{
			try
			{
				$savedRow = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', (int) $loadoutId ] )->first();
				_hub::ensureForumTopic( $savedRow );
			}
			catch ( \Throwable ) {}
		}

		$acceptSugId = (int) ( Request::i()->accept_suggestion_id ?? 0 );
		if ( $acceptSugId > 0 )
		{
			try
			{
				$sug = Db::i()->select( '*', 'gd_loadout_suggestions', [ 'id=? AND loadout_id=? AND status=?', $acceptSugId, (int) $loadoutId, 'pending' ] )->first();
				Db::i()->update( 'gd_loadout_suggestions', [ 'status' => 'accepted', 'resolved_at' => time() ], [ 'id=?', $acceptSugId ] );

				try
				{
					$suggester = Member::load( (int) $sug['from_member'] );
					if ( $suggester->member_id )
					{
						$savedLoadout = Db::i()->select( '*', 'gd_loadouts', [ 'id=?', (int) $loadoutId ] )->first();
						ob_start();
						try
						{
							$notification = new \IPS\Notification(
								\IPS\Application::load( 'gdloadout' ), 'suggestion_resolved', $suggester, [ $suggester ],
								[ 'loadout_name' => $savedLoadout['name'] ?? '', 'action' => 'accepted', 'username' => $member->name ?? 'Unknown', 'slug' => $savedLoadout['slug'] ?? '' ]
							);
							$notification->recipients->attach( $suggester );
							$notification->send();
						}
						catch ( \Throwable ) {}
						ob_end_clean();
					}
				}
				catch ( \Throwable ) {}
			}
			catch ( \Throwable ) {}
		}

		$ownerName = $member->name ?? 'user';
		$loadoutSlug = $uniqueSlug ?? $slug;
		$viewUrl = (string) Url::internal(
			'app=gdloadout&module=loadouts&controller=hub&do=view&username=' . urlencode( $ownerName ) . '&slug=' . urlencode( $loadoutSlug ),
			'front', 'gdloadout_view'
		);

		Output::i()->json( [ 'ok' => true, 'loadout_id' => (int) $loadoutId, 'redirect' => $viewUrl ] );
	}

	protected function delete(): void
	{
		Session::i()->csrfCheck();
		$member = Member::loggedIn();
		$id = (int) ( Request::i()->loadout_id ?? 0 );

		try { Db::i()->select( 'id', 'gd_loadouts', [ 'id=? AND member_id=?', $id, (int) $member->member_id ] )->first(); }
		catch ( \Throwable ) { Output::i()->json( [ 'error' => Member::loggedIn()->language()->addToStack( 'gdloadout_err_not_found' ) ], 404 ); return; }

		Db::i()->delete( 'gd_loadout_items', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadout_votes', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadout_follows', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadout_forum_posts', [ 'loadout_id=?', $id ] );
		Db::i()->delete( 'gd_loadouts', [ 'id=?', $id ] );
		Output::i()->json( [ 'ok' => true ] );
	}

	/**
	 * v1.0.63 — compact compliance decorator for search-result JSON.
	 * One SELECT per UPC (batched by the caller wouldn't help — each
	 * result row is already looked up individually via other queries
	 * in search()), guarded so gdcompliance being missing or the
	 * flags table being locked cannot ever fail a search. Returns a
	 * `compliance` sub-array that builder.js reads to render the
	 * real-time badge:
	 *   state            — buyer's currently-selected state code
	 *   restricted_here  — bool, non-advisory flag matching state
	 *   advisory_here    — bool, advisory flag matching state
	 *   reason_here      — reason text for the state (restrictions win)
	 *   restricted_count — count of DISTINCT restricted states
	 *   restricted_codes — those distinct state codes
	 */
	private function complianceForResult( string $upc, string $state ): array
	{
		$out = [
			'state'            => $state,
			'restricted_here'  => false,
			'advisory_here'    => false,
			'reason_here'      => '',
			'restricted_count' => 0,
			'restricted_codes' => [],
		];
		if ( $upc === '' ) { return $out; }

		$codes = [];
		try
		{
			foreach ( Db::i()->select(
				'state_code, firearm_type, reason',
				'gd_compliance_flags',
				[ 'upc=?', $upc ]
			) as $f )
			{
				$sc         = strtoupper( (string) ( $f['state_code'] ?? '' ) );
				$isAdvisory = ( ( $f['firearm_type'] ?? '' ) === 'advisory' );

				if ( !$isAdvisory && $sc !== '' ) { $codes[ $sc ] = true; }

				if ( $state !== '' && $sc === $state )
				{
					if ( $isAdvisory )
					{
						$out['advisory_here'] = true;
					}
					else
					{
						$out['restricted_here'] = true;
						$out['reason_here']     = (string) ( $f['reason'] ?? '' );
					}
				}
			}
		}
		catch ( \Throwable ) { /* gdcompliance optional — silent */ }

		$out['restricted_codes'] = array_keys( $codes );
		$out['restricted_count'] = count( $out['restricted_codes'] );
		return $out;
	}

	protected function search(): void
	{
		/* v1.0.63 — buyer's persisted state (cookie set by
		   setComplianceState). Empty when they haven't picked one
		   yet — search() still runs; each result carries an empty
		   `compliance.state` and builder.js can prompt to pick. */
		$complianceState = $this->currentComplianceState();

		$query = trim( (string) ( Request::i()->q ?? '' ) );
		$page  = max( 1, (int) ( Request::i()->page ?? 1 ) );

		$categoryParam = Request::i()->category ?? '';
		$cats = [];
		if ( \is_array( $categoryParam ) )
		{
			$cats = array_values( array_filter( array_map( 'trim', $categoryParam ) ) );
		}
		elseif ( (string) $categoryParam !== '' )
		{
			if ( strpos( (string) $categoryParam, ',' ) !== false )
			{
				$cats = array_values( array_filter( array_map( 'trim', explode( ',', (string) $categoryParam ) ) ) );
			}
			else
			{
				$cats = [ trim( (string) $categoryParam ) ];
			}
		}

		if ( mb_strlen( $query ) < 2 && empty( $cats ) )
		{
			Output::i()->json( [ 'total' => 0, 'results' => [] ] );
			return;
		}

		try
		{
			$matchedUpcs = [];

			if ( $query !== '' && preg_match( '/^[0-9]{8,14}$/', $query ) )
			{
				try
				{
					$u = (string) Db::i()->select( 'upc', 'gd_catalog', [ 'upc=? AND record_status=?', $query, 'active' ] )->first();
					if ( $u !== '' ) $matchedUpcs[] = $u;
				}
				catch ( \UnderflowException ) {}
			}

			if ( !$matchedUpcs && $query !== '' )
			{
				try
				{
					foreach ( Db::i()->select( 'upc', 'gd_catalog', [ 'mpn=? AND record_status=?', $query, 'active' ], 'id ASC', 24 ) as $u )
					{
						$matchedUpcs[] = (string) $u;
					}
				}
				catch ( \Throwable ) {}
			}

			if ( $matchedUpcs )
			{
				$out = [];
				foreach ( $matchedUpcs as $u )
				{
					try
					{
						$row = Db::i()->select( '*', 'gd_catalog', [ 'upc=?', $u ] )->first();
						$price = NULL; $dealers = 0;
						try
						{
							$p = Db::i()->select( 'MIN(dealer_price) AS best_price, COUNT(DISTINCT dealer_id) AS dealer_count', 'gd_dealer_listings', [ 'upc=? AND listing_status=?', $u, 'active' ] )->first();
							$price   = ( $p['best_price'] !== NULL && (float) $p['best_price'] > 0 ) ? (float) $p['best_price'] : NULL;
							$dealers = (int) $p['dealer_count'];
						}
						catch ( \Throwable ) {}
						$out[] = [
							'upc' => $u, 'title' => $row['title'] ?? '', 'brand' => $row['brand'] ?? '',
							'best_price' => $price, 'dealer_count' => $dealers, 'in_stock' => $dealers > 0,
							'category' => $row['category'] ?? '', 'caliber' => $row['caliber'] ?? '',
							'image_url' => (string) ( $row['image_url'] ?? '' ),
							'compliance' => $this->complianceForResult( (string) $u, $complianceState ),
						];
					}
					catch ( \Throwable ) {}
				}
				Output::i()->json( [ 'total' => \count( $out ), 'results' => $out ] );
				return;
			}

			$arr = function ( $v ) {
				if ( \is_array( $v ) ) { return array_values( array_filter( array_map( fn( $x ) => trim( (string) $x ), $v ), fn( $x ) => $x !== '' ) ); }
				$v = trim( (string) $v );
				return $v !== '' ? [ $v ] : [];
			};

			$filters = [];
			if ( $cats )
			{
				$catField = ( Request::i()->catfield ?? '' ) === 'subcategory' ? 'subcategory' : 'category';
				$filters[ $catField ] = \count( $cats ) === 1 ? $cats[0] : $cats;
			}

			foreach ( [
				'brand', 'caliber', 'action', 'casing', 'bullet_type', 'capacity',
				'holster_type', 'holster_color', 'holster_material', 'holster_hand',
				'apparel_pattern', 'apparel_size', 'apparel_material',
				'blade_shape', 'blade_length', 'blade_material', 'blade_edge', 'knife_handle',
				'hunt_call_type', 'hunt_game', 'optic_magnification', 'optic_objective',
			] as $facetKey )
			{
				$val = $arr( Request::i()->$facetKey ?? [] );
				if ( $val ) { $filters[ $facetKey ] = $val; }
			}

			if ( !empty( Request::i()->in_stock ) )  { $filters['in_stock'] = true; }
			$minPrice = (float) ( Request::i()->min_price ?? 0 );
			$maxPrice = (float) ( Request::i()->max_price ?? 0 );
			if ( $minPrice > 0 ) { $filters['min_price'] = $minPrice; }
			if ( $maxPrice > 0 ) { $filters['max_price'] = $maxPrice; }

			$sortParam = trim( (string) ( Request::i()->sort ?? 'relevance' ) );
			$allowedSorts = [ 'relevance', 'brand' ];
			$sort = \in_array( $sortParam, $allowedSorts, true ) ? $sortParam : 'relevance';

			$searcher = new \IPS\gdsearch\Search\Searcher();
			$result   = $searcher->search( $query, $filters, $sort, $page, 24 );

			$aggs = $result['aggregations'] ?? [];
			foreach ( [ 'calibers', 'actions', 'capacities', 'casings', 'bullet_types',
				'holster_types', 'holster_colors', 'holster_materials', 'holster_hands',
				'apparel_patterns', 'apparel_sizes', 'apparel_materials',
				'blade_shapes', 'blade_lengths', 'blade_materials', 'blade_edges', 'knife_handles',
				'hunt_call_types', 'hunt_games', 'optics_mags', 'optics_objs' ] as $scopedAgg )
			{
				if ( isset( $aggs[ $scopedAgg ]['values']['buckets'] ) )
				{
					$aggs[ $scopedAgg ]['buckets'] = $aggs[ $scopedAgg ]['values']['buckets'];
				}
			}

			foreach ( [ 'brands', 'calibers', 'actions', 'capacities', 'casings', 'bullet_types',
				'holster_types', 'holster_colors', 'holster_materials', 'holster_hands',
				'apparel_patterns', 'apparel_sizes', 'apparel_materials',
				'blade_shapes', 'blade_lengths', 'blade_materials', 'blade_edges', 'knife_handles',
				'hunt_call_types', 'hunt_games', 'optics_mags', 'optics_objs' ] as $aggKey )
			{
				if ( isset( $aggs[ $aggKey ]['buckets'] ) && \is_array( $aggs[ $aggKey ]['buckets'] ) )
				{
					$aggs[ $aggKey ]['buckets'] = array_values( array_filter(
						$aggs[ $aggKey ]['buckets'],
						static function ( $b ) { return trim( (string) ( $b['key'] ?? '' ) ) !== ''; }
					) );
					if ( empty( $aggs[ $aggKey ]['buckets'] ) ) { unset( $aggs[ $aggKey ] ); }
				}
				else
				{
					unset( $aggs[ $aggKey ] );
				}
			}
			unset( $aggs['categories'] );

			$cleanAggs = [];
			foreach ( $aggs as $k => $v )
			{
				if ( !empty( $v['buckets'] ) ) { $cleanAggs[ $k ] = [ 'buckets' => $v['buckets'] ]; }
			}

			$out = [];
			foreach ( ( $result['results'] ?? [] ) as $r )
			{
				$img = ''; $catTitle = ''; $catBrand = '';
				if ( !empty( $r['upc'] ) )
				{
					try {
						$cat = Db::i()->select( 'title, brand, image_url', 'gd_catalog', [ 'upc=?', $r['upc'] ] )->first();
						$catTitle = (string) ( $cat['title'] ?? '' );
						$catBrand = (string) ( $cat['brand'] ?? '' );
						$img      = (string) ( $cat['image_url'] ?? '' );
					} catch ( \Throwable ) {}
				}
				$out[] = [
					'upc'         => $r['upc'] ?? '',
					'title'       => $catTitle !== '' ? $catTitle : ( $r['title'] ?? '' ),
					'brand'       => $catBrand !== '' ? $catBrand : ( $r['brand'] ?? '' ),
					'best_price'  => $r['best_price'] ?? null,
					'dealer_count'=> $r['dealer_count'] ?? 0,
					'in_stock'    => $r['in_stock'] ?? false,
					'category'    => $r['category'] ?? '',
					'caliber'     => $r['caliber'] ?? '',
					'image_url'   => $img,
					'compliance'  => $this->complianceForResult( (string) ( $r['upc'] ?? '' ), $complianceState ),
				];
			}
			Output::i()->json( [ 'total' => $result['total'] ?? 0, 'results' => $out, 'aggregations' => $cleanAggs ] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( $e, 'gdloadout_search' ); } catch ( \Throwable ) {}
			Output::i()->json( [ 'total' => 0, 'results' => [] ] );
		}
	}
}

class builder extends _builder {}
