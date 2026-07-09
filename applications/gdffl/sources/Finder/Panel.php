<?php
/**
 * @brief  GD FFL Finder — embeddable "Find an FFL" button + modal (Stage 3).
 *
 * v1.0.16 replaces v1.0.15's collapsible below-offers panel
 * with a compact button that lives in the price-comparison
 * chart header (top-right, by the sort control). Clicking it
 * opens a modal — same search endpoint, same result-card
 * design — so the buyer finds a transfer FFL without leaving
 * the product page AND the price comparison stays uncluttered.
 *
 * gdsearch calls \IPS\gdffl\Finder\Panel::renderButton( $upc )
 * from a triple-guarded try/catch (mirrors the v1.0.82
 * gdreviews shared-render pattern). A missing / broken /
 * disabled gdffl leaves $fflLocatorHtml as '' → the button
 * simply doesn't render and the page is unaffected.
 *
 * `render()` is kept around as a no-op returning '' so a
 * gdsearch install still on v1.0.83 (which called ::render())
 * doesn't error while both apps are being upgraded in the
 * same maintenance window.
 *
 * gd_ffl / gd_zip_geo are read exclusively through the finder's
 * existing do=search JSON endpoint (fetch call from the modal).
 * This class writes zero database rows.
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
	 * v1.0.15 shim — retained so a gdsearch install still on
	 * v1.0.83 (which called Panel::render()) doesn't crash if
	 * gdffl is upgraded first. Returns '' so no unwanted below-
	 * offers panel renders while both apps are mid-upgrade.
	 */
	public static function render( string $upc = '' ): string
	{
		return '';
	}

	/**
	 * Render the outlined "Find an FFL" button plus the hidden
	 * modal it opens. gdsearch drops the returned HTML into the
	 * price-comparison chart header via {$fflLocatorHtml|raw}.
	 *
	 * Guaranteed never to raise: any internal failure returns
	 * '' so the caller's `.$fflLocatorHtml` template output is
	 * safe against a broken gdffl.
	 *
	 * $upc is accepted for future per-item logic but the current
	 * render doesn't gate on it — the button appears on every
	 * product page.
	 */
	public static function renderButton( string $upc = '' ): string
	{
		try
		{
			return self::renderButtonInner( $upc );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdffl locator button: ' . $e->getMessage(), 'gdffl' ); } catch ( \Throwable ) {}
			return '';
		}
	}

	protected static function renderButtonInner( string $upc ): string
	{
		$esc = fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		/* Enqueue the finder's own stylesheet so the SAME
		   .gr5 .gdffl-* rules used on /ffl-finder style the
		   modal's result cards (distance chips, phone pills,
		   type tags, spinner). CSS enqueue MUST use
		   \IPS\Theme::i()->css() — the css helper on
		   \IPS\Output does not exist (that was the v1.0.11
		   bug — don't reintroduce it). */
		try
		{
			\IPS\Output::i()->cssFiles = array_merge(
				\IPS\Output::i()->cssFiles,
				\IPS\Theme::i()->css( 'finder.css', 'gdffl', 'interface' )
			);
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdffl locator css enqueue: ' . $e->getMessage(), 'gdffl' ); } catch ( \Throwable ) {}
		}

		/* Absolute URL — the modal fetches from gdffl's front
		   controller, not gdsearch's. */
		$searchUrl = (string) \IPS\Http\Url::internal(
			'app=gdffl&module=finder&controller=finder&do=search',
			'front'
		);

		/* Radius <option> list — same set as the standalone
		   finder. Default from ACP setting when present. */
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

		/* Inline SVG icons — no external CDN dependency. */
		$iconPin =
			'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/>'
			. '<circle cx="12" cy="10" r="3"/>'
			. '</svg>';
		$iconPinBig =
			'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/>'
			. '<circle cx="12" cy="10" r="3"/>'
			. '</svg>';
		$iconSearchSm =
			'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>'
			. '</svg>';
		$iconSearchBtn = $iconSearchSm;
		$iconX =
			'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>'
			. '</svg>';

		/* Labels — hard-coded English for now; can be promoted
		   to lang keys later if translation matters. */
		$btnTxt      = 'Find an FFL';
		$modalTitle  = 'Find an FFL near you';
		$modalSub    = 'Enter your ZIP to find nearby dealers who can receive your transfer.';
		$closeTxt    = 'Close';
		$zipLabel    = 'ZIP code';
		$radLabel    = 'Radius';
		$submitTxt   = 'Search';

		/* --- Button ------------------------------------------ */
		$btn  = '<button type="button" class="gdffl-locator-btn" data-gdffl-locator-open aria-haspopup="dialog">';
		$btn .= '<span class="gdffl-locator-btn__icon" aria-hidden="true">' . $iconPin . '</span>';
		$btn .= '<span>' . $esc( $btnTxt ) . '</span>';
		$btn .= '</button>';

		/* --- Modal (hidden until opened) --------------------- */
		$modal  = '<div class="gr5 gdffl-locator-overlay" data-gdffl-locator-overlay role="dialog" aria-modal="true" aria-labelledby="gdffl-loc-title" hidden>';
		$modal .= '<div class="gdffl-locator-card">';

		/* Header — navy. */
		$modal .= '<div class="gdffl-locator-header">';
		$modal .= '<div class="gdffl-locator-header__row">';
		$modal .= '<span class="gdffl-locator-header__icon">' . $iconPinBig . '</span>';
		$modal .= '<h2 class="gdffl-locator-header__title" id="gdffl-loc-title">' . $esc( $modalTitle ) . '</h2>';
		$modal .= '<button type="button" class="gdffl-locator-close" data-gdffl-locator-close aria-label="' . $esc( $closeTxt ) . '">' . $iconX . '</button>';
		$modal .= '</div>';
		$modal .= '<div class="gdffl-locator-header__sub">' . $esc( $modalSub ) . '</div>';
		$modal .= '</div>';

		/* Search form. */
		$modal .= '<form class="gdffl-locator-searchbar" data-gdffl-locator-form data-search-url="' . $esc( $searchUrl ) . '">';
		$modal .= '<div class="gdffl-row">';

		$modal .= '<div class="gdffl-field gdffl-field--zip">';
		$modal .= '<label class="gdffl-field-label" for="gdffl-loc-zip">' . $esc( $zipLabel ) . '</label>';
		$modal .= '<div class="gdffl-input-wrap">';
		$modal .= '<span class="gdffl-input-wrap__icon">' . $iconSearchSm . '</span>';
		$modal .= '<input class="gdffl-input" id="gdffl-loc-zip" type="text" inputmode="numeric" maxlength="10" pattern="[0-9\-]*" placeholder="e.g. 61938" autocomplete="postal-code" required>';
		$modal .= '</div>';
		$modal .= '</div>';

		$modal .= '<div class="gdffl-field gdffl-field--radius">';
		$modal .= '<label class="gdffl-field-label" for="gdffl-loc-radius">' . $esc( $radLabel ) . '</label>';
		$modal .= '<select id="gdffl-loc-radius">' . $radiusOpts . '</select>';
		$modal .= '</div>';

		$modal .= '<button type="submit" class="gdffl-btn">'
			. '<span aria-hidden="true">' . $iconSearchBtn . '</span>'
			. '<span>' . $esc( $submitTxt ) . '</span>'
			. '</button>';

		$modal .= '</div>';
		$modal .= '</form>';

		/* Results area (scroll). */
		$modal .= '<div class="gdffl-locator-body">';
		$modal .= '<div class="gdffl-count" id="gdffl-loc-count" hidden></div>';
		$modal .= '<div class="gdffl-status" id="gdffl-loc-status"></div>';
		$modal .= '<div class="gdffl-results" id="gdffl-loc-results" role="list"></div>';
		$modal .= '</div>';

		$modal .= '</div>'; /* /.gdffl-locator-card */
		$modal .= '</div>'; /* /.gdffl-locator-overlay */

		/* --- Inline styles (small — buttony + modal shell;
		       the result-card + form-field styles come from
		       finder.css via the enqueue above). ------------- */
		$styles = '<style>'
			/* Outlined green button for the chart header. */
			. '.gdffl-locator-btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 14px;border-radius:8px;background:#ffffff;border:1.5px solid #0f6e56;color:#0f6e56;font:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s ease,color .15s ease;line-height:1;white-space:nowrap;text-decoration:none}'
			. '.gdffl-locator-btn:hover{background:#e1f5ee;color:#0b5a46}'
			. '.gdffl-locator-btn:focus{outline:none;box-shadow:0 0 0 3px rgba(15,110,86,.2)}'
			. '.gdffl-locator-btn__icon{display:inline-flex;color:#0f6e56}'
			. '.gdffl-locator-btn__icon svg{display:block}'
			/* Modal overlay. */
			. '.gdffl-locator-overlay{position:fixed;inset:0;background:rgba(15,39,64,.55);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px}'
			. '.gdffl-locator-overlay[hidden]{display:none}'
			. '.gdffl-locator-overlay .gdffl-locator-card{background:#ffffff;border-radius:14px;max-width:560px;width:100%;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 40px rgba(15,23,42,.3);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a}'
			/* Header. */
			. '.gdffl-locator-header{background:#0f2740;color:#fff;padding:18px 22px}'
			. '.gdffl-locator-header__row{display:flex;align-items:center;gap:10px}'
			. '.gdffl-locator-header__icon{color:#5dcaa5;display:inline-flex;flex:0 0 auto}'
			. '.gdffl-locator-header__icon svg{display:block}'
			. '.gdffl-locator-header__title{margin:0;font-size:18px;font-weight:500;color:#fff;flex:1 1 auto;line-height:1.25}'
			. '.gdffl-locator-header__sub{margin:8px 0 0 30px;color:#9db4cc;font-size:13px;line-height:1.4}'
			. '.gdffl-locator-close{background:transparent;border:0;color:#9db4cc;cursor:pointer;padding:4px;border-radius:6px;display:inline-flex;transition:background .15s ease,color .15s ease}'
			. '.gdffl-locator-close:hover{background:rgba(255,255,255,.1);color:#fff}'
			. '.gdffl-locator-close svg{display:block}'
			/* Search bar. */
			. '.gdffl-locator-searchbar{background:#f8fafc;padding:16px 20px;border-bottom:1px solid #e2e8f0}'
			. '.gdffl-locator-searchbar .gdffl-row{margin:0}'
			/* Body — scrolls independently. */
			. '.gdffl-locator-body{padding:14px 20px 18px;overflow-y:auto;max-height:340px}'
			. '.gdffl-locator-body .gdffl-count{margin-top:0;margin-bottom:10px}'
			/* Small-screen. */
			. '@media (max-width:520px){'
			.   '.gdffl-locator-header__title{font-size:16px}'
			.   '.gdffl-locator-header__sub{margin-left:0;margin-top:6px}'
			.   '.gdffl-locator-searchbar{padding:14px 16px}'
			.   '.gdffl-locator-body{padding:12px 16px 16px;max-height:none;flex:1 1 auto}'
			. '}'
			. '</style>';

		/* --- Inline script — open/close + search --------- */
		$script = '<script>' . self::inlineJs() . '</script>';

		return $btn . $modal . $styles . $script;
	}

	/* ------------------------------------------------------------------
	 * Modal open/close + search + card-render JS. Self-contained so it
	 * does not touch the standalone finder's element IDs. Reads/writes
	 * the same `gdffl_zip` localStorage key so a ZIP entered here is
	 * remembered on /ffl-finder and vice-versa.
	 * ------------------------------------------------------------------ */
	protected static function inlineJs(): string
	{
		$js = <<<'JS'
(function () {
	'use strict';
	var openBtn  = document.querySelector('[data-gdffl-locator-open]');
	var overlay  = document.querySelector('[data-gdffl-locator-overlay]');
	if (!openBtn || !overlay) { return; }

	var card      = overlay.querySelector('.gdffl-locator-card');
	var closeBtns = overlay.querySelectorAll('[data-gdffl-locator-close]');
	var form      = overlay.querySelector('[data-gdffl-locator-form]');
	var zipInput  = overlay.querySelector('#gdffl-loc-zip');
	var radiusSel = overlay.querySelector('#gdffl-loc-radius');
	var statusEl  = overlay.querySelector('#gdffl-loc-status');
	var countEl   = overlay.querySelector('#gdffl-loc-count');
	var resultsEl = overlay.querySelector('#gdffl-loc-results');
	var searchUrl = form ? (form.getAttribute('data-search-url') || '') : '';

	var ZIP_KEY = 'gdffl_zip';
	var NEAR_MI = 5;

	function readStoredZip() {
		try {
			var stored = window.localStorage && window.localStorage.getItem(ZIP_KEY);
			if (stored && /^[0-9]{5}$/.test(stored)) { return stored; }
		} catch (e) {}
		return '';
	}
	function rememberZip(zip) {
		try {
			if (window.localStorage && /^[0-9]{5}$/.test(zip)) {
				window.localStorage.setItem(ZIP_KEY, zip);
			}
		} catch (e) {}
	}

	function openModal() {
		overlay.hidden = false;
		document.body.style.overflow = 'hidden';
		if (zipInput && !zipInput.value) {
			var s = readStoredZip();
			if (s) { zipInput.value = s; }
		}
		setTimeout(function () { if (zipInput) { zipInput.focus(); zipInput.select(); } }, 30);
	}
	function closeModal() {
		overlay.hidden = true;
		document.body.style.overflow = '';
	}

	openBtn.addEventListener('click', function (ev) { ev.preventDefault(); openModal(); });
	closeBtns.forEach(function (b) { b.addEventListener('click', function () { closeModal(); }); });
	overlay.addEventListener('click', function (ev) { if (ev.target === overlay) { closeModal(); } });
	document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !overlay.hidden) { closeModal(); } });

	/* --- Search + render --------------------------------- */
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
		var dist    = (r.distance_miles != null) ? Number(r.distance_miles) : null;
		var distCls = 'gdffl-distance' + (dist != null && dist < NEAR_MI ? ' gdffl-distance--near' : '');
		var distNum = dist === null ? '?' : (Number.isInteger(dist) ? String(dist) : dist.toFixed(1));
		var biz     = r.business_name || r.license_name || 'FFL';
		var addr    = [r.street, [r.city, r.state].filter(Boolean).join(', '), r.zip].filter(Boolean).map(esc).join(', ');
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

	if (form) {
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			runSearch();
		});
	}
})();
JS;
		return $js;
	}
}
class Panel extends _Panel {}
