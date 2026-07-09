/* GD FFL Finder — public finder JS (v1.0.10).
 *
 * Reads init JSON from <script id="gdffl-finder-init">, wires
 * the search form, calls the do=search JSON endpoint, and
 * renders result cards. No inline `$`-prefixed variables so
 * the IPS template engine cannot mangle it (rule #46 — this
 * file is served straight from interface/ per rule #47 so the
 * template pipeline never actually touches it either, but
 * kept dollar-clean for defense in depth).
 */
(function () {
	'use strict';

	var initEl = document.getElementById('gdffl-finder-init');
	if (!initEl) { return; }
	var init;
	try { init = JSON.parse(initEl.textContent); } catch (e) { return; }

	var searchUrl = init.searchUrl || '';
	var labels    = init.labels || {};

	var form       = document.getElementById('gdfflForm');
	var zipInput   = document.getElementById('gdffl-zip');
	var radiusSel  = document.getElementById('gdffl-radius');
	var allTypes   = document.getElementById('gdffl-alltypes');
	var statusEl   = document.getElementById('gdfflStatus');
	var countEl    = document.getElementById('gdfflCount');
	var resultsEl  = document.getElementById('gdfflResults');
	var moreBtn    = document.getElementById('gdfflMore');

	var currentPage = 1;
	var lastZip     = '';
	var lastRadius  = 0;
	var lastTypes   = '';
	var totalShown  = 0;

	function selectedTypes() {
		if (allTypes && allTypes.checked) { return 'all'; }
		var boxes = document.querySelectorAll('input[type=checkbox][name=type]:checked');
		var picked = [];
		for (var i = 0; i < boxes.length; i++) { picked.push(boxes[i].value); }
		return picked.join(',');
	}

	function escapeHtml(str) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(String(str == null ? '' : str)));
		return d.innerHTML;
	}
	function escapeAttr(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	function fmtPhone(raw) {
		var digits = String(raw || '').replace(/[^0-9]/g, '');
		if (digits.length === 10) {
			return '(' + digits.substr(0, 3) + ') ' + digits.substr(3, 3) + '-' + digits.substr(6, 4);
		}
		if (digits.length === 11 && digits.charAt(0) === '1') {
			return '1 (' + digits.substr(1, 3) + ') ' + digits.substr(4, 3) + '-' + digits.substr(7, 4);
		}
		return String(raw || '');
	}

	function fmtInt(n) {
		try { return Number(n).toLocaleString(); } catch (e) { return String(n); }
	}

	/* Renders one FFL card. Structured:
	 *   .gdffl-card
	 *     .gdffl-card__head  — name (left) + distance pill (right)
	 *     .gdffl-card__addr  — multiline address
	 *     .gdffl-card__actions
	 *       .gdffl-card__actions-left  — type pill
	 *       <a.gdffl-call>             — REAL tel: anchor (not markdown)
	 */
	function cardHtml(r) {
		var biz    = r.business_name || r.license_name || 'FFL';
		var addr   = [
			r.street,
			[r.city, r.state].filter(Boolean).join(', '),
			r.zip
		].filter(Boolean).map(escapeHtml).join('<br>');

		var phoneDigits = String(r.phone || '').replace(/[^0-9]/g, '');
		var phoneCol;
		if (phoneDigits.length >= 10) {
			phoneCol = '<a class="gdffl-call" href="tel:' + escapeAttr(phoneDigits) + '"'
				+ ' aria-label="Call ' + escapeAttr(biz) + '">'
				+   '<span class="gdffl-call__icon" aria-hidden="true">' + phoneIconSvg() + '</span>'
				+   '<span class="gdffl-call__label">' + escapeHtml(fmtPhone(r.phone)) + '</span>'
				+ '</a>';
		} else {
			phoneCol = '<span class="gdffl-call gdffl-call--none">' + escapeHtml(labels.no_phone || 'No phone on file') + '</span>';
		}

		var typeLabel = r.lic_type_label || r.lic_type || '';
		var typeText  = r.lic_type
			? (escapeHtml(r.lic_type) + (typeLabel && typeLabel !== r.lic_type ? ' · ' + escapeHtml(typeLabel) : ''))
			: escapeHtml(typeLabel);
		var typePill  = typeText
			? '<span class="gdffl-pill gdffl-pill--type">' + typeText + '</span>'
			: '';

		var distStr = (r.distance_miles !== null && r.distance_miles !== undefined)
			? (String(r.distance_miles) + ' ' + (labels.distance || 'mi'))
			: '';
		var distPill = distStr
			? '<span class="gdffl-pill gdffl-pill--distance">' + escapeHtml(distStr) + '</span>'
			: '';

		return '<div class="gdffl-card" role="listitem">'
			+   '<div class="gdffl-card__head">'
			+     '<h3 class="gdffl-card__name">' + escapeHtml(biz) + '</h3>'
			+     distPill
			+   '</div>'
			+   (addr ? '<div class="gdffl-card__addr">' + addr + '</div>' : '')
			+   '<div class="gdffl-card__actions">'
			+     '<div class="gdffl-card__actions-left">' + typePill + '</div>'
			+     phoneCol
			+   '</div>'
			+ '</div>';
	}

	function phoneIconSvg() {
		/* Minimal inline SVG so we don't ship an icon font. Currently
		   used only inside the call button. */
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			+ '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>'
			+ '</svg>';
	}

	function updateCountHeader(zip, radius, total) {
		if (!countEl) { return; }
		if (total > 0) {
			countEl.hidden = false;
			countEl.innerHTML = '<b>' + fmtInt(total) + '</b> FFL' + (total === 1 ? '' : 's')
				+ ' within ' + escapeHtml(String(radius)) + ' miles of '
				+ '<b>' + escapeHtml(String(zip)) + '</b>';
		} else {
			countEl.hidden = true;
			countEl.textContent = '';
		}
	}

	function runSearch(append) {
		var zipDigits = String(zipInput ? zipInput.value : '').replace(/[^0-9]/g, '');
		if (zipDigits.length < 5) {
			statusEl.className = 'gdffl-status gdffl-status--err';
			statusEl.textContent = labels.zip_bad || 'Please enter a 5-digit ZIP code.';
			resultsEl.innerHTML = '';
			if (countEl) { countEl.hidden = true; }
			if (moreBtn) { moreBtn.hidden = true; }
			return;
		}

		var radius = parseInt(radiusSel ? radiusSel.value : '25', 10) || 25;
		var types  = selectedTypes();

		if (!append) {
			currentPage = 1;
			lastZip     = zipDigits;
			lastRadius  = radius;
			lastTypes   = types;
			totalShown  = 0;
			statusEl.className = 'gdffl-status gdffl-status--loading';
			statusEl.textContent = labels.searching || 'Searching…';
			resultsEl.innerHTML = '';
			if (countEl) { countEl.hidden = true; }
			if (moreBtn) { moreBtn.hidden = true; }
		} else {
			currentPage += 1;
			statusEl.className = 'gdffl-status gdffl-status--loading';
			statusEl.textContent = labels.searching || 'Searching…';
		}

		var params = new URLSearchParams();
		params.set('zip',    zipDigits);
		params.set('radius', String(radius));
		if (types) { params.set('types', types); }
		params.set('page',   String(currentPage));

		var sep = searchUrl.indexOf('?') === -1 ? '?' : '&';
		fetch(searchUrl + sep + params.toString(), {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		})
		.then(function (r) { return r.json(); })
		.then(function (data) {
			if (data && data.error) {
				statusEl.className = 'gdffl-status gdffl-status--err';
				if (data.error === 'zip_bad') {
					statusEl.textContent = labels.zip_bad || 'Please enter a 5-digit ZIP code.';
				} else if (data.error === 'zip_not_found') {
					statusEl.textContent = labels.zip_notfound || 'That ZIP code is not in our lookup.';
				} else {
					statusEl.textContent = labels.error || 'Search failed — please try again.';
				}
				if (!append) {
					resultsEl.innerHTML = '';
					if (countEl) { countEl.hidden = true; }
				}
				if (moreBtn) { moreBtn.hidden = true; }
				return;
			}

			var results = (data && data.results) || [];
			var per     = (data && data.per) || 20;

			if (!append && results.length === 0) {
				statusEl.className = 'gdffl-status gdffl-status--empty';
				statusEl.textContent = (labels.no_results
					|| 'No FFLs found within the selected radius. Try a wider search.');
				if (countEl) { countEl.hidden = true; }
				return;
			}

			statusEl.className = 'gdffl-status';
			statusEl.textContent = '';

			var html = '';
			for (var i = 0; i < results.length; i++) { html += cardHtml(results[i]); }
			if (append) {
				resultsEl.insertAdjacentHTML('beforeend', html);
				totalShown += results.length;
			} else {
				resultsEl.innerHTML = html;
				totalShown = results.length;
			}

			updateCountHeader(lastZip, lastRadius, totalShown);

			if (moreBtn) {
				moreBtn.hidden = ( results.length < per );
			}
		})
		.catch(function () {
			statusEl.className = 'gdffl-status gdffl-status--err';
			statusEl.textContent = labels.error || 'Search failed — please try again.';
			if (moreBtn) { moreBtn.hidden = true; }
		});
	}

	if (form) {
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			runSearch(false);
		});
	}
	if (moreBtn) {
		moreBtn.addEventListener('click', function () { runSearch(true); });
	}
	if (allTypes) {
		allTypes.addEventListener('change', function () {
			var boxes = document.querySelectorAll('input[type=checkbox][name=type]');
			for (var i = 0; i < boxes.length; i++) { boxes[i].disabled = allTypes.checked; }
		});
	}
})();
