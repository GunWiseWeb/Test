<?php
/**
 * @brief  GD Compliance — Public State Compliance Lookup (v1.6.22 Stage 1)
 *
 * gdcompliance's FIRST public/front module. A visitor picks their state
 * and enters a UPC or MPN; the page shows whether that item is
 * restricted / carries an advisory / has no flags for that state.
 * Read-only over gd_compliance_flags — no writes, no member state.
 *
 * FURL: /state-lookup/ (registered in data/furl.json).
 *
 * NO ACP permission check — this is publicly viewable (guest-friendly).
 * Any state-changing action (report button, etc.) will be gated with
 * CSRF at the point of the POST in later stages. This stage is
 * server-rendered GET / POST-back with no JS complexity.
 *
 * Stage plan:
 *   Stage 1 (THIS): basic lookup + editable disclaimer.
 *   Stage 2 (later): "report a mistake" button → review queue.
 *   Stage 3 (later): advanced search / full-state banned-list pull.
 * Keep this file clean and self-contained so stages 2-3 layer on.
 */

namespace IPS\gdcompliance\modules\front\lookup;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _lookup extends \IPS\Dispatcher\Controller
{
	/** Full 50 states + DC. Ordered alphabetically by name for the picker. */
	const STATE_NAMES = [
		'AL' => 'Alabama',       'AK' => 'Alaska',       'AZ' => 'Arizona',       'AR' => 'Arkansas',
		'CA' => 'California',    'CO' => 'Colorado',     'CT' => 'Connecticut',   'DE' => 'Delaware',
		'DC' => 'District of Columbia',
		'FL' => 'Florida',       'GA' => 'Georgia',      'HI' => 'Hawaii',        'ID' => 'Idaho',
		'IL' => 'Illinois',      'IN' => 'Indiana',      'IA' => 'Iowa',          'KS' => 'Kansas',
		'KY' => 'Kentucky',      'LA' => 'Louisiana',    'ME' => 'Maine',         'MD' => 'Maryland',
		'MA' => 'Massachusetts', 'MI' => 'Michigan',     'MN' => 'Minnesota',     'MS' => 'Mississippi',
		'MO' => 'Missouri',      'MT' => 'Montana',      'NE' => 'Nebraska',      'NV' => 'Nevada',
		'NH' => 'New Hampshire', 'NJ' => 'New Jersey',   'NM' => 'New Mexico',    'NY' => 'New York',
		'NC' => 'North Carolina','ND' => 'North Dakota', 'OH' => 'Ohio',          'OK' => 'Oklahoma',
		'OR' => 'Oregon',        'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',  'SC' => 'South Carolina',
		'SD' => 'South Dakota',  'TN' => 'Tennessee',    'TX' => 'Texas',         'UT' => 'Utah',
		'VT' => 'Vermont',       'VA' => 'Virginia',     'WA' => 'Washington',    'WV' => 'West Virginia',
		'WI' => 'Wisconsin',     'WY' => 'Wyoming',
	];

	/**
	 * NOTE: publicly viewable — no ACP permission check. The public
	 * lookup runs read-only against gd_compliance_flags; it never
	 * writes anything.
	 */
	public function execute(): void
	{
		parent::execute();
	}

