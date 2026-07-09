<?php
/**
 * @brief  GD FFL Finder — embeddable product-page panel (Stage 3).
 *
 * gdsearch's product page calls `\IPS\gdffl\Finder\Panel::render($upc)`
 * from a guarded try/catch (mirrors the gdreviews shared-render
 * pattern). This class returns a collapsible "Find an FFL to
 * receive your transfer" panel that reuses the finder's own
 * search endpoint + card markup.
 *
 * The returned HTML is fully self-contained:
 *   - enqueues finder.css so the .gr5 .gdffl-* styles apply
 *   - includes an inline <script> block that handles this
 *     panel's search + render (kept small; standalone
 *     interface/finder.js already handles /ffl-finder itself)
 *   - reads/writes the last ZIP under localStorage key
 *     'gdffl_zip' — same key the standalone finder uses, so
 *     the two flows stay in sync
 *   - links to /ffl-finder for the full experience
 *
 * Always shown — no firearm-only heuristic — so buyers looking
 * at ammo, receivers, or other transfer-required items also see
 * the panel.
 *
 * gd_ffl / gd_zip_geo are read exclusively through the existing
 * do=search JSON endpoint (fetch call). This class writes zero
 * database rows and touches no other app's tables.
 */

namespace IPS\gdffl\Finder;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _Panel
{
	/**
	 * Render the embeddable panel. Returns the full HTML fragment
	 * (opening details + form + results container + inline JS).
	 * Guaranteed never to raise: any internal failure returns ''
	 * so a caller's `.$fflPanelHtml` template output is safe.
	 *
	 * $upc is accepted for future use (per-item filtering) but the
	 * current render doesn't gate on it — every product page shows
	 * the panel.
	 */
	public static function render( string $upc = '' ): string
	{
		try
		{
			return self::renderInner( $upc );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdffl panel: ' . $e->getMessage(), 'gdffl' ); } catch ( \Throwable ) {}
			return '';
		}
	}

