<?php
/**
 * @brief  GD Compliance — Public State Compliance Lookup (Stages 1 + 2 + 3)
 *
 * Stage 1 (v1.6.22 → v1.6.23): visitor picks a state + enters UPC/MPN,
 * page shows restricted / advisory / no-restrictions for that state.
 * Read-only over gd_compliance_flags.
 *
 * Stage 2 (v1.6.24): logged-in member flags misclassifications via a
 * "Report a problem" affordance. Report stored in gd_compliance_reports;
 * guest sees login prompt. CSRF via POST body csrfKey (rule #81); rate-
 * limited via setting gdcompliance_report_ratelimit (default 5/hr).
 *
 * Stage 3 (v1.6.25): (a) advanced search view (state required, mode
 * RESTRICTED/AVAILABLE, category/type/brand filters, paginated) using
 * IPS-native select()->join() over gd_compliance_flags / gd_catalog.
 * (b) full-state restricted list with CSV export (upc/title/brand/
 * type/reason/citation, ≤50k rows). (c) row-level "Report a problem"
 * links reuse Stage-2 flow by rebuilding the single-lookup URL with
 * pre-filled state+q — no new report code.
 *
 * FURL: /state-lookup/ (registered in data/furl.json). All three
 * views live under the same FURL; view selection via ?do=search /
 * ?do=statelist / (no do → single lookup).
 *
 * NO ACP permission check — the page is publicly viewable. State-
 * changing action (submit report) checks login + CSRF at the POST.
 *
 * Design ceilings (Stage 3):
 *   - RESTRICTED lists are inherently bounded (state has finite flags).
 *   - AVAILABLE queries ARE NOT — a bare-state "available" cardinal-
 *     ity is ~catalog size. Enforced: require at least one of
 *     {category, brand} filter before running an available query.
 *   - CSV export cap: gdcompliance_lookup_csv_max (default 50000).
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

	/** Set by buildResult() so manage() can render a matching report button. */
	protected string $lastClassification = '';

	/** Set by buildResult() so submit() can compare / trust nothing. */
	protected string $lastUpc = '';

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

		/* Post-submit flash from a report POST. Ephemeral URL param;
		   nothing state-changing here — just a friendly banner. */
		$reportFlash = (string) ( \IPS\Request::i()->reported ?? '' );
		if ( !in_array( $reportFlash, [ 'ok', 'ratelimit', 'error', 'login' ], true ) )
		{
			$reportFlash = '';
		}

		$resultHtml = '';
		if ( $submitted )
		{
			$resultHtml = $this->buildResult( $stateSel, $q );
		}

		/* Login-gated report block — rendered ONLY when we have a real
		   classification for the visitor to flag (product-in-catalog).
		   Guests see a "log in to report" prompt. */
		$reportBlockHtml = '';
		if ( $submitted && $this->lastClassification !== '' && $this->lastUpc !== '' )
		{
			$reportBlockHtml = $this->buildReportBlock( $this->lastUpc, $stateSel, $this->lastClassification, $reportFlash );
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
			. $this->pageStyles()
			. '<div class="gdcl-wrap">'
			. '<div class="gdcl-hero">'
			. '<h1>' . $h( $title ) . '</h1>'
			. '<p>' . $h( $lang->addToStack( 'gdcompliance_lookup_intro' ) ) . '</p>'
			. '</div>'
			. '<div class="gdcl-disclaimer"><strong>' . $h( $lang->addToStack( 'gdcompliance_lookup_disclaimer_label' ) ) . '</strong><br>'
			. nl2br( $h( $disclaimer ) )
			. '</div>'
			. $this->renderTabs( 'single', $stateSel )
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
			. $reportBlockHtml
			. '</div>';

		\IPS\Output::i()->output = $html;
	}

	/**
	 * All styles for the lookup pages (single, search, statelist). Kept
	 * in one place so refactors don't diverge. Wrapped by the caller's
	 * <style> — return raw <style>…</style> to keep the callers terse.
	 */
	protected function pageStyles(): string
	{
		return '<style>'
			/* v1.6.26: outer wrap grows to 1446px (full desktop); the
			   single-column "reading" blocks (hero, disclaimer, single-
			   lookup form, single-result result, report-wrap) each cap
			   themselves at 820px so prose stays a comfortable measure
			   while data tables (filters, lists, actions, pager) get
			   the full 1446px. Everything shrinks gracefully below. */
			. '.gdcl-wrap{max-width:1446px;margin:24px auto;padding:0 16px;font-family:\'Inter\',system-ui,-apple-system,sans-serif;color:#0f172a}'
			. '.gdcl-hero{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;margin:0 auto 18px;max-width:820px;box-shadow:0 2px 10px rgba(15,23,42,.04)}'
			. '.gdcl-hero h1{margin:0 0 6px;font-size:1.5em;font-weight:700;color:#0f172a}'
			. '.gdcl-hero p{margin:0;color:#475569;font-size:.95em;line-height:1.5}'
			. '.gdcl-disclaimer{background:#fefce8;border:1px solid #facc15;color:#713f12;border-radius:10px;padding:12px 14px;margin:0 auto 16px;max-width:820px;font-size:.9em;line-height:1.5}'
			. '.gdcl-disclaimer strong{color:#854d0e}'
			. '.gdcl-form{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin:0 auto 18px;max-width:820px}'
			. '.gdcl-form label{display:block;margin:0 0 4px;font-size:.82em;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.04em}'
			. '.gdcl-form select,.gdcl-form input[type=text]{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:1em;background:#fff;color:#0f172a}'
			. '.gdcl-form select:focus,.gdcl-form input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}'
			. '.gdcl-row{margin-bottom:12px}'
			. '.gdcl-submit{display:inline-block;background:#1e40af;color:#fff;border:none;font-weight:600;padding:11px 22px;border-radius:8px;cursor:pointer;font-size:.95em}'
			. '.gdcl-submit:hover{background:#1e3a8a}'
			. '.gdcl-result{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin:0 auto 18px;max-width:820px}'
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
			. '.gdcl-report-wrap{margin:14px auto 0;max-width:820px}'
			. '.gdcl-report-flash{padding:10px 12px;border-radius:8px;font-size:.9em;margin-bottom:10px;line-height:1.5}'
			. '.gdcl-report-flash--ok{background:#ecfeff;border:1px solid #a5f3fc;color:#155e75}'
			. '.gdcl-report-flash--warn{background:#fef3c7;border:1px solid #fde68a;color:#78350f}'
			. '.gdcl-report{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px}'
			. '.gdcl-report[open]{padding:14px}'
			. '.gdcl-report-btn{display:inline-block;background:transparent;color:#1e40af;border:1px solid #cbd5e1;font-weight:600;padding:7px 14px;border-radius:8px;cursor:pointer;font-size:.85em;text-decoration:none;list-style:none}'
			. '.gdcl-report-btn::-webkit-details-marker{display:none}'
			. '.gdcl-report-btn:hover{background:#f1f5f9}'
			. '.gdcl-report-form{margin-top:12px}'
			. '.gdcl-report-fields{display:flex;gap:14px;margin-bottom:10px}'
			. '.gdcl-report-fields > div{flex:1 1 auto}'
			. '.gdcl-report-fields label{display:block;font-size:.75em;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px}'
			. '.gdcl-report-readonly{background:#f8fafc;border:1px solid #e2e8f0;color:#334155;padding:8px 10px;border-radius:6px;font-family:ui-monospace,monospace;font-size:.9em}'
			. '.gdcl-report-form label{display:block;font-size:.8em;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin:8px 0 3px}'
			. '.gdcl-report-form textarea{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.95em;color:#0f172a;font-family:inherit;resize:vertical}'
			. '.gdcl-report-form textarea:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}'
			. '.gdcl-report-submit{display:inline-block;background:#1e40af;color:#fff;border:none;font-weight:600;padding:9px 18px;border-radius:8px;cursor:pointer;font-size:.9em;margin-top:10px}'
			. '.gdcl-report-submit:hover{background:#1e3a8a}'
			. '.gdcl-report-hint{margin:8px 0 0;font-size:.8em;color:#64748b;line-height:1.5}'
			/* --- Stage 3 additions: tabs, filter bar, result rows, pager. --- */
			. '.gdcl-tabs{display:flex;gap:2px;background:#f1f5f9;border-radius:10px;padding:4px;margin-bottom:16px}'
			. '.gdcl-tab{flex:1 1 auto;text-align:center;padding:9px 12px;color:#334155;background:transparent;font-weight:600;font-size:.9em;text-decoration:none;border-radius:8px}'
			. '.gdcl-tab:hover{background:rgba(255,255,255,.7);color:#0f172a}'
			. '.gdcl-tab--active{background:#fff;color:#1e40af;box-shadow:0 1px 3px rgba(15,23,42,.06)}'
			. '.gdcl-filters{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:16px}'
			. '.gdcl-filters .row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px}'
			. '.gdcl-filters .row > div{flex:1 1 180px}'
			. '.gdcl-filters label{display:block;margin:0 0 4px;font-size:.75em;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em}'
			. '.gdcl-filters select,.gdcl-filters input[type=text]{width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:.95em;background:#fff;color:#0f172a}'
			. '.gdcl-filters select:focus,.gdcl-filters input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}'
			. '.gdcl-mode-toggle{display:inline-flex;background:#f1f5f9;border-radius:8px;padding:3px;margin-bottom:0;gap:2px}'
			. '.gdcl-mode-toggle label{margin:0;font-size:.85em;text-transform:none;letter-spacing:0}'
			. '.gdcl-mode-toggle input{position:absolute;opacity:0;pointer-events:none}'
			. '.gdcl-mode-toggle span{display:inline-block;padding:7px 14px;border-radius:6px;font-weight:600;color:#475569;cursor:pointer}'
			. '.gdcl-mode-toggle input:checked + span{background:#fff;color:#1e40af;box-shadow:0 1px 3px rgba(15,23,42,.06)}'
			. '.gdcl-note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:.9em;line-height:1.5}'
			. '.gdcl-warn{background:#fef3c7;border:1px solid #fde68a;color:#78350f;padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:.9em;line-height:1.5}'
			. '.gdcl-count{color:#334155;font-size:.95em;margin:0 0 10px}'
			. '.gdcl-count strong{color:#0f172a}'
			. '.gdcl-list{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:0;margin-bottom:12px;overflow:hidden}'
			/* v1.6.26 row layout: proper flex row with title col that CAN
			   shrink (min-width:0 is required — without it, flex items
			   won't shrink below their content width and the button
			   would keep wrapping to the next line). justify-content:
			   space-between pins the report link to the right; flex-
			   wrap:nowrap keeps it on the SAME line consistently. Long
			   titles wrap within the .main column via overflow-wrap. */
			. '.gdcl-list-row{padding:14px 16px;border-bottom:1px solid #eef2f7;display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:nowrap}'
			. '.gdcl-list-row:last-child{border-bottom:none}'
			. '.gdcl-list-row .main{flex:1 1 auto;min-width:0;overflow-wrap:break-word;word-break:break-word}'
			. '.gdcl-list-row .title{margin:0;color:#0f172a;font-weight:600;font-size:1em;overflow-wrap:break-word;word-break:break-word}'
			. '.gdcl-list-row .meta{margin:2px 0 0;color:#64748b;font-size:.85em}'
			. '.gdcl-list-row .upc{display:inline-block;font-family:ui-monospace,monospace;color:#475569;font-size:.85em;background:#f1f5f9;padding:2px 8px;border-radius:6px;max-width:100%;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}'
			. '.gdcl-list-row .reason{margin:6px 0 0;color:#7f1d1d;font-size:.9em;line-height:1.4;overflow-wrap:break-word}'
			. '.gdcl-list-row .cite{margin:2px 0 0;color:#7f1d1d;font-size:.8em;opacity:.85;overflow-wrap:break-word}'
			/* Narrow viewports: let the row wrap so the button drops
			   below cleanly rather than crushing the title. Fires only
			   when there truly isn't room. */
			. '@media (max-width:640px){.gdcl-list-row{flex-wrap:wrap}.gdcl-list-row .main{flex-basis:100%}}'
			. '.gdcl-type-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.7em;font-weight:700;text-transform:uppercase;letter-spacing:.03em}'
			. '.gdcl-type-awb{background:#fee2e2;color:#991b1b}'
			. '.gdcl-type-cap{background:#fef3c7;color:#78350f}'
			. '.gdcl-type-mp{background:#fce7f3;color:#831843}'
			. '.gdcl-type-rof{background:#e0e7ff;color:#3730a3}'
			. '.gdcl-type-adv{background:#dcfce7;color:#14532d}'
			. '.gdcl-row-report{color:#1e40af;font-size:.8em;text-decoration:none;font-weight:600;flex-shrink:0;flex-grow:0;flex-basis:auto;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;align-self:flex-start;background:#fff;white-space:nowrap}'
			. '.gdcl-row-report:hover{background:#f1f5f9}'
			. '.gdcl-pager{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}'
			. '.gdcl-pager-btn{background:#fff;color:#1e40af;padding:8px 14px;border:1px solid #cbd5e1;border-radius:8px;font-weight:600;font-size:.9em;text-decoration:none}'
			. '.gdcl-pager-btn:hover{background:#f1f5f9}'
			. '.gdcl-pager-btn--dim{color:#94a3b8;background:#f8fafc}'
			. '.gdcl-pager-info{color:#64748b;font-size:.9em}'
			. '.gdcl-actions{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap}'
			. '.gdcl-actions .btn{background:#1e40af;color:#fff;padding:9px 16px;border-radius:8px;font-weight:600;text-decoration:none;border:none;cursor:pointer;font-size:.9em}'
			. '.gdcl-actions .btn:hover{background:#1e3a8a}'
			. '.gdcl-actions .btn--sec{background:#fff;color:#1e40af;border:1px solid #cbd5e1}'
			. '.gdcl-actions .btn--sec:hover{background:#f1f5f9}'
			. '.gdcl-empty{background:#f8fafc;border:1px dashed #cbd5e1;color:#64748b;padding:24px;text-align:center;border-radius:10px;margin-bottom:14px}'
			. '.gdcl-section-head{margin:14px 0 6px;color:#334155;font-size:.9em;font-weight:700;text-transform:uppercase;letter-spacing:.05em}'
			. '</style>';
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
		$this->lastUpc = $productUpc;

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
			$this->lastClassification = 'restricted';
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
			$this->lastClassification = 'advisory';
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
		$this->lastClassification = 'no_restrictions';
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
	 * Build the "Report a problem" block that renders below the result.
	 *
	 * Guest → "Log in to report a classification issue" (link to IPS
	 * login with return_url set back to the lookup page for this q).
	 * Member → collapsed <details> with UPC/state pre-filled read-only
	 * fields, a note textarea, and a CSRF-protected POST form.
	 *
	 * The flash param ('ok', 'ratelimit', 'error', 'login') surfaces
	 * the outcome of a prior POST so the visitor sees a friendly
	 * confirmation right where the button used to be.
	 */
	protected function buildReportBlock( string $productUpc, string $stateCode, string $classification, string $flash ): string
	{
		$lang   = \IPS\Member::loggedIn()->language();
		$h      = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		$member = \IPS\Member::loggedIn();

		/* Flash first. */
		$flashHtml = '';
		if ( $flash === 'ok' )
		{
			$flashHtml = '<div class="gdcl-report-flash gdcl-report-flash--ok">'
				. $h( $lang->addToStack( 'gdcompliance_lookup_report_thanks' ) )
				. '</div>';
		}
		elseif ( $flash === 'ratelimit' )
		{
			$flashHtml = '<div class="gdcl-report-flash gdcl-report-flash--warn">'
				. $h( $lang->addToStack( 'gdcompliance_lookup_report_ratelimited' ) )
				. '</div>';
		}
		elseif ( $flash === 'error' )
		{
			$flashHtml = '<div class="gdcl-report-flash gdcl-report-flash--warn">'
				. $h( $lang->addToStack( 'gdcompliance_lookup_report_error' ) )
				. '</div>';
		}
		elseif ( $flash === 'login' )
		{
			$flashHtml = '<div class="gdcl-report-flash gdcl-report-flash--warn">'
				. $h( $lang->addToStack( 'gdcompliance_lookup_report_login_required' ) )
				. '</div>';
		}

		/* Guest → login prompt. Return URL preserves the query so the
		   member lands back on the same result page. */
		if ( !$member->member_id )
		{
			$returnUrl = (string) \IPS\Http\Url::internal(
				'app=gdcompliance&module=lookup&controller=lookup&state=' . rawurlencode( $stateCode ) . '&q=' . rawurlencode( $productUpc ),
				'front', 'gdcompliance_state_lookup'
			);
			$loginUrl = (string) \IPS\Http\Url::internal(
				'app=core&module=system&controller=login&ref=' . base64_encode( $returnUrl )
			);
			return '<div class="gdcl-report-wrap">'
				. $flashHtml
				. '<a href="' . $h( $loginUrl ) . '" class="gdcl-report-btn">'
				. $h( $lang->addToStack( 'gdcompliance_lookup_report_login_cta' ) )
				. '</a>'
				. '</div>';
		}

		/* Member: collapsible <details> form. */
		$submitUrl = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=lookup&controller=lookup&do=submitReport',
			'front', 'gdcompliance_state_lookup'
		);
		$csrfKey = (string) \IPS\Session::i()->csrfKey;
		$stateName = self::STATE_NAMES[ $stateCode ] ?? $stateCode;

		return '<div class="gdcl-report-wrap">'
			. $flashHtml
			. '<details class="gdcl-report">'
			. '<summary class="gdcl-report-btn">' . $h( $lang->addToStack( 'gdcompliance_lookup_report_cta' ) ) . '</summary>'
			. '<form method="post" action="' . $h( $submitUrl ) . '" class="gdcl-report-form">'
			. '<input type="hidden" name="csrfKey" value="' . $h( $csrfKey ) . '">'
			. '<input type="hidden" name="upc" value="' . $h( $productUpc ) . '">'
			. '<input type="hidden" name="state" value="' . $h( $stateCode ) . '">'
			. '<input type="hidden" name="classification" value="' . $h( $classification ) . '">'
			. '<div class="gdcl-report-fields">'
			. '<div><label>UPC</label><div class="gdcl-report-readonly">' . $h( $productUpc ) . '</div></div>'
			. '<div><label>State</label><div class="gdcl-report-readonly">' . $h( $stateName ) . '</div></div>'
			. '</div>'
			. '<label for="gdcl-report-note">' . $h( $lang->addToStack( 'gdcompliance_lookup_report_note_label' ) ) . '</label>'
			. '<textarea id="gdcl-report-note" name="note" rows="4" maxlength="2000" required placeholder="' . $h( $lang->addToStack( 'gdcompliance_lookup_report_note_placeholder' ) ) . '"></textarea>'
			. '<button type="submit" class="gdcl-report-submit">' . $h( $lang->addToStack( 'gdcompliance_lookup_report_submit' ) ) . '</button>'
			. '<p class="gdcl-report-hint">' . $h( $lang->addToStack( 'gdcompliance_lookup_report_hint' ) ) . '</p>'
			. '</form>'
			. '</details>'
			. '</div>';
	}

	/**
	 * POST /state-lookup/?do=submitReport
	 *
	 * Auth: login required (guests bounced with ?reported=login flash).
	 * CSRF: csrfKey in POST body — validated via Session::csrfCheck().
	 * Rate limit: N per member per hour, per setting
	 *             gdcompliance_report_ratelimit (default 5).
	 *
	 * Trust: member_id from Session (never POST). UPC + state are
	 * re-validated against gd_catalog + STATE_NAMES; classification is
	 * trusted only as a label for what the visitor saw (audit only —
	 * we don't recompute on the report row).
	 */
	public function submitReport(): void
	{
		$lang = \IPS\Member::loggedIn()->language();

		/* Login gate. */
		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			$this->bounceReport( '', '', 'login' );
			return;
		}

		/* CSRF. csrfKey lives in POST body (rule #62 / #81). */
		try { \IPS\Session::i()->csrfCheck(); }
		catch ( \Throwable )
		{
			$this->bounceReport( '', '', 'error' );
			return;
		}

		/* Inputs. Validate state + classification; UPC bounded + charset-limited. */
		$stateCode = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( !isset( self::STATE_NAMES[ $stateCode ] ) )
		{
			$this->bounceReport( '', '', 'error' );
			return;
		}

		$upcRaw = trim( (string) ( \IPS\Request::i()->upc ?? '' ) );
		$upc    = substr( $upcRaw, 0, 64 );
		if ( $upc === '' || !preg_match( '/^[A-Za-z0-9\-\._\/ ]+$/', $upc ) )
		{
			$this->bounceReport( '', $stateCode, 'error' );
			return;
		}

		$classification = (string) ( \IPS\Request::i()->classification ?? '' );
		if ( !in_array( $classification, [ 'restricted', 'no_restrictions', 'advisory' ], true ) )
		{
			$classification = '';
		}

		$note = trim( (string) ( \IPS\Request::i()->note ?? '' ) );
		$note = mb_substr( $note, 0, 2000 );
		if ( $note === '' )
		{
			$this->bounceReport( $upc, $stateCode, 'error' );
			return;
		}

		/* Rate limit. Independent per-member; setting-driven. */
		$limit = (int) ( \IPS\Settings::i()->gdcompliance_report_ratelimit ?? 5 );
		if ( $limit < 1 ) { $limit = 5; }
		$since = time() - 3600;
		$recent = 0;
		try
		{
			$recent = (int) \IPS\Db::i()->select(
				'COUNT(*)', 'gd_compliance_reports',
				[ 'member_id=? AND created_at > ?', (int) $member->member_id, $since ]
			)->first();
		}
		catch ( \Throwable ) {}
		if ( $recent >= $limit )
		{
			$this->bounceReport( $upc, $stateCode, 'ratelimit' );
			return;
		}

		/* Ignore reports for products we don't carry (defensive — the
		   button only renders when we do, but a hand-crafted POST could
		   send anything). */
		$exists = false;
		try
		{
			$exists = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', [ 'upc=?', $upc ] )->first() > 0;
		}
		catch ( \Throwable ) {}
		if ( !$exists )
		{
			$this->bounceReport( $upc, $stateCode, 'error' );
			return;
		}

		/* Insert the report row. */
		try
		{
			\IPS\Db::i()->insert( 'gd_compliance_reports', [
				'member_id'               => (int) $member->member_id,
				'upc'                     => $upc,
				'state_code'              => $stateCode,
				'reported_classification' => $classification,
				'note'                    => $note,
				'status'                  => 'pending',
				'created_at'              => time(),
				'ip_address'              => (string) mb_substr( (string) ( \IPS\Request::i()->ipAddress() ?? '' ), 0, 45 ),
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'submitReport insert: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			$this->bounceReport( $upc, $stateCode, 'error' );
			return;
		}

		$this->bounceReport( $upc, $stateCode, 'ok' );
	}

	/**
	 * Bare redirect back to the lookup page with the same state+q and
	 * a small ?reported=… flash. No second arg on redirect() — that
	 * shows an interstitial (rule #21).
	 */
	protected function bounceReport( string $upc, string $stateCode, string $status ): void
	{
		$q = [
			'app'        => 'gdcompliance',
			'module'     => 'lookup',
			'controller' => 'lookup',
			'reported'   => $status,
		];
		if ( $stateCode !== '' ) { $q['state'] = $stateCode; }
		if ( $upc       !== '' ) { $q['q']     = $upc; }
		$url = (string) \IPS\Http\Url::internal( http_build_query( $q ), 'front', 'gdcompliance_state_lookup' );
		\IPS\Output::i()->redirect( $url );
	}

	/* =====================================================================
	 * Stage 3 shared helpers
	 * ===================================================================== */

	/**
	 * Category picker. First three roll up to a firearm top-type via
	 * Engine::buildTypeMap (parent_id walk); the last three don't — they
	 * map to fixed category_ids directly in categoryIdsForType() below.
	 * Verified live cat IDs: mag=38, accessories=58, triggers=60, lower=154.
	 */
	const CATEGORY_CHOICES = [
		''          => 'Any category',
		'handgun'   => 'Handguns',
		'rifle'     => 'Rifles',
		'shotgun'   => 'Shotguns',
		'lower'     => 'Lowers',
		'magazine'  => 'Magazines',
		'accessory' => 'Accessories',
	];

	/**
	 * Fixed category_id mappings for CATEGORY_CHOICES entries that don't
	 * roll up through Engine::buildTypeMap (which only classifies
	 * handgun/rifle/shotgun). IDs verified live on the running install.
	 */
	const CATEGORY_FIXED_IDS = [
		'lower'     => [ 154 ],
		'magazine'  => [ 38 ],
		'accessory' => [ 58, 60 ],
	];

	/** Restriction-type picker options for Restricted mode. */
	const TYPE_CHOICES = [
		''             => 'Any restriction',
		'awb'          => 'Assault-weapons ban',
		'capacity'     => 'Magazine capacity',
		'melting'      => 'Melting-point / frame material',
		'rate_of_fire' => 'Rate-of-fire device',
		'advisory'     => 'Buyer advisory',
	];

	/** Page size for advanced search / statelist. */
	const PER_PAGE = 25;

	/**
	 * Build the top-of-page tab strip. Same three views on every state-
	 * lookup page. Preserves the current state selection where possible
	 * so switching tabs keeps the visitor's context.
	 */
	protected function renderTabs( string $active, string $stateSel = '' ): string
	{
		$h = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$stateQs = $stateSel !== '' ? '&state=' . rawurlencode( $stateSel ) : '';
		$tabs = [
			'single'   => [ (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=lookup&controller=lookup' . $stateQs, 'front', 'gdcompliance_state_lookup' ),
				'Single Lookup' ],
			'search'   => [ (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=lookup&controller=lookup&do=search' . $stateQs, 'front', 'gdcompliance_state_lookup' ),
				'Advanced Search' ],
			'statelist' => [ (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=lookup&controller=lookup&do=statelist' . $stateQs, 'front', 'gdcompliance_state_lookup' ),
				'Restricted List' ],
		];

		$html = '<div class="gdcl-tabs">';
		foreach ( $tabs as $key => [ $url, $label ] )
		{
			$cls = 'gdcl-tab' . ( $active === $key ? ' gdcl-tab--active' : '' );
			$html .= '<a href="' . $h( $url ) . '" class="' . $h( $cls ) . '">' . $h( $label ) . '</a>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * gd_catalog category_id → firearm_type map ('handgun'|'rifle'|
	 * 'shotgun'|null), memoized per request. Falls back to empty array
	 * on Engine unavailability (e.g. minimal install without Engine.php).
	 */
	protected function getTypeMap(): array
	{
		static $cached = null;
		if ( $cached !== null ) { return $cached; }

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Engine.php';
			$cached = \IPS\gdcompliance\Engine::buildTypeMap();
		}
		catch ( \Throwable )
		{
			$cached = [];
		}
		return $cached;
	}

	/**
	 * Return the category_id list for a chosen category filter.
	 * Empty list → no category filter (caller treats as "any").
	 *
	 * Two paths:
	 *   - Fixed mappings (Lowers=154, Magazines=38, Accessories=58,60)
	 *     because those categories are NOT firearm top-types and never
	 *     appear in Engine::buildTypeMap()'s output.
	 *   - Firearm top-types (handgun/rifle/shotgun) walked via
	 *     Engine::buildTypeMap so children like "Bolt Action Rifle" get
	 *     matched too.
	 */
	protected function categoryIdsForType( string $type ): array
	{
		if ( $type === '' ) { return []; }

		if ( isset( self::CATEGORY_FIXED_IDS[ $type ] ) )
		{
			return self::CATEGORY_FIXED_IDS[ $type ];
		}

		$out = [];
		foreach ( $this->getTypeMap() as $catId => $topType )
		{
			if ( $topType === $type ) { $out[] = (int) $catId; }
		}
		return $out;
	}

	/**
	 * The set of firearm_type values that qualify as a "restriction"
	 * (not merely an advisory) for the given restriction-type filter.
	 *
	 *   ''             → all restrict-type flags (excludes advisory)
	 *   'awb'          → awb_%, pica_%, awb_lower
	 *   'capacity'     → handgun/rifle/shotgun/magazine (capacity rows)
	 *   'melting'      → melting_point
	 *   'rate_of_fire' → rate_of_fire
	 *   'advisory'     → advisory
	 *
	 * Returns [ whereFragment, argsArray ] to append to caller WHEREs.
	 */
	protected function typeFilterClause( string $type, string $flagAlias = 'f' ): array
	{
		$col = $flagAlias . '.firearm_type';
		switch ( $type )
		{
			case 'advisory':
				return [ "$col=?", [ 'advisory' ] ];
			case 'melting':
				return [ "$col=?", [ 'melting_point' ] ];
			case 'rate_of_fire':
				return [ "$col=?", [ 'rate_of_fire' ] ];
			case 'capacity':
				return [ "$col IN (?, ?, ?, ?)", [ 'handgun', 'rifle', 'shotgun', 'magazine' ] ];
			case 'awb':
				return [ "( $col LIKE ? OR $col LIKE ? OR $col=? )", [ 'awb\_%', 'pica\_%', 'awb_lower' ] ];
			case '':
			default:
				/* Restrict-only default excludes advisory. */
				return [ "$col<>?", [ 'advisory' ] ];
		}
	}

	/**
	 * Simple prev/next pager. Returns HTML fragment ready to inline.
	 * Preserves all $extraParams (state=, cat=, brand=, etc.) in each
	 * link's query string so filters survive the click.
	 */
	protected function pager( int $page, int $totalPages, string $do, array $extraParams ): string
	{
		if ( $totalPages < 2 ) { return ''; }
		$h = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$baseArgs = array_merge( [
			'app'        => 'gdcompliance',
			'module'     => 'lookup',
			'controller' => 'lookup',
			'do'         => $do,
		], $extraParams );

		$urlFor = function( int $n ) use ( $baseArgs ) {
			$args = $baseArgs;
			$args['page'] = $n;
			return (string) \IPS\Http\Url::internal( http_build_query( $args ), 'front', 'gdcompliance_state_lookup' );
		};

		$html = '<div class="gdcl-pager">';
		if ( $page > 1 )
		{
			$html .= '<a class="gdcl-pager-btn" href="' . $h( $urlFor( $page - 1 ) ) . '">‹ Previous</a>';
		}
		else
		{
			$html .= '<span class="gdcl-pager-btn gdcl-pager-btn--dim">‹ Previous</span>';
		}
		$html .= '<span class="gdcl-pager-info">Page ' . $page . ' of ' . $totalPages . '</span>';
		if ( $page < $totalPages )
		{
			$html .= '<a class="gdcl-pager-btn" href="' . $h( $urlFor( $page + 1 ) ) . '">Next ›</a>';
		}
		else
		{
			$html .= '<span class="gdcl-pager-btn gdcl-pager-btn--dim">Next ›</span>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Row-level "Report a problem" affordance for advanced-search /
	 * statelist results. Links back to the Single Lookup URL with
	 * state+q pre-filled — Stage 2's buildReportBlock() takes over
	 * from there. No new report code.
	 */
	protected function rowReportLink( string $upc, string $stateCode ): string
	{
		$h = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$url = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=lookup&controller=lookup&state=' . rawurlencode( $stateCode ) . '&q=' . rawurlencode( $upc ),
			'front', 'gdcompliance_state_lookup'
		);
		return '<a href="' . $h( $url ) . '" class="gdcl-row-report">Report a problem</a>';
	}

	/**
	 * Build the standard state <select>. Reused across all three views.
	 */
	protected function stateSelectHtml( string $selected, string $name = 'state' ): string
	{
		$h = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$out = '<select name="' . $h( $name ) . '" required><option value="">Pick a state…</option>';
		$sorted = self::STATE_NAMES;
		asort( $sorted, SORT_NATURAL | SORT_FLAG_CASE );
		foreach ( $sorted as $code => $name )
		{
			$sel = ( $selected === $code ) ? ' selected' : '';
			$out .= '<option value="' . $h( $code ) . '"' . $sel . '>' . $h( $name ) . '</option>';
		}
		$out .= '</select>';
		return $out;
	}

	/**
	 * Standard page chrome (title, disclaimer, tabs). Returns the opening
	 * HTML — caller emits its own body and closes with '</div>'.
	 */
	protected function pageChrome( string $activeTab, string $stateSel ): string
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$title = (string) $lang->addToStack( 'gdcompliance_lookup_page_title' );
		\IPS\Output::i()->title      = $title;
		\IPS\Output::i()->breadcrumb = [];
		\IPS\Output::i()->sidebar    = [ 'enabled' => false ];

		$disclaimer = trim( (string) ( \IPS\Settings::i()->gdcompliance_lookup_disclaimer ?? '' ) );
		if ( $disclaimer === '' )
		{
			$disclaimer = (string) $lang->addToStack( 'gdcompliance_lookup_default_disclaimer' );
		}

		return ''
			. $this->pageStyles()
			. '<div class="gdcl-wrap">'
			. '<div class="gdcl-hero"><h1>' . $h( $title ) . '</h1>'
			. '<p>' . $h( (string) $lang->addToStack( 'gdcompliance_lookup_intro' ) ) . '</p></div>'
			. '<div class="gdcl-disclaimer"><strong>' . $h( (string) $lang->addToStack( 'gdcompliance_lookup_disclaimer_label' ) ) . '</strong><br>'
			. nl2br( $h( $disclaimer ) )
			. '</div>'
			. $this->renderTabs( $activeTab, $stateSel );
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

	/**
	 * CSS class fragment for the row-badge per restriction type. Used
	 * only in the compact search/statelist rows.
	 */
	protected static function typeBadgeClass( string $ftype ): string
	{
		return match( true ) {
			strncmp( $ftype, 'awb_', 4 ) === 0  => 'awb',
			strncmp( $ftype, 'pica_', 5 ) === 0 => 'awb',
			$ftype === 'melting_point'          => 'mp',
			$ftype === 'rate_of_fire'           => 'rof',
			$ftype === 'advisory'               => 'adv',
			default                             => 'cap',
		};
	}

	/* =====================================================================
	 * Stage 3 — Advanced Search view (?do=search)
	 *
	 * Filters: state (required), mode (restricted|available),
	 * category (handgun|rifle|shotgun|''), type (Restricted-mode only,
	 * awb|capacity|melting|rate_of_fire|advisory|''), brand (LIKE).
	 *
	 * Restricted: SELECT ... FROM gd_compliance_flags f LEFT JOIN
	 * gd_catalog c ON c.upc=f.upc WHERE f.state_code=? [+ type filter
	 * on f.firearm_type; category filter on c.category_id IN (...);
	 * brand LIKE].
	 *
	 * Available: SELECT ... FROM gd_catalog c LEFT JOIN
	 * gd_compliance_flags f ON (f.upc=c.upc AND f.state_code=? AND
	 * f.firearm_type <> 'advisory') WHERE f.id IS NULL [+ same
	 * filters — advisory items DO count as available]. Requires
	 * category OR brand set (guard against a bare-state ~catalog dump).
	 * ===================================================================== */
	public function search(): void
	{
		$lang = \IPS\Member::loggedIn()->language();
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		if ( (int) ( \IPS\Settings::i()->gdcompliance_lookup_enabled ?? 1 ) !== 1 )
		{
			$this->renderDisabled();
			return;
		}

		/* Filters — all from GET. */
		$stateSel = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( !isset( self::STATE_NAMES[ $stateSel ] ) ) { $stateSel = ''; }

		$mode = (string) ( \IPS\Request::i()->mode ?? 'restricted' );
		if ( !in_array( $mode, [ 'restricted', 'available' ], true ) ) { $mode = 'restricted'; }

		$cat = (string) ( \IPS\Request::i()->cat ?? '' );
		if ( !isset( self::CATEGORY_CHOICES[ $cat ] ) ) { $cat = ''; }

		$type = (string) ( \IPS\Request::i()->type ?? '' );
		if ( !isset( self::TYPE_CHOICES[ $type ] ) ) { $type = ''; }

		$brandRaw = trim( (string) ( \IPS\Request::i()->brand ?? '' ) );
		$brand    = substr( $brandRaw, 0, 60 );
		if ( $brand !== '' && !preg_match( '/^[A-Za-z0-9 &\-\.\+\']+$/', $brand ) )
		{
			$brand = '';
		}

		$page = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );

		/* -- Page chrome + tabs -- */
		$html = $this->pageChrome( 'search', $stateSel );

		/* -- Filter form -- */
		$formUrl = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=lookup&controller=lookup&do=search',
			'front', 'gdcompliance_state_lookup'
		);

		$catOpts = '';
		foreach ( self::CATEGORY_CHOICES as $k => $v )
		{
			$sel = ( $k === $cat ) ? ' selected' : '';
			$catOpts .= '<option value="' . $h( $k ) . '"' . $sel . '>' . $h( $v ) . '</option>';
		}
		$typeOpts = '';
		foreach ( self::TYPE_CHOICES as $k => $v )
		{
			$sel = ( $k === $type ) ? ' selected' : '';
			$typeOpts .= '<option value="' . $h( $k ) . '"' . $sel . '>' . $h( $v ) . '</option>';
		}

		$modeRestrictedChecked = ( $mode === 'restricted' ) ? ' checked' : '';
		$modeAvailableChecked  = ( $mode === 'available'  ) ? ' checked' : '';

		$html .= ''
			. '<form method="get" action="' . $h( $formUrl ) . '" class="gdcl-filters">'
			. '<input type="hidden" name="do" value="search">'
			. '<div class="row">'
			. '<div><label>Ship-to state</label>' . $this->stateSelectHtml( $stateSel ) . '</div>'
			. '<div><label>Category</label><select name="cat">' . $catOpts . '</select></div>'
			. '<div><label>Brand (optional)</label><input type="text" name="brand" value="' . $h( $brand ) . '" maxlength="60" placeholder="e.g. Ruger"></div>'
			. '</div>'
			. '<div class="row" style="align-items:flex-end">'
			. '<div style="flex:0 0 auto"><label>Mode</label>'
			. '<div class="gdcl-mode-toggle">'
			. '<label><input type="radio" name="mode" value="restricted"' . $modeRestrictedChecked . '><span>Restricted</span></label>'
			. '<label><input type="radio" name="mode" value="available"' . $modeAvailableChecked . '><span>Available</span></label>'
			. '</div></div>'
			. '<div><label>Restriction type <span style="opacity:.6;font-weight:400">(restricted mode only)</span></label><select name="type">' . $typeOpts . '</select></div>'
			. '<div style="flex:0 0 auto"><button type="submit" class="gdcl-submit">Search</button></div>'
			. '</div>'
			. '</form>';

		/* -- Precondition: state required. Empty state → just render the form. -- */
		if ( $stateSel === '' )
		{
			$html .= '<div class="gdcl-empty">Pick a state to search.</div></div>';
			\IPS\Output::i()->output = $html;
			return;
		}

		$stateName = self::STATE_NAMES[ $stateSel ] ?? $stateSel;

		if ( $mode === 'available' )
		{
			$html .= $this->buildAvailableResults( $stateSel, $stateName, $cat, $brand, $page );
		}
		else
		{
			$html .= $this->buildRestrictedResults( $stateSel, $stateName, $cat, $type, $brand, $page );
		}

		$html .= '</div>';
		\IPS\Output::i()->output = $html;
	}

	/**
	 * RESTRICTED mode result section for the advanced search.
	 * Native select()->join() only — no raw preparedQuery.
	 */
	protected function buildRestrictedResults( string $stateCode, string $stateName, string $cat, string $type, string $brand, int $page ): string
	{
		$h = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );

		$whereParts = [ 'f.state_code=?' ];
		$whereArgs  = [ $stateCode ];

		[ $typeFrag, $typeArgs ] = $this->typeFilterClause( $type, 'f' );
		$whereParts[] = $typeFrag;
		foreach ( $typeArgs as $a ) { $whereArgs[] = $a; }

		if ( $cat !== '' )
		{
			$catIds = $this->categoryIdsForType( $cat );
			if ( empty( $catIds ) )
			{
				/* User picked a category that maps to zero rows in this
				   install — force an empty result rather than crashing on
				   IN (). */
				return '<p class="gdcl-count">No products match the category filter for this install.</p></div>';
			}
			$placeholders = implode( ',', array_fill( 0, count( $catIds ), '?' ) );
			$whereParts[] = 'c.category_id IN (' . $placeholders . ')';
			foreach ( $catIds as $id ) { $whereArgs[] = (int) $id; }
		}

		if ( $brand !== '' )
		{
			$whereParts[] = '( c.brand LIKE ? OR c.manufacturer LIKE ? )';
			$whereArgs[]  = '%' . $brand . '%';
			$whereArgs[]  = '%' . $brand . '%';
		}

		$whereWithArgs = array_merge( [ implode( ' AND ', $whereParts ) ], $whereArgs );

		$total = 0;
		try
		{
			$total = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				[ 'gd_compliance_flags', 'f' ],
				$whereWithArgs
			)->join( [ 'gd_catalog', 'c' ], 'c.upc=f.upc', 'LEFT' )->first();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'search restricted count: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$per        = self::PER_PAGE;
		$totalPages = max( 1, (int) ceil( $total / $per ) );
		if ( $page > $totalPages ) { $page = $totalPages; }
		$offset = ( $page - 1 ) * $per;

		$rows = [];
		try
		{
			$iter = \IPS\Db::i()->select(
				'f.upc, f.firearm_type, f.reason, f.citation, c.title, c.brand, c.manufacturer',
				[ 'gd_compliance_flags', 'f' ],
				$whereWithArgs,
				'f.firearm_type ASC, f.upc ASC',
				[ $offset, $per ]
			)->join( [ 'gd_catalog', 'c' ], 'c.upc=f.upc', 'LEFT' );
			foreach ( $iter as $r ) { $rows[] = $r; }
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'search restricted rows: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$out = '<p class="gdcl-count">Restricted matches in <strong>' . $h( $stateName ) . '</strong>: <strong>' . number_format( $total ) . '</strong></p>';

		if ( empty( $rows ) )
		{
			$out .= '<div class="gdcl-empty">No restricted items match those filters in ' . $h( $stateName ) . '.</div>';
			return $out;
		}

		$out .= '<div class="gdcl-list">';
		foreach ( $rows as $r )
		{
			$upc     = (string) ( $r['upc']    ?? '' );
			$ftype   = (string) ( $r['firearm_type'] ?? '' );
			$reason  = (string) ( $r['reason'] ?? '' );
			$cite    = (string) ( $r['citation'] ?? '' );
			$title   = (string) ( $r['title']  ?? '' );
			$brandC  = (string) ( $r['brand']  ?? ( $r['manufacturer'] ?? '' ) );
			$titleLine = trim( ( $brandC !== '' ? $brandC . ' — ' : '' ) . $title );
			if ( $titleLine === '' ) { $titleLine = 'Item (not in catalog metadata)'; }

			$out .= '<div class="gdcl-list-row">'
				. '<div class="main">'
				. '<span class="gdcl-type-badge gdcl-type-' . $h( self::typeBadgeClass( $ftype ) ) . '">' . $h( self::flagTypeLabel( $ftype ) ) . '</span> '
				. '<span class="upc">' . $h( $upc ) . '</span>'
				. '<p class="title">' . $h( $titleLine ) . '</p>'
				. ( $reason !== '' ? '<p class="reason">' . $h( $reason ) . '</p>' : '' )
				. ( $cite   !== '' ? '<p class="cite">Citation: ' . $h( $cite ) . '</p>' : '' )
				. '</div>'
				. $this->rowReportLink( $upc, $stateCode )
				. '</div>';
		}
		$out .= '</div>';

		$out .= $this->pager( $page, $totalPages, 'search', [
			'state' => $stateCode,
			'mode'  => 'restricted',
			'cat'   => $cat,
			'type'  => $type,
			'brand' => $brand,
		] );

		return $out;
	}

	/**
	 * AVAILABLE mode result section for the advanced search.
	 *
	 * REQUIRES at least one of {category, brand} — a bare state-only
	 * available query is refused (would enumerate ~catalog).
	 *
	 * "Available" = product exists in gd_catalog AND has no non-advisory
	 * flag for the selected state. Advisory items ARE still available
	 * (they carry a buyer requirement but can ship).
	 */
	protected function buildAvailableResults( string $stateCode, string $stateName, string $cat, string $brand, int $page ): string
	{
		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		$lang = \IPS\Member::loggedIn()->language();

		if ( $cat === '' && $brand === '' )
		{
			return '<div class="gdcl-warn"><strong>Filter required.</strong> The "available" list is too large to render without at least one of Category or Brand set. Pick a category (Handguns / Rifles / Shotguns) or type a brand to run this query.</div>';
		}

		$whereParts = [ 'f.id IS NULL' ];
		$whereArgs  = [];

		if ( $cat !== '' )
		{
			$catIds = $this->categoryIdsForType( $cat );
			if ( empty( $catIds ) )
			{
				return '<p class="gdcl-count">No products match the category filter for this install.</p>';
			}
			$placeholders = implode( ',', array_fill( 0, count( $catIds ), '?' ) );
			$whereParts[] = 'c.category_id IN (' . $placeholders . ')';
			foreach ( $catIds as $id ) { $whereArgs[] = (int) $id; }
		}
		if ( $brand !== '' )
		{
			$whereParts[] = '( c.brand LIKE ? OR c.manufacturer LIKE ? )';
			$whereArgs[]  = '%' . $brand . '%';
			$whereArgs[]  = '%' . $brand . '%';
		}

		$whereWithArgs = array_merge( [ implode( ' AND ', $whereParts ) ], $whereArgs );

		/* LEFT JOIN gd_compliance_flags on upc+state+NON-advisory. Advisory
		   flags do NOT exclude a product from "available" — a buyer
		   requirement is still an available item, just with a note.
		   IPS join clauses don't take bound params, so embed the state
		   literal directly. Safe: $stateCode was already whitelisted
		   against STATE_NAMES above (never user-controlled beyond the
		   50 known keys). */
		$safeState = strtoupper( $stateCode );
		$joinOn = "f.upc=c.upc AND f.state_code='" . $safeState . "' AND f.firearm_type<>'advisory'";

		$total = 0;
		try
		{
			$total = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				[ 'gd_catalog', 'c' ],
				$whereWithArgs
			)->join( [ 'gd_compliance_flags', 'f' ], $joinOn, 'LEFT' )->first();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'search available count: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$per        = self::PER_PAGE;
		$totalPages = max( 1, (int) ceil( $total / $per ) );
		if ( $page > $totalPages ) { $page = $totalPages; }
		$offset = ( $page - 1 ) * $per;

		$rows = [];
		try
		{
			$iter = \IPS\Db::i()->select(
				'c.upc, c.title, c.brand, c.manufacturer',
				[ 'gd_catalog', 'c' ],
				$whereWithArgs,
				'c.title ASC',
				[ $offset, $per ]
			)->join( [ 'gd_compliance_flags', 'f' ], $joinOn, 'LEFT' );
			foreach ( $iter as $r ) { $rows[] = $r; }
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'search available rows: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$note = trim( (string) ( \IPS\Settings::i()->gdcompliance_lookup_available_note ?? '' ) );
		if ( $note === '' ) { $note = (string) $lang->addToStack( 'gdcompliance_lookup_available_note' ); }

		$out = '<div class="gdcl-note"><strong>Please verify before purchasing.</strong><br>' . nl2br( $h( $note ) ) . '</div>'
			. '<p class="gdcl-count">Not restricted for <strong>' . $h( $stateName ) . '</strong>: <strong>' . number_format( $total ) . '</strong> matching items</p>';

		if ( empty( $rows ) )
		{
			$out .= '<div class="gdcl-empty">No available items match those filters in ' . $h( $stateName ) . '.</div>';
			return $out;
		}

		$out .= '<div class="gdcl-list">';
		foreach ( $rows as $r )
		{
			$upc    = (string) ( $r['upc']   ?? '' );
			$title  = (string) ( $r['title'] ?? '' );
			$brandC = (string) ( $r['brand'] ?? ( $r['manufacturer'] ?? '' ) );
			$titleLine = trim( ( $brandC !== '' ? $brandC . ' — ' : '' ) . $title );
			if ( $titleLine === '' ) { $titleLine = 'Item'; }

			$out .= '<div class="gdcl-list-row">'
				. '<div class="main">'
				. '<span class="gdcl-type-badge gdcl-type-adv">Available</span> '
				. '<span class="upc">' . $h( $upc ) . '</span>'
				. '<p class="title">' . $h( $titleLine ) . '</p>'
				. '<p class="meta">Not restricted for ' . $h( $stateName ) . '.</p>'
				. '</div>'
				. $this->rowReportLink( $upc, $stateCode )
				. '</div>';
		}
		$out .= '</div>';

		$out .= $this->pager( $page, $totalPages, 'search', [
			'state' => $stateCode,
			'mode'  => 'available',
			'cat'   => $cat,
			'brand' => $brand,
		] );

		return $out;
	}

	/* =====================================================================
	 * Stage 3 — Full-State Restricted List + CSV export (?do=statelist)
	 *
	 *   ?do=statelist&state=XX             → HTML list (paginated), filterable
	 *                                          by restriction type
	 *   ?do=statelist&state=XX&export=csv  → text/csv download, capped at
	 *                                          gdcompliance_lookup_csv_max
	 *                                          (default 50000) rows
	 * ===================================================================== */
	public function statelist(): void
	{
		if ( (int) ( \IPS\Settings::i()->gdcompliance_lookup_enabled ?? 1 ) !== 1 )
		{
			$this->renderDisabled();
			return;
		}

		$stateSel = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( !isset( self::STATE_NAMES[ $stateSel ] ) ) { $stateSel = ''; }

		/* CSV export short-circuits everything and streams directly. */
		if ( $stateSel !== '' && (string) ( \IPS\Request::i()->export ?? '' ) === 'csv' )
		{
			$this->streamRestrictedCsv( $stateSel );
			return;
		}

		$type = (string) ( \IPS\Request::i()->type ?? '' );
		if ( !isset( self::TYPE_CHOICES[ $type ] ) ) { $type = ''; }

		$page = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );

		$h    = fn( string $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		$html = $this->pageChrome( 'statelist', $stateSel );

		/* Filter form. */
		$formUrl = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=lookup&controller=lookup&do=statelist',
			'front', 'gdcompliance_state_lookup'
		);
		$typeOpts = '';
		foreach ( self::TYPE_CHOICES as $k => $v )
		{
			$sel = ( $k === $type ) ? ' selected' : '';
			$typeOpts .= '<option value="' . $h( $k ) . '"' . $sel . '>' . $h( $v ) . '</option>';
		}

		$html .= ''
			. '<form method="get" action="' . $h( $formUrl ) . '" class="gdcl-filters">'
			. '<input type="hidden" name="do" value="statelist">'
			. '<div class="row">'
			. '<div><label>State</label>' . $this->stateSelectHtml( $stateSel ) . '</div>'
			. '<div><label>Restriction type</label><select name="type">' . $typeOpts . '</select></div>'
			. '<div style="flex:0 0 auto"><label>&nbsp;</label><button type="submit" class="gdcl-submit">List</button></div>'
			. '</div>'
			. '</form>';

		if ( $stateSel === '' )
		{
			$html .= '<div class="gdcl-empty">Pick a state to view its full restricted list.</div></div>';
			\IPS\Output::i()->output = $html;
			return;
		}

		$stateName = self::STATE_NAMES[ $stateSel ] ?? $stateSel;

		/* Build the same WHERE as restricted-mode search but without
		   category/brand filters — this is the full-state view. */
		$whereParts = [ 'f.state_code=?' ];
		$whereArgs  = [ $stateSel ];
		[ $typeFrag, $typeArgs ] = $this->typeFilterClause( $type, 'f' );
		$whereParts[] = $typeFrag;
		foreach ( $typeArgs as $a ) { $whereArgs[] = $a; }
		$whereWithArgs = array_merge( [ implode( ' AND ', $whereParts ) ], $whereArgs );

		$total = 0;
		try
		{
			$total = (int) \IPS\Db::i()->select(
				'COUNT(*)',
				[ 'gd_compliance_flags', 'f' ],
				$whereWithArgs
			)->join( [ 'gd_catalog', 'c' ], 'c.upc=f.upc', 'LEFT' )->first();
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'statelist count: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		$per        = self::PER_PAGE;
		$totalPages = max( 1, (int) ceil( $total / $per ) );
		if ( $page > $totalPages ) { $page = $totalPages; }
		$offset = ( $page - 1 ) * $per;

		$exportUrl = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=lookup&controller=lookup&do=statelist&state=' . rawurlencode( $stateSel ) . '&export=csv',
			'front', 'gdcompliance_state_lookup'
		);

		$html .= '<div class="gdcl-actions">'
			. '<a href="' . $h( $exportUrl ) . '" class="btn">⬇ Download CSV (restricted-' . $h( $stateSel ) . '.csv)</a>'
			. '</div>'
			. '<p class="gdcl-count">Restricted in <strong>' . $h( $stateName ) . '</strong>: <strong>' . number_format( $total ) . '</strong> items</p>';

		$rows = [];
		try
		{
			$iter = \IPS\Db::i()->select(
				'f.upc, f.firearm_type, f.reason, f.citation, c.title, c.brand, c.manufacturer',
				[ 'gd_compliance_flags', 'f' ],
				$whereWithArgs,
				'f.firearm_type ASC, f.upc ASC',
				[ $offset, $per ]
			)->join( [ 'gd_catalog', 'c' ], 'c.upc=f.upc', 'LEFT' );
			foreach ( $iter as $r ) { $rows[] = $r; }
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'statelist rows: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		if ( empty( $rows ) )
		{
			$html .= '<div class="gdcl-empty">No restricted items found in ' . $h( $stateName ) . '.</div></div>';
			\IPS\Output::i()->output = $html;
			return;
		}

		$html .= '<div class="gdcl-list">';
		foreach ( $rows as $r )
		{
			$upc    = (string) ( $r['upc']    ?? '' );
			$ftype  = (string) ( $r['firearm_type'] ?? '' );
			$reason = (string) ( $r['reason'] ?? '' );
			$cite   = (string) ( $r['citation'] ?? '' );
			$title  = (string) ( $r['title']  ?? '' );
			$brandC = (string) ( $r['brand']  ?? ( $r['manufacturer'] ?? '' ) );
			$titleLine = trim( ( $brandC !== '' ? $brandC . ' — ' : '' ) . $title );
			if ( $titleLine === '' ) { $titleLine = 'Item (not in catalog metadata)'; }

			$html .= '<div class="gdcl-list-row">'
				. '<div class="main">'
				. '<span class="gdcl-type-badge gdcl-type-' . $h( self::typeBadgeClass( $ftype ) ) . '">' . $h( self::flagTypeLabel( $ftype ) ) . '</span> '
				. '<span class="upc">' . $h( $upc ) . '</span>'
				. '<p class="title">' . $h( $titleLine ) . '</p>'
				. ( $reason !== '' ? '<p class="reason">' . $h( $reason ) . '</p>' : '' )
				. ( $cite   !== '' ? '<p class="cite">Citation: ' . $h( $cite ) . '</p>' : '' )
				. '</div>'
				. $this->rowReportLink( $upc, $stateSel )
				. '</div>';
		}
		$html .= '</div>';

		$html .= $this->pager( $page, $totalPages, 'statelist', [
			'state' => $stateSel,
			'type'  => $type,
		] );

		$html .= '</div>';
		\IPS\Output::i()->output = $html;
	}

	/**
	 * Stream the state's full restricted list as CSV. Bounded by
	 * gdcompliance_lookup_csv_max (default 50000). Content-Disposition
	 * attachment + safe filename. Open access (public compliance info).
	 *
	 * CSV columns: upc, title, brand, restriction_type, reason, citation.
	 * First 4 rows are a header comment carrying the disclaimer (Excel-
	 * safe: prefixed with '#' in the first cell so it renders as a
	 * comment column rather than corrupting the data grid).
	 */
	protected function streamRestrictedCsv( string $stateCode ): void
	{
		$max = (int) ( \IPS\Settings::i()->gdcompliance_lookup_csv_max ?? 50000 );
		if ( $max < 100 || $max > 200000 ) { $max = 50000; }

		$disclaimer = trim( (string) ( \IPS\Settings::i()->gdcompliance_lookup_disclaimer ?? '' ) );
		if ( $disclaimer === '' )
		{
			$disclaimer = 'This CSV lists items our compliance engine flagged for ' . $stateCode . '. Verify each entry against current state and local law and your FFL before relying on it. Gun Wise LLC assumes no liability for reliance on this list.';
		}
		$stateName = self::STATE_NAMES[ $stateCode ] ?? $stateCode;

		$tmp = tempnam( sys_get_temp_dir(), 'gdcompl_csv_' );
		$fh  = fopen( $tmp, 'w' );

		fputcsv( $fh, [ '# GunRack.deals compliance export — restricted items for ' . $stateName . ' (' . $stateCode . ')' ] );
		fputcsv( $fh, [ '# Generated: ' . date( 'Y-m-d H:i' ) . ' UTC' ] );
		fputcsv( $fh, [ '# Disclaimer: ' . $disclaimer ] );
		fputcsv( $fh, [ 'upc', 'title', 'brand', 'restriction_type', 'reason', 'citation' ] );

		$count = 0;
		try
		{
			$iter = \IPS\Db::i()->select(
				'f.upc, f.firearm_type, f.reason, f.citation, c.title, c.brand, c.manufacturer',
				[ 'gd_compliance_flags', 'f' ],
				[ 'f.state_code=?', $stateCode ],
				'f.firearm_type ASC, f.upc ASC',
				[ 0, $max ]
			)->join( [ 'gd_catalog', 'c' ], 'c.upc=f.upc', 'LEFT' );
			foreach ( $iter as $r )
			{
				$brandC = (string) ( $r['brand'] ?? ( $r['manufacturer'] ?? '' ) );
				fputcsv( $fh, [
					(string) ( $r['upc']    ?? '' ),
					(string) ( $r['title']  ?? '' ),
					$brandC,
					self::flagTypeLabel( (string) ( $r['firearm_type'] ?? '' ) ),
					(string) ( $r['reason'] ?? '' ),
					(string) ( $r['citation'] ?? '' ),
				] );
				$count++;
				if ( $count >= $max ) { break; }
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'streamRestrictedCsv: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		fclose( $fh );
		$body = (string) file_get_contents( $tmp );
		@unlink( $tmp );

		$safeFname = 'restricted-' . preg_replace( '/[^A-Z]/', '', strtoupper( $stateCode ) ) . '.csv';
		\IPS\Output::i()->sendOutput(
			$body,
			200,
			'text/csv',
			[
				'Content-Disposition' => 'attachment; filename="' . $safeFname . '"',
				'Cache-Control'       => 'no-store, no-cache, must-revalidate',
				'Pragma'              => 'no-cache',
			],
			FALSE,
			FALSE,
			FALSE
		);
	}
}

class lookup extends _lookup {}