	protected function manage(): void
	{
		/* Feature toggle. When disabled, render a friendly notice
		   instead of 404 — this is a public URL a state may bookmark. */
		$enabled = (int) ( \IPS\Settings::i()->gdcompliance_lookup_enabled ?? 1 ) === 1;
		if ( !$enabled )
		{
			$this->renderDisabled();
			return;
		}

		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		/* Inputs. Alphanumerics + a small allowed punctuation set — a
		   UPC has hyphens on some SKUs; an MPN can carry alphanumerics
		   and hyphens. Reject empty; cap length. */
		$stateSel = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( !isset( self::STATE_NAMES[ $stateSel ] ) ) { $stateSel = ''; }

		$rawQ = trim( (string) ( \IPS\Request::i()->q ?? '' ) );
		$q    = substr( $rawQ, 0, 64 );
		if ( $q !== '' && !preg_match( '/^[A-Za-z0-9\-\._\/ ]+$/', $q ) )
		{
			$q = '';
		}

		$submitted = ( $stateSel !== '' && $q !== '' );

		$resultHtml = '';
		if ( $submitted )
		{
			$resultHtml = $this->buildResult( $stateSel, $q );
		}

		/* Render the page. Server-side, no inline JS $-vars per the
		   IPS template-interpolation gotcha (rule #46 in CLAUDE.md).
		   All strings are h()-escaped. */
		$title = $lang->addToStack( 'gdcompliance_lookup_page_title' );
		\IPS\Output::i()->title       = (string) $title;
		\IPS\Output::i()->breadcrumb  = [];
		\IPS\Output::i()->sidebar     = [ 'enabled' => false ];

		$formUrl = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=lookup&controller=lookup',
			'front', 'gdcompliance_state_lookup'
		);

		$disclaimer = trim( (string) ( \IPS\Settings::i()->gdcompliance_lookup_disclaimer ?? '' ) );
		if ( $disclaimer === '' )
		{
			$disclaimer = $lang->addToStack( 'gdcompliance_lookup_default_disclaimer' );
		}

		$stateOpts = '<option value="">' . $h( $lang->addToStack( 'gdcompliance_lookup_pick_state' ) ) . '</option>';
		$sorted    = self::STATE_NAMES;
		asort( $sorted, SORT_NATURAL | SORT_FLAG_CASE );
		foreach ( $sorted as $code => $name )
		{
			$sel = $stateSel === $code ? ' selected' : '';
			$stateOpts .= '<option value="' . $h( $code ) . '"' . $sel . '>' . $h( $name ) . '</option>';
		}

		$html = ''
			. '<style>'
			. '.gdcl-wrap{max-width:760px;margin:24px auto;padding:0 16px;font-family:\'Inter\',system-ui,-apple-system,sans-serif;color:#0f172a}'
			. '.gdcl-hero{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;margin-bottom:18px;box-shadow:0 2px 10px rgba(15,23,42,.04)}'
			. '.gdcl-hero h1{margin:0 0 6px;font-size:1.5em;font-weight:700;color:#0f172a}'
			. '.gdcl-hero p{margin:0;color:#475569;font-size:.95em;line-height:1.5}'
			. '.gdcl-disclaimer{background:#fefce8;border:1px solid #facc15;color:#713f12;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:.9em;line-height:1.5}'
			. '.gdcl-disclaimer strong{color:#854d0e}'
			. '.gdcl-form{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin-bottom:18px}'
			. '.gdcl-form label{display:block;margin:0 0 4px;font-size:.82em;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.04em}'
			. '.gdcl-form select,.gdcl-form input[type=text]{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:1em;background:#fff;color:#0f172a}'
			. '.gdcl-form select:focus,.gdcl-form input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}'
			. '.gdcl-row{margin-bottom:12px}'
			. '.gdcl-submit{display:inline-block;background:#1e40af;color:#fff;border:none;font-weight:600;padding:11px 22px;border-radius:8px;cursor:pointer;font-size:.95em}'
			. '.gdcl-submit:hover{background:#1e3a8a}'
			. '.gdcl-result{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin-bottom:18px}'
			. '.gdcl-result h2{margin:0 0 6px;font-size:1.15em;font-weight:700}'
			. '.gdcl-result .title{margin:6px 0 12px;color:#334155;font-size:.9em}'
			. '.gdcl-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.72em;font-weight:700;text-transform:uppercase;letter-spacing:.04em;vertical-align:middle;margin-right:8px}'
			. '.gdcl-badge.restrict{background:#fee2e2;color:#991b1b}'
			. '.gdcl-badge.advisory{background:#fef3c7;color:#78350f}'
			. '.gdcl-badge.clear{background:#dcfce7;color:#065f46}'
			. '.gdcl-badge.info{background:#e0e7ff;color:#3730a3}'
			. '.gdcl-restrict{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:10px;margin-bottom:10px}'
			. '.gdcl-restrict .reason{margin:6px 0 0;color:#7f1d1d;font-size:.95em;line-height:1.5}'
			. '.gdcl-restrict .cite{margin:6px 0 0;font-size:.85em;color:#7f1d1d;opacity:.85}'
			. '.gdcl-advisory{background:#fefce8;border:1px solid #fde68a;color:#78350f;padding:12px 14px;border-radius:10px;margin-bottom:10px}'
			. '.gdcl-advisory .reason{margin:6px 0 0;font-size:.95em;line-height:1.5}'
			. '.gdcl-clear{background:#f0fdf4;border:1px solid #86efac;color:#065f46;padding:14px;border-radius:10px}'
			. '.gdcl-notfound{background:#f1f5f9;border:1px solid #cbd5e1;color:#334155;padding:14px;border-radius:10px}'
			. '.gdcl-muted{color:#64748b;font-size:.85em}'
			. '</style>'

