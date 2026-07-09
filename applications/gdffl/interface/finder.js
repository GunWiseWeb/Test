/* GD FFL Finder — public finder JS (v1.0.13).
 *
 * Wires the search form, calls the do=search JSON endpoint,
 * and renders result cards to match the approved mockup:
 *   - navy header, green Search button, chip-based filters
 *   - 52x52 distance chip on each result (teal if <5 mi, else neutral)
 *   - real <a href="tel:..."> Call button (NOT markdown text)
 *   - count header: "{N} dealers within {R} miles of {ZIP}"
 *
 * Ships from applications/gdffl/interface/ so the template
 * engine never processes it (rule #47).
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
	var chipsWrap  = document.getElementById('gdfflChips');
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

	/* Distance threshold (miles) below which the distance chip
	 * gets the teal accent color. */
	var NEAR_MI = 5;

	function selectedTypes() {
		if (allTypes && allTypes.classList.contains('is-active')) {
			return 'all';
		}
		if (!chipsWrap) { return ''; }
		var chips = chipsWrap.querySelectorAll('[data-role="type"].is-active');
		var picked = [];
		for (var i = 0; i < chips.length; i++) {
			picked.push(chips[i].getAttribute('data-value') || '');
		}
		return picked.filter(Boolean).join(',');
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

	/* Inline SVG icons — no external dependency. */
	function iconPin() {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			+ '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/>'
			+ '<circle cx="12" cy="10" r="3"/>'
			+ '</svg>';
	}
	function iconPhone() {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			+ '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>'
			+ '</svg>';
	}

	function cardHtml(r) {
		var dist    = (r.distance_miles !== null && r.distance_miles !== undefined)
			? Number(r.distance_miles)
			: null;
		var distCls = 'gdffl-distance' + (dist !== null && dist < NEAR_MI ? ' gdffl-distance--near' : '');
		var distNum = dist === null ? '' : (Number.isInteger(dist) ? String(dist) : dist.toFixed(1));

		var distChip = '<div class="' + distCls + '" aria-label="' + escapeAttr((dist === null ? 'Distance unknown' : distNum + ' miles')) + '">'
			+   '<span class="gdffl-distance__num">' + escapeHtml(distNum || '?') + '</span>'
			+   '<span class="gdffl-distance__unit">' + escapeHtml(labels.distance || 'mi') + '</span>'
			+ '</div>';

		var biz = r.business_name || r.license_name || 'FFL';

		var addrParts = [
			r.street,
			[r.city, r.state].filter(Boolean).join(', '),
			r.zip
		].filter(Boolean).map(escapeHtml);
		var addrLine  = addrParts.join(', ');
		var addr      = addrLine
			? '<div class="gdffl-card__addr">'
				+   '<span class="gdffl-card__addr-icon">' + iconPin() + '</span>'
				+   '<span class="gdffl-card__addr-text">' + addrLine + '</span>'
				+ '</div>'
			: '';

		var typeLabel = r.lic_type_label || r.lic_type || '';
		var typeText  = r.lic_type
			? (escapeHtml(r.lic_type) + (typeLabel && typeLabel !== r.lic_type ? ' · ' + escapeHtml(typeLabel) : ''))
			: escapeHtml(typeLabel);
		var typeTag   = typeText
			? '<span class="gdffl-card__type">' + typeText + '</span>'
			: '';

		var phoneDigits = String(r.phone || '').replace(/[^0-9]/g, '');
		var callBtn;
		if (phoneDigits.length >= 10) {
			callBtn = '<a class="gdffl-call" href="tel:' + escapeAttr(phoneDigits) + '"'
				+ ' aria-label="Call ' + escapeAttr(biz) + '">'
				+   iconPhone() + '<span>Call</span>'
				+ '</a>';
		} else {
			callBtn = '<span class="gdffl-call gdffl-call--none">' + escapeHtml(labels.no_phone || 'No phone on file') + '</span>';
		}

		return '<div class="gdffl-card" role="listitem">'
			+   distChip
			+   '<div class="gdffl-card__body">'
			+     '<div class="gdffl-card__name">' + escapeHtml(biz) + '</div>'
			+     addr
			+     typeTag
			+   '</div>'
			+   callBtn
			+ '</div>';
	}

	function updateCountHeader(zip, radius, total) {
		if (!countEl) { return; }
		if (total > 0) {
			countEl.hidden = false;
			countEl.innerHTML = '<b>' + fmtInt(total) + '</b> dealer' + (total === 1 ? '' : 's')
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
				statusEl.textContent = 'No dealers within ' + radius + ' miles of ' + zipDigits + ' — try a wider radius.';
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

	/* --- Chip wiring ---------------------------------------- */

	function setChipActive(chip, active) {
		if (active) {
			chip.classList.add('is-active');
			chip.setAttribute('aria-checked', 'true');
		} else {
			chip.classList.remove('is-active');
			chip.setAttribute('aria-checked', 'false');
		}
	}

	if (chipsWrap) {
		chipsWrap.addEventListener('click', function (ev) {
			var chip = ev.target && ev.target.closest && ev.target.closest('.gdffl-chip');
			if (!chip || !chipsWrap.contains(chip)) { return; }

			var role = chip.getAttribute('data-role');
			if (role === 'alltypes') {
				var makeActive = !chip.classList.contains('is-active');
				setChipActive(chip, makeActive);
				/* When "Show all types" is on, dim all type chips so
				   the visual matches the semantic (filter is bypassed). */
				var typeChips = chipsWrap.querySelectorAll('[data-role="type"]');
				for (var i = 0; i < typeChips.length; i++) {
					typeChips[i].disabled = makeActive;
					if (makeActive) { typeChips[i].setAttribute('aria-disabled', 'true'); }
					else { typeChips[i].removeAttribute('aria-disabled'); }
				}
			} else if (role === 'type') {
				if (allTypes && allTypes.classList.contains('is-active')) { return; }
				setChipActive(chip, !chip.classList.contains('is-active'));
			}
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
})();
