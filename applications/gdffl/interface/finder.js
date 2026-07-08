/* GD FFL Finder — public finder JS.
 *
 * Reads init JSON from <script id="gdffl-finder-init">, wires
 * the search form, calls the do=search JSON endpoint, and
 * renders result cards. No inline `$`-prefixed variables so
 * the IPS template engine can't mangle it (rule #46 doesn't
 * apply here anyway — served straight from interface/, per
 * rule #47). */
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
	var resultsEl  = document.getElementById('gdfflResults');
	var moreBtn    = document.getElementById('gdfflMore');

	var currentPage = 1;
	var lastZip     = '';
	var lastRadius  = 0;
	var lastTypes   = '';

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
		return String(raw || '');
	}

	function cardHtml(r) {
		var biz    = r.business_name || r.license_name || 'FFL';
		var addr   = [ r.street, [ r.city, r.state ].filter(Boolean).join(', '), r.zip ].filter(Boolean).join('<br>');
		var phone  = String(r.phone || '').replace(/[^0-9]/g, '');
		var phoneLine = phone.length >= 10
			? '<a href="tel:' + escapeAttr(phone) + '">' + escapeHtml(fmtPhone(r.phone)) + '</a>'
			: '<span>' + escapeHtml(labels.no_phone || '—') + '</span>';
		var typeLabel = r.lic_type_label || r.lic_type;
		var distStr = (r.distance_miles !== null && r.distance_miles !== undefined)
			? String(r.distance_miles) + ' mi'
			: '';
		return '<div class="gdffl-card" role="listitem">'
			+   '<div class="gdffl-card__body">'
			+     '<div class="gdffl-card__biz">' + escapeHtml(biz) + '</div>'
			+     '<div class="gdffl-card__addr">' + addr + '</div>'
			+     '<div class="gdffl-card__meta">'
			+       '<span class="gdffl-card__type">' + escapeHtml(r.lic_type) + ' · ' + escapeHtml(typeLabel) + '</span>'
			+       phoneLine
			+     '</div>'
			+   '</div>'
			+   '<div class="gdffl-card__dist">' + escapeHtml(distStr) + '</div>'
			+ '</div>';
	}

	function runSearch(append) {
		var zipDigits = String(zipInput ? zipInput.value : '').replace(/[^0-9]/g, '');
		if (zipDigits.length < 5) {
			statusEl.className = 'gdffl-status gdffl-status--err';
			statusEl.textContent = labels.zip_bad || 'Enter a 5-digit ZIP.';
			resultsEl.innerHTML = '';
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
			statusEl.className = 'gdffl-status';
			statusEl.textContent = labels.searching || 'Searching…';
			resultsEl.innerHTML = '';
			if (moreBtn) { moreBtn.hidden = true; }
		} else {
			currentPage += 1;
			statusEl.className = 'gdffl-status';
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
					statusEl.textContent = labels.zip_bad || 'Enter a 5-digit ZIP.';
				} else if (data.error === 'zip_not_found') {
					statusEl.textContent = labels.zip_notfound || 'ZIP not found.';
				} else {
					statusEl.textContent = labels.error || 'Search failed — please try again.';
				}
				if (!append) { resultsEl.innerHTML = ''; }
				if (moreBtn) { moreBtn.hidden = true; }
				return;
			}

			var results = (data && data.results) || [];
			if (!append && results.length === 0) {
				statusEl.className = 'gdffl-status';
				statusEl.textContent = labels.no_results || 'No FFLs within the selected radius.';
				return;
			}

			statusEl.className = 'gdffl-status';
			statusEl.textContent = '';

			var html = '';
			for (var i = 0; i < results.length; i++) { html += cardHtml(results[i]); }
			if (append) {
				resultsEl.insertAdjacentHTML('beforeend', html);
			} else {
				resultsEl.innerHTML = html;
			}
			if (moreBtn) {
				var per = (data && data.per) || 20;
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