			. '<div class="gdcl-wrap">'
			. '<div class="gdcl-hero">'
			. '<h1>' . $h( $title ) . '</h1>'
			. '<p>' . $h( $lang->addToStack( 'gdcompliance_lookup_intro' ) ) . '</p>'
			. '</div>'

			. '<div class="gdcl-disclaimer"><strong>' . $h( $lang->addToStack( 'gdcompliance_lookup_disclaimer_label' ) ) . '</strong><br>'
			. nl2br( $h( $disclaimer ) )
			. '</div>'

			. '<form method="get" action="' . $h( $formUrl ) . '" class="gdcl-form">'
			. '<div class="gdcl-row">'
			. '<label for="gdcl-state">' . $h( $lang->addToStack( 'gdcompliance_lookup_field_state' ) ) . '</label>'
			. '<select id="gdcl-state" name="state" required>' . $stateOpts . '</select>'
			. '</div>'
			. '<div class="gdcl-row">'
			. '<label for="gdcl-q">' . $h( $lang->addToStack( 'gdcompliance_lookup_field_q' ) ) . '</label>'
			. '<input type="text" id="gdcl-q" name="q" value="' . $h( $q ) . '" placeholder="' . $h( $lang->addToStack( 'gdcompliance_lookup_field_q_ph' ) ) . '" maxlength="64" required>'
			. '</div>'
			. '<button type="submit" class="gdcl-submit">' . $h( $lang->addToStack( 'gdcompliance_lookup_submit' ) ) . '</button>'
			. '</form>'

			. $resultHtml
			. '</div>';