	protected static function renderInner( string $upc ): string
	{
		$esc = fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		/* Enqueue the finder's stylesheet so .gr5 .gdffl-* rules
		   apply on the product page too. Uses \IPS\Theme::i()->css
		   — NOT \IPS\Output::i()->css (which doesn't exist — that
		   was the v1.0.11 bug). */
		try
		{
			\IPS\Output::i()->cssFiles = array_merge(
				\IPS\Output::i()->cssFiles,
				\IPS\Theme::i()->css( 'finder.css', 'gdffl', 'interface' )
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdffl panel css enqueue: ' . $e->getMessage(), 'gdffl' ); } catch ( \Throwable ) {}
		}

		/* Absolute URLs — the panel embeds on the product page
		   which lives under a different route, so relative won't
		   work. Both point at gdffl's own front controller. */
		$searchUrl = (string) \IPS\Http\Url::internal(
			'app=gdffl&module=finder&controller=finder&do=search',
			'front'
		);
		$finderUrl = (string) \IPS\Http\Url::internal(
			'app=gdffl&module=finder&controller=finder',
			'front'
		);

		/* Radius <option> list — matches the standalone page's set. */
		$radiusOptions = [ 5, 10, 25, 50, 100 ];
		$defaultRadius = 25;
		try
		{
			$defaultRadius = (int) \IPS\Settings::i()->gdffl_default_radius ?: 25;
		}
		catch ( \Throwable ) {}
		$radiusOpts = '';
		foreach ( $radiusOptions as $r )
		{
			$sel = ( $r === $defaultRadius ) ? ' selected' : '';
			$radiusOpts .= '<option value="' . (int) $r . '"' . $sel . '>' . (int) $r . ' mi</option>';
		}

		/* Inline SVGs — no external CDN dependency. */
		$iconPin =
			'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/>'
			. '<circle cx="12" cy="10" r="3"/>'
			. '</svg>';
		$iconChevron =
			'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<polyline points="6 9 12 15 18 9"/>'
			. '</svg>';
		$iconSearchSm =
			'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>'
			. '</svg>';
		$iconSearchBtn = $iconSearchSm;
		$iconArrow =
			'<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/>'
			. '</svg>';

		/* Panel labels — hard-coded English for now; can be
		   promoted to lang keys later if translation is needed. */
		$titleTxt   = 'Find an FFL to receive your transfer';
		$hintTxt    = 'Enter your ZIP code to find nearby licensed dealers.';
		$zipLabel   = 'ZIP code';
		$radLabel   = 'Radius';
		$submitTxt  = 'Search';
		$openTxt    = 'Open full FFL finder';

		/* Build the panel HTML. Uses <details>/<summary> so
		   there's zero JS required just to open it (the search
		   itself needs JS, but the collapse doesn't). */
		$html  = '<div class="gr5 gdffl-panel-wrap">';
		$html .= '<details class="gdffl-panel" data-gdffl-panel>';
		$html .= '<summary class="gdffl-panel__summary">';
		$html .= '<span class="gdffl-panel__summary-icon">' . $iconPin . '</span>';
		$html .= '<span class="gdffl-panel__summary-text">' . $esc( $titleTxt ) . '</span>';
		$html .= '<span class="gdffl-panel__summary-chevron">' . $iconChevron . '</span>';
		$html .= '</summary>';

		$html .= '<div class="gdffl-panel__body">';
		$html .= '<p class="gdffl-panel__hint">' . $esc( $hintTxt ) . '</p>';

		/* Compact search form. IDs prefixed with gdffl-p- so the
		   standalone finder's IDs (gdffl-zip, gdfflForm, …) never
		   collide when both live on the same document. */
		$html .= '<form class="gdffl-panel__form" data-gdffl-panel-form data-search-url="' . $esc( $searchUrl ) . '">';
		$html .= '<div class="gdffl-row">';

		$html .= '<div class="gdffl-field gdffl-field--zip">';
		$html .= '<label class="gdffl-field-label" for="gdffl-p-zip">' . $esc( $zipLabel ) . '</label>';
		$html .= '<div class="gdffl-input-wrap">';
		$html .= '<span class="gdffl-input-wrap__icon">' . $iconSearchSm . '</span>';
		$html .= '<input class="gdffl-input" id="gdffl-p-zip" type="text" inputmode="numeric" maxlength="10" pattern="[0-9\-]*" placeholder="e.g. 61938" autocomplete="postal-code" required>';
		$html .= '</div>';
		$html .= '</div>';

		$html .= '<div class="gdffl-field gdffl-field--radius">';
		$html .= '<label class="gdffl-field-label" for="gdffl-p-radius">' . $esc( $radLabel ) . '</label>';
		$html .= '<select id="gdffl-p-radius">' . $radiusOpts . '</select>';
		$html .= '</div>';

		$html .= '<button type="submit" class="gdffl-btn">'
			. '<span aria-hidden="true">' . $iconSearchBtn . '</span>'
			. '<span>' . $esc( $submitTxt ) . '</span>'
			. '</button>';

		$html .= '</div>';
		$html .= '</form>';

		$html .= '<div class="gdffl-count" id="gdffl-p-count" hidden></div>';
		$html .= '<div class="gdffl-status" id="gdffl-p-status"></div>';
		$html .= '<div class="gdffl-results" id="gdffl-p-results" role="list"></div>';

		$html .= '<div class="gdffl-panel__footer">';
		$html .= '<a class="gdffl-panel__open-full" href="' . $esc( $finderUrl ) . '">'
			. $esc( $openTxt ) . ' <span aria-hidden="true">' . $iconArrow . '</span>'
			. '</a>';
		$html .= '</div>';

		$html .= '</div>';   /* /gdffl-panel__body */
		$html .= '</details>';
		$html .= '</div>';   /* /gdffl-panel-wrap */

		/* Panel-specific styles (small — just the header row +
		   footer link). Everything else reuses finder.css. */
		$html .= '<style>'
			. '.gr5 .gdffl-panel-wrap{max-width:none;margin:16px 0 0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a}'
			. '.gr5 .gdffl-panel{border:1px solid #e2e8f0;border-radius:12px;background:#ffffff;overflow:hidden}'
			. '.gr5 .gdffl-panel__summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:10px;padding:14px 18px;background:#0f2740;color:#ffffff;font-size:15px;font-weight:500;user-select:none}'
			. '.gr5 .gdffl-panel__summary::-webkit-details-marker{display:none}'
			. '.gr5 .gdffl-panel__summary-icon{color:#5dcaa5;display:inline-flex}'
			. '.gr5 .gdffl-panel__summary-icon svg,.gr5 .gdffl-panel__summary-chevron svg{display:block}'
			. '.gr5 .gdffl-panel__summary-text{flex:1 1 auto}'
			. '.gr5 .gdffl-panel__summary-chevron{color:#9db4cc;transition:transform .15s ease}'
			. '.gr5 .gdffl-panel[open] .gdffl-panel__summary-chevron{transform:rotate(180deg)}'
			. '.gr5 .gdffl-panel__body{padding:16px 20px 18px;background:#f8fafc;border-top:1px solid #e2e8f0}'
			. '.gr5 .gdffl-panel__hint{margin:0 0 12px;color:#64748b;font-size:13.5px;line-height:1.4}'
			. '.gr5 .gdffl-panel__form{margin:0 0 14px}'
			. '.gr5 .gdffl-panel__footer{margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end}'
			. '.gr5 .gdffl-panel__open-full{color:#0f6e56;font-size:13px;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;gap:4px}'
			. '.gr5 .gdffl-panel__open-full:hover{color:#0b5a46;text-decoration:underline}'
			. '.gr5 .gdffl-panel__open-full svg{display:block}'
			. '</style>';

		/* Panel search JS — self-contained, does not touch the
		   standalone finder's element IDs. Reads / writes the
		   same `gdffl_zip` localStorage key so ZIP is remembered
		   across pages (and pre-filled from wherever the buyer
		   last searched, panel or standalone). */
		$html .= '<script>' . self::inlineJs() . '</script>';

		return $html;
	}

	/* ------------------------------------------------------------------
	 * INTERNAL — inline JS for the panel. Kept as a heredoc so we can
	 * edit it as JS, but everything is UTF-8 plain text with no PHP
	 * interpolation (no `$` variables from PHP context leak into the
	 * script — the string is emitted verbatim).
	 * ------------------------------------------------------------------ */
	protected static function inlineJs(): string
	{
		$js = <<<'JS'
(function () {
	'use strict';
	var panelForm = document.querySelector('[data-gdffl-panel-form]');
	if (!panelForm) { return; }
	var zipInput  = document.getElementById('gdffl-p-zip');
	var radiusSel = document.getElementById('gdffl-p-radius');
	var countEl   = document.getElementById('gdffl-p-count');
	var statusEl  = document.getElementById('gdffl-p-status');
	var resultsEl = document.getElementById('gdffl-p-results');
	var searchUrl = panelForm.getAttribute('data-search-url') || '';

	/* Same localStorage key as the standalone finder. */
	var ZIP_KEY = 'gdffl_zip';
	try {
		if (zipInput && !zipInput.value) {
			var stored = window.localStorage && window.localStorage.getItem(ZIP_KEY);
			if (stored && /^[0-9]{5}$/.test(stored)) { zipInput.value = stored; }
		}
	} catch (e) {}
	function rememberZip(zip) {
		try {
			if (window.localStorage && /^[0-9]{5}$/.test(zip)) {
				window.localStorage.setItem(ZIP_KEY, zip);
			}
		} catch (e) {}
	}

	var NEAR_MI = 5;

	function esc(str) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(String(str == null ? '' : str)));
		return d.innerHTML;
	}
	function escAttr(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
			.replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}
	function fmtPhone(raw) {
		var d = String(raw || '').replace(/[^0-9]/g, '');
		if (d.length === 10) { return '(' + d.substr(0,3) + ') ' + d.substr(3,3) + '-' + d.substr(6,4); }
		if (d.length === 11 && d.charAt(0) === '1') { return '1 (' + d.substr(1,3) + ') ' + d.substr(4,3) + '-' + d.substr(7,4); }
		return String(raw || '');
	}
	function iconPhone() {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
	}
	function iconPin() {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>';
	}

	function cardHtml(r) {
		var dist = (r.distance_miles != null) ? Number(r.distance_miles) : null;
		var distCls = 'gdffl-distance' + (dist != null && dist < NEAR_MI ? ' gdffl-distance--near' : '');
		var distNum = dist === null ? '?' : (Number.isInteger(dist) ? String(dist) : dist.toFixed(1));
		var biz  = r.business_name || r.license_name || 'FFL';
		var addr = [r.street, [r.city, r.state].filter(Boolean).join(', '), r.zip].filter(Boolean).map(esc).join(', ');
		var typeText = r.lic_type
			? (esc(r.lic_type) + ((r.lic_type_label && r.lic_type_label !== r.lic_type) ? ' · ' + esc(r.lic_type_label) : ''))
			: '';
		var typeTag  = typeText ? '<span class="gdffl-card__type">' + typeText + '</span>' : '';
		var digits = String(r.phone || '').replace(/[^0-9]/g, '');
		var phone;
		if (digits.length >= 10) {
			phone = '<a class="gdffl-phone" href="tel:' + escAttr(digits) + '" aria-label="Call ' + escAttr(biz) + '">'
				+ iconPhone() + '<span class="gdffl-phone__num">' + esc(fmtPhone(r.phone)) + '</span></a>';
		} else {
			phone = '<span class="gdffl-phone gdffl-phone--none">No phone on file</span>';
		}
		return '<div class="gdffl-card" role="listitem">'
			+ '<div class="' + distCls + '"><span class="gdffl-distance__num">' + esc(distNum) + '</span><span class="gdffl-distance__unit">mi</span></div>'
			+ '<div class="gdffl-card__body">'
			+   '<div class="gdffl-card__name">' + esc(biz) + '</div>'
			+   (addr ? '<div class="gdffl-card__addr"><span class="gdffl-card__addr-icon">' + iconPin() + '</span><span class="gdffl-card__addr-text">' + addr + '</span></div>' : '')
			+   typeTag
			+ '</div>'
			+ phone
			+ '</div>';
	}

	function updateCount(zip, radius, total) {
		if (!countEl) { return; }
		if (total > 0) {
			countEl.hidden = false;
			countEl.innerHTML = '<b>' + total + '</b> dealer' + (total === 1 ? '' : 's')
				+ ' within ' + esc(String(radius)) + ' miles of <b>' + esc(zip) + '</b>';
		} else {
			countEl.hidden = true;
		}
	}

	function runSearch() {
		var zipDigits = String(zipInput ? zipInput.value : '').replace(/[^0-9]/g, '');
		if (zipDigits.length < 5) {
			statusEl.className = 'gdffl-status gdffl-status--err';
			statusEl.textContent = 'Please enter a 5-digit ZIP code.';
			resultsEl.innerHTML = '';
			return;
		}
		var radius = parseInt(radiusSel ? radiusSel.value : '25', 10) || 25;
		rememberZip(zipDigits);
		statusEl.className = 'gdffl-status gdffl-status--loading';
		statusEl.textContent = 'Searching…';
		resultsEl.innerHTML = '';
		if (countEl) { countEl.hidden = true; }

		var url = searchUrl + (searchUrl.indexOf('?') === -1 ? '?' : '&')
			+ 'zip=' + encodeURIComponent(zipDigits)
			+ '&radius=' + encodeURIComponent(String(radius))
			+ '&page=1';
		fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data && data.error) {
					statusEl.className = 'gdffl-status gdffl-status--err';
					if (data.error === 'zip_bad')             { statusEl.textContent = 'Please enter a 5-digit ZIP code.'; }
					else if (data.error === 'zip_not_found')  { statusEl.textContent = 'That ZIP code is not in our lookup.'; }
					else                                       { statusEl.textContent = 'Search failed — please try again.'; }
					return;
				}
				var rows = (data && data.results) || [];
				if (rows.length === 0) {
					statusEl.className = 'gdffl-status gdffl-status--empty';
					statusEl.textContent = 'No dealers within ' + radius + ' miles of ' + zipDigits + ' — try a wider radius.';
					return;
				}
				statusEl.className = 'gdffl-status';
				statusEl.textContent = '';
				var html = '';
				for (var i = 0; i < rows.length; i++) { html += cardHtml(rows[i]); }
				resultsEl.innerHTML = html;
				updateCount(zipDigits, radius, rows.length);
			})
			.catch(function () {
				statusEl.className = 'gdffl-status gdffl-status--err';
				statusEl.textContent = 'Search failed — please try again.';
			});
	}

	panelForm.addEventListener('submit', function (ev) {
		ev.preventDefault();
		runSearch();
	});
})();
JS;
		return $js;
	}
}
class Panel extends _Panel {}