		\IPS\Output::i()->output = $html;
	}

	/**
	 * Resolve the product + build the result block for one query.
	 *
	 * Query flow:
	 *   1. gd_catalog WHERE upc = q     (UPC first)
	 *   2. gd_catalog WHERE mpn = q     (MPN fallback)
	 *   3. gd_compliance_flags WHERE upc=? AND state_code=?
	 * Never fabricate — unknown → "not in our catalog."
	 */
	protected function buildResult( string $stateCode, string $q ): string
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$stateName = self::STATE_NAMES[ $stateCode ] ?? $stateCode;

		/* --- 1) UPC match first --- */
		$product = null;
		try
		{
			$product = \IPS\Db::i()->select(
				'upc, title, brand, mpn, caliber, category_id',
				'gd_catalog',
				[ 'upc=?', $q ]
			)->first();
		}
		catch ( \Throwable ) { $product = null; }

		/* --- 2) MPN fallback --- */
		if ( !is_array( $product ) )
		{
			try
			{
				$product = \IPS\Db::i()->select(
					'upc, title, brand, mpn, caliber, category_id',
					'gd_catalog',
					[ 'mpn=?', $q ]
				)->first();
			}
			catch ( \Throwable ) { $product = null; }
		}

		if ( !is_array( $product ) )
		{
			return '<div class="gdcl-result">'
				. '<h2><span class="gdcl-badge info">Not in catalog</span></h2>'
				. '<p>' . $h( (string) $lang->addToStack(
					'gdcompliance_lookup_not_found', FALSE, [ 'sprintf' => [ $q ] ]
				) ) . '</p>'
				. '<p class="gdcl-muted">' . $h( $lang->addToStack( 'gdcompliance_lookup_not_found_hint' ) ) . '</p>'
				. '</div>';
		}

		$productUpc   = (string) ( $product['upc']   ?? '' );
		$productTitle = (string) ( $product['title'] ?? '' );
		$productBrand = (string) ( $product['brand'] ?? '' );
		$titleLine    = trim( ( $productBrand !== '' ? $productBrand . ' — ' : '' ) . $productTitle );

		/* --- 3) Flag lookup for this UPC + state --- */
		$flagRows = [];
		try
		{
			foreach ( \IPS\Db::i()->select(
				'firearm_type, reason, citation',
				'gd_compliance_flags',
				[ 'upc=? AND state_code=?', $productUpc, $stateCode ],
				'firearm_type ASC'
			) as $r )
			{
				$flagRows[] = $r;
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'lookup buildResult flags: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		/* Split into advisory vs restrict. */
		$restrictFlags = [];
		$advisoryFlags = [];
		foreach ( $flagRows as $r )
		{
			$ftype = (string) ( $r['firearm_type'] ?? '' );
			if ( $ftype === 'advisory' )
			{
				$advisoryFlags[] = $r;
			}
			else
			{
				$restrictFlags[] = $r;
			}
		}

		$productTitleHtml = '<p class="title"><strong>' . $h( (string) $lang->addToStack(
			'gdcompliance_lookup_product', FALSE, [ 'sprintf' => [ $productUpc ] ]
		) ) . '</strong> ' . $h( $titleLine ) . '</p>';

		/* --- Result: restrict wins the headline; advisories render below. --- */
		if ( !empty( $restrictFlags ) )
		{
			$out = '<div class="gdcl-result">'
				. '<h2><span class="gdcl-badge restrict">⛔ Restricted</span>' . $h( (string) $lang->addToStack(
					'gdcompliance_lookup_restricted_headline', FALSE, [ 'sprintf' => [ $stateName ] ]
				) ) . '</h2>'
				. $productTitleHtml;

			foreach ( $restrictFlags as $f )
			{
				$reason = trim( (string) ( $f['reason']   ?? '' ) );
				$cite   = trim( (string) ( $f['citation'] ?? '' ) );
				$out .= '<div class="gdcl-restrict">'
					. '<strong>' . $h( self::flagTypeLabel( (string) ( $f['firearm_type'] ?? '' ) ) ) . '</strong>'
					. ( $reason !== '' ? '<p class="reason">' . $h( $reason ) . '</p>' : '' )
					. ( $cite   !== '' ? '<p class="cite">' . $h( (string) $lang->addToStack(
						'gdcompliance_lookup_citation', FALSE, [ 'sprintf' => [ $cite ] ]
					) ) . '</p>' : '' )
					. '</div>';
			}
			foreach ( $advisoryFlags as $f )
			{
				$reason = trim( (string) ( $f['reason']   ?? '' ) );
				$cite   = trim( (string) ( $f['citation'] ?? '' ) );
				$out .= '<div class="gdcl-advisory">'
					. '<strong>' . $h( $lang->addToStack( 'gdcompliance_lookup_advisory_label' ) ) . '</strong>'
					. ( $reason !== '' ? '<p class="reason">' . $h( $reason ) . '</p>' : '' )
					. ( $cite   !== '' ? '<p class="cite">' . $h( (string) $lang->addToStack(
						'gdcompliance_lookup_citation', FALSE, [ 'sprintf' => [ $cite ] ]
					) ) . '</p>' : '' )
					. '</div>';
			}
			$out .= '<p class="gdcl-muted">' . $h( $lang->addToStack( 'gdcompliance_lookup_verify_reminder' ) ) . '</p>';
			$out .= '</div>';
			return $out;
		}

		/* --- Advisory-only (no restrict) --- */
		if ( !empty( $advisoryFlags ) )
		{
			$out = '<div class="gdcl-result">'
				. '<h2><span class="gdcl-badge advisory">ⓘ Advisory</span>' . $h( (string) $lang->addToStack(
					'gdcompliance_lookup_advisory_headline', FALSE, [ 'sprintf' => [ $stateName ] ]
				) ) . '</h2>'
				. $productTitleHtml
				. '<p style="margin:0 0 10px;color:#78350f">' . $h( $lang->addToStack( 'gdcompliance_lookup_advisory_intro' ) ) . '</p>';
			foreach ( $advisoryFlags as $f )
			{
				$reason = trim( (string) ( $f['reason']   ?? '' ) );
				$cite   = trim( (string) ( $f['citation'] ?? '' ) );
				$out .= '<div class="gdcl-advisory">'
					. ( $reason !== '' ? '<p class="reason">' . $h( $reason ) . '</p>' : '' )
					. ( $cite   !== '' ? '<p class="cite">' . $h( (string) $lang->addToStack(
						'gdcompliance_lookup_citation', FALSE, [ 'sprintf' => [ $cite ] ]
					) ) . '</p>' : '' )
					. '</div>';
			}
			$out .= '<p class="gdcl-muted">' . $h( $lang->addToStack( 'gdcompliance_lookup_verify_reminder' ) ) . '</p>';
			$out .= '</div>';
			return $out;
		}

		/* --- No flags --- */
		return '<div class="gdcl-result">'
			. '<h2><span class="gdcl-badge clear">✓ ' . $h( (string) $lang->addToStack(
				'gdcompliance_lookup_norestrict_headline', FALSE, [ 'sprintf' => [ $stateName ] ]
			) ) . '</span></h2>'
			. $productTitleHtml
			. '<div class="gdcl-clear">'
			. $h( (string) $lang->addToStack(
				'gdcompliance_lookup_clear_body', FALSE, [ 'sprintf' => [ $stateName ] ]
			) )
			. '</div>'
			. '<p class="gdcl-muted" style="margin-top:10px">' . $h( $lang->addToStack( 'gdcompliance_lookup_clear_reminder' ) ) . '</p>'
			. '</div>';
	}

	/**
	 * Feature-disabled fallback — friendly notice instead of 404.
	 */
	protected function renderDisabled(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		\IPS\Output::i()->title  = $lang->addToStack( 'gdcompliance_lookup_page_title' );
		\IPS\Output::i()->output = '<div style="max-width:640px;margin:40px auto;padding:24px;text-align:center;font-family:system-ui,sans-serif">'
			. '<h1 style="color:#0f172a">' . $h( $lang->addToStack( 'gdcompliance_lookup_page_title' ) ) . '</h1>'
			. '<p style="color:#475569">' . $h( $lang->addToStack( 'gdcompliance_lookup_disabled_msg' ) ) . '</p>'
			. '</div>';
	}

	/**
	 * Human label per firearm_type. Kept short — the reason line
	 * carries the detail.
	 */
	protected static function flagTypeLabel( string $ftype ): string
	{
		return match( true ) {
			strncmp( $ftype, 'awb_', 4 ) === 0    => 'Assault-weapons ban',
			strncmp( $ftype, 'pica_', 5 ) === 0   => 'Assault-weapons ban',
			$ftype === 'awb_lower'                => 'Assault-weapons ban (lower receiver)',
			$ftype === 'magazine'                 => 'Magazine capacity',
			$ftype === 'melting_point'            => 'Melting-point / frame material',
			$ftype === 'rate_of_fire'             => 'Rate-of-fire device',
			$ftype === 'manual'                   => 'Manual override',
			in_array( $ftype, [ 'handgun', 'rifle', 'shotgun' ], true ) => 'Magazine capacity',
			default                               => 'State restriction',
		};
	}
}

class lookup extends _lookup {}
