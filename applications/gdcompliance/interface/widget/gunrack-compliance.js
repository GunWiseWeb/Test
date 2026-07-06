/*!
 * GunRack Compliance Widget — vanilla JS embed for dealer product pages.
 *
 * Dealer install (generic):
 *   <div id="gunrack-compliance"
 *        data-upc="011356670526"
 *        data-key="gdc_pub_XXXX"></div>
 *   <script src="https://gunrack.deals/applications/gdcompliance/interface/widget/gunrack-compliance.js" async></script>
 *
 * See mykey page on gunrack.deals for platform-specific snippets.
 *
 * Contract:
 *   - Vanilla JS, no dependencies.
 *   - Scoped CSS (all classes start with .grc-), so it never fights the
 *     dealer's theme.
 *   - Uses a PUBLISHABLE key (gdc_pub_...) which is domain-locked
 *     server-side. A leaked key won't work off the dealer's origin.
 *   - Read-only: never touches the dealer's cart / add-to-cart / prices.
 *   - Fails QUIETLY: any network / auth / unknown-UPC failure renders
 *     nothing or a tiny muted note; never throws into the host page.
 *   - Multi-mount safe: supports a container with id=gunrack-compliance
 *     AND any number of containers with class=gunrack-compliance
 *     (dealers with more than one embed per page — rare but harmless).
 */
(function () {
	'use strict';

	var API_BASE      = 'https://gunrack.deals/api/compliance/product';
	var ATTRIBUTION   = 'https://gunrack.deals';
	var STORAGE_KEY   = 'grc-selected-state';
	var CSS_INJECTED  = false;

	/* ---------------------------------------------------------------
	 * Full state map. Kept inline so the widget is a single-file drop-
	 * in. Duplicates the server-side STATE_NAMES constant. */
	var STATES = {
		'AL':'Alabama','AK':'Alaska','AZ':'Arizona','AR':'Arkansas',
		'CA':'California','CO':'Colorado','CT':'Connecticut','DE':'Delaware',
		'DC':'District of Columbia',
		'FL':'Florida','GA':'Georgia','HI':'Hawaii','ID':'Idaho',
		'IL':'Illinois','IN':'Indiana','IA':'Iowa','KS':'Kansas',
		'KY':'Kentucky','LA':'Louisiana','ME':'Maine','MD':'Maryland',
		'MA':'Massachusetts','MI':'Michigan','MN':'Minnesota','MS':'Mississippi',
		'MO':'Missouri','MT':'Montana','NE':'Nebraska','NV':'Nevada',
		'NH':'New Hampshire','NJ':'New Jersey','NM':'New Mexico','NY':'New York',
		'NC':'North Carolina','ND':'North Dakota','OH':'Ohio','OK':'Oklahoma',
		'OR':'Oregon','PA':'Pennsylvania','RI':'Rhode Island','SC':'South Carolina',
		'SD':'South Dakota','TN':'Tennessee','TX':'Texas','UT':'Utah',
		'VT':'Vermont','VA':'Virginia','WA':'Washington','WV':'West Virginia',
		'WI':'Wisconsin','WY':'Wyoming'
	};

	/* ---------------------------------------------------------------
	 * Scoped CSS. Injected once per page, however many widgets mount.
	 * Every rule targets .grc-* so the dealer's theme is untouched.
	 */
	function injectCss() {
		if (CSS_INJECTED) return;
		CSS_INJECTED = true;
		var css =
			'.grc-widget{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:14px;color:#0f172a;line-height:1.45;margin:12px 0;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;background:#fff;max-width:100%;box-sizing:border-box}' +
			'.grc-widget *{box-sizing:border-box}' +
			'.grc-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}' +
			'.grc-title{margin:0;font-size:15px;font-weight:700;color:#0f172a}' +
			'.grc-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}' +
			'.grc-badge--restrict{background:#fee2e2;color:#991b1b}' +
			'.grc-badge--advisory{background:#fef3c7;color:#78350f}' +
			'.grc-badge--clear{background:#dcfce7;color:#065f46}' +
			'.grc-badge--info{background:#e0e7ff;color:#3730a3}' +
			'.grc-row{margin:8px 0;padding:10px 12px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0}' +
			'.grc-row--restrict{background:#fef2f2;border-color:#fecaca;color:#7f1d1d}' +
			'.grc-row--advisory{background:#fefce8;border-color:#fde68a;color:#78350f}' +
			'.grc-row--clear{background:#f0fdf4;border-color:#bbf7d0;color:#065f46}' +
			'.grc-row .grc-row__label{font-weight:600;display:block;margin-bottom:2px}' +
			'.grc-row .grc-row__body{font-size:13px}' +
			'.grc-states{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 0}' +
			'.grc-state{display:inline-block;padding:3px 9px;border-radius:6px;background:#fff;border:1px solid currentColor;font-weight:700;font-size:12px;color:inherit}' +
			'.grc-details{margin-top:8px}' +
			'.grc-details > summary{cursor:pointer;list-style:none;font-size:12.5px;font-weight:600;color:#334155;display:inline-flex;align-items:center;gap:4px;user-select:none}' +
			'.grc-details > summary::-webkit-details-marker{display:none}' +
			'.grc-details > summary::after{content:"▾";font-size:10px}' +
			'.grc-details[open] > summary::after{content:"▴"}' +
			'.grc-flag-list{list-style:none;padding:0;margin:8px 0 0}' +
			'.grc-flag-list > li{padding:6px 0;border-top:1px solid rgba(0,0,0,.08);font-size:13px;line-height:1.5}' +
			'.grc-flag-list > li:first-child{border-top:0;padding-top:2px}' +
			'.grc-flag-list .grc-flag-state{font-weight:700;margin-right:4px}' +
			'.grc-flag-list .grc-flag-type{display:inline-block;padding:1px 6px;border-radius:4px;background:rgba(0,0,0,.05);font-size:11px;text-transform:uppercase;letter-spacing:.03em;margin-right:6px;color:inherit}' +
			'.grc-flag-list .grc-flag-reason{color:inherit;opacity:.9}' +
			'.grc-flag-list .grc-flag-cite{font-size:11.5px;color:inherit;opacity:.75;margin-top:2px}' +
			'.grc-picker{display:flex;align-items:center;gap:8px;margin:10px 0 0;padding:8px 10px;background:#f1f5f9;border-radius:8px;flex-wrap:wrap}' +
			'.grc-picker label{font-size:12.5px;font-weight:600;color:#334155;margin:0}' +
			'.grc-picker select{padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;font-size:13px;color:#0f172a;min-width:180px;max-width:100%}' +
			'.grc-picker button{padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;font-size:12px;cursor:pointer}' +
			'.grc-picker button:hover{background:#e2e8f0}' +
			'.grc-your-state{margin-top:8px;padding:10px 12px;border-radius:8px;font-weight:600;font-size:13.5px}' +
			'.grc-your-state--restrict{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}' +
			'.grc-your-state--advisory{background:#fefce8;border:1px solid #fde68a;color:#78350f}' +
			'.grc-your-state--clear{background:#f0fdf4;border:1px solid #bbf7d0;color:#065f46}' +
			'.grc-your-state small{font-weight:400;font-size:12px;opacity:.85;display:block;margin-top:3px}' +
			'.grc-disclaimer{margin-top:10px;padding:8px 10px;background:#fef9c3;border:1px solid #fde68a;color:#713f12;border-radius:6px;font-size:11.5px;line-height:1.4}' +
			'.grc-preview-note{display:inline-block;background:#e0e7ff;color:#3730a3;font-size:10.5px;padding:1px 8px;border-radius:999px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}' +
			'.grc-attribution{margin-top:10px;font-size:11px;color:#64748b;text-align:right}' +
			'.grc-attribution a{color:#1e40af;text-decoration:none}' +
			'.grc-attribution a:hover{text-decoration:underline}' +
			'.grc-quiet{font-size:11.5px;color:#94a3b8;font-style:italic}' +
			'';
		var s = document.createElement('style');
		s.setAttribute('data-grc', '1');
		s.appendChild(document.createTextNode(css));
		(document.head || document.documentElement).appendChild(s);
	}

	function esc(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function stateName(code) {
		code = String(code || '').toUpperCase();
		return STATES[code] || code;
	}

	function typeLabel(t) {
		switch (String(t || '').toLowerCase()) {
			case 'awb':           return 'Assault-weapons ban';
			case 'capacity':      return 'Magazine capacity';
			case 'melting_point': return 'Melting-point / frame material';
			case 'rate_of_fire':  return 'Rate-of-fire device';
			case 'advisory':      return 'Buyer requirement';
			case 'override':      return 'State restriction';
			default:              return 'State restriction';
		}
	}

	/* ---------------------------------------------------------------
	 * Silent-fail helper. Any code path that hits an unrecoverable
	 * error here renders a tiny muted note (or nothing at all if the
	 * container was never populated) so the dealer's page still
	 * looks right.
	 */
	function renderQuiet(container, msg) {
		try {
			container.innerHTML = msg
				? '<div class="grc-widget"><div class="grc-quiet">' + esc(msg) + '</div></div>'
				: '';
		} catch (e) { /* absolute last-resort */ }
	}

	/* ---------------------------------------------------------------
	 * localStorage wrapper — never throws (private-mode / disabled
	 * cookies etc. shouldn't kill the widget).
	 */
	function loadStoredState() {
		try { return (window.localStorage && localStorage.getItem(STORAGE_KEY)) || ''; }
		catch (e) { return ''; }
	}
	function saveStoredState(code) {
		try { window.localStorage && localStorage.setItem(STORAGE_KEY, code || ''); }
		catch (e) { /* ignore */ }
	}

	/* ---------------------------------------------------------------
	 * Compute the "for your state" answer from the payload. Advisory
	 * items are still available (Stage-1 semantics — buyer requirement,
	 * not a sale prohibition).
	 */
	function verdictForState(payload, stateCode) {
		var code = String(stateCode || '').toUpperCase();
		if (!code) return null;

		var restricted = (payload.restricted_states || []).filter(function (r) { return r.state === code; });
		if (restricted.length) {
			return { kind: 'restrict', flags: restricted };
		}
		var advisory = (payload.advisory_states || []).filter(function (r) { return r.state === code; });
		if (advisory.length) {
			return { kind: 'advisory', flags: advisory };
		}
		return { kind: 'clear', flags: [] };
	}

	/* ---------------------------------------------------------------
	 * The state picker. Always available; picking a state highlights
	 * the "for your state" verdict without hiding the all-states view.
	 */
	function renderStatePicker(container, payload, selected) {
		var sortable = [];
		for (var k in STATES) { if (Object.prototype.hasOwnProperty.call(STATES, k)) sortable.push(k); }
		sortable.sort(function (a, b) { return STATES[a] < STATES[b] ? -1 : 1; });

		var opts = '<option value="">— Any state —</option>';
		for (var i = 0; i < sortable.length; i++) {
			var s = sortable[i];
			opts += '<option value="' + esc(s) + '"' + (s === selected ? ' selected' : '') + '>' + esc(STATES[s]) + '</option>';
		}
		var pickerId  = 'grc-picker-' + Math.floor(Math.random() * 1e9);
		var resultId  = 'grc-your-'  + Math.floor(Math.random() * 1e9);
		var wrapEl    = document.createElement('div');
		wrapEl.className = 'grc-picker';
		wrapEl.innerHTML =
			'<label for="' + pickerId + '">Check your state:</label>' +
			'<select id="' + pickerId + '">' + opts + '</select>' +
			'<button type="button" class="grc-picker-clear">Clear</button>';
		container.appendChild(wrapEl);

		var resultEl = document.createElement('div');
		resultEl.id = resultId;
		container.appendChild(resultEl);

		function paintResult(code) {
			resultEl.innerHTML = '';
			if (!code) return;
			var v = verdictForState(payload, code);
			if (!v) return;
			var name = stateName(code);
			var box  = document.createElement('div');
			if (v.kind === 'restrict') {
				box.className = 'grc-your-state grc-your-state--restrict';
				var reasons = v.flags.map(function (f) { return esc(f.reason || typeLabel(f.type)); }).slice(0, 2).join(' · ');
				box.innerHTML = '⛔ Restricted for sale in ' + esc(name) + '.<small>' + reasons + '</small>';
			} else if (v.kind === 'advisory') {
				box.className = 'grc-your-state grc-your-state--advisory';
				var advReason = v.flags.map(function (f) { return esc(f.reason); }).slice(0, 1).join('');
				box.innerHTML = 'ⓘ Buyer requirement in ' + esc(name) + '.<small>' + advReason + '</small>';
			} else {
				box.className = 'grc-your-state grc-your-state--clear';
				box.innerHTML = '✓ Not restricted for ' + esc(name) + '.<small>Verify with your FFL before purchase.</small>';
			}
			resultEl.appendChild(box);
		}

		var pickerEl = wrapEl.querySelector('#' + pickerId);
		var clearBtn = wrapEl.querySelector('.grc-picker-clear');
		pickerEl.addEventListener('change', function (ev) {
			var code = ev.target.value || '';
			saveStoredState(code);
			paintResult(code);
		});
		clearBtn.addEventListener('click', function () {
			pickerEl.value = '';
			saveStoredState('');
			paintResult('');
		});

		if (selected) paintResult(selected);
	}

	/* ---------------------------------------------------------------
	 * Render the widget once the payload is in hand.
	 */
	function render(container, payload) {
		injectCss();

		var wrap = document.createElement('div');
		wrap.className = 'grc-widget';
		container.innerHTML = '';
		container.appendChild(wrap);

		var restrictedStates = payload.restricted_states || [];
		var advisoryStates   = payload.advisory_states   || [];
		var codes            = payload.restricted_state_codes || [];
		var advisoryCodes    = advisoryStates.map(function (r) { return r.state; }).filter(function (v, i, a) { return a.indexOf(v) === i; });

		/* Header: title + top badge + verification pill. */
		var head = document.createElement('div');
		head.className = 'grc-head';
		var title = 'State compliance';
		var badgeClass, badgeText;
		if (payload.status === 'unknown') {
			badgeClass = 'grc-badge grc-badge--info';
			badgeText  = 'Not in database';
		} else if (restrictedStates.length) {
			badgeClass = 'grc-badge grc-badge--restrict';
			badgeText  = 'Restricted in ' + codes.length + ' state' + (codes.length === 1 ? '' : 's');
		} else if (advisoryStates.length) {
			badgeClass = 'grc-badge grc-badge--advisory';
			badgeText  = 'Buyer requirements';
		} else {
			badgeClass = 'grc-badge grc-badge--clear';
			badgeText  = 'No known restrictions';
		}
		var verifyPill = payload.verification_status === 'pending_legal_review'
			? '<span class="grc-preview-note" title="Pending legal verification">preview</span>' : '';
		head.innerHTML =
			'<h3 class="grc-title">' + esc(title) + '</h3>' +
			'<div>' + verifyPill + ' <span class="' + badgeClass + '">' + esc(badgeText) + '</span></div>';
		wrap.appendChild(head);

		/* Body: unknown-UPC branch shortcuts everything else. */
		if (payload.status === 'unknown') {
			var unk = document.createElement('div');
			unk.className = 'grc-row';
			unk.innerHTML = '<span class="grc-row__label">Not in compliance database</span><span class="grc-row__body">' + esc(payload.message || 'We do not have compliance data for this UPC.') + '</span>';
			wrap.appendChild(unk);
			appendDisclaimer(wrap, payload);
			return;
		}

		/* Restricted-states row. */
		if (codes.length) {
			var row = document.createElement('div');
			row.className = 'grc-row grc-row--restrict';
			var chips = codes.slice().sort().map(function (c) {
				return '<span class="grc-state" title="' + esc(stateName(c)) + '">' + esc(c) + '</span>';
			}).join(' ');
			row.innerHTML =
				'<span class="grc-row__label">⛔ Restricted for sale in</span>' +
				'<div class="grc-states">' + chips + '</div>';

			/* Expand for full reasons/citations. */
			var det = document.createElement('details');
			det.className = 'grc-details';
			var flagsHtml = restrictedStates.map(function (f) {
				return '<li>' +
					'<span class="grc-flag-state">' + esc(f.state) + '</span>' +
					'<span class="grc-flag-type">' + esc(typeLabel(f.type)) + '</span>' +
					'<span class="grc-flag-reason">' + esc(f.reason || '') + '</span>' +
					(f.citation ? '<div class="grc-flag-cite">' + esc(f.citation) + '</div>' : '') +
					'</li>';
			}).join('');
			det.innerHTML = '<summary>Details &amp; citations</summary><ul class="grc-flag-list">' + flagsHtml + '</ul>';
			row.appendChild(det);
			wrap.appendChild(row);
		}

		/* Advisory-states row. */
		if (advisoryStates.length) {
			var advRow = document.createElement('div');
			advRow.className = 'grc-row grc-row--advisory';
			var advChips = advisoryCodes.slice().sort().map(function (c) {
				return '<span class="grc-state" title="' + esc(stateName(c)) + '">' + esc(c) + '</span>';
			}).join(' ');
			advRow.innerHTML =
				'<span class="grc-row__label">ⓘ Buyer requirements in</span>' +
				'<span class="grc-row__body">' + esc('The item can ship, but the buyer must meet a state permit or training requirement at the FFL.') + '</span>' +
				'<div class="grc-states" style="margin-top:6px">' + advChips + '</div>';
			var advDet = document.createElement('details');
			advDet.className = 'grc-details';
			var advList = advisoryStates.map(function (f) {
				return '<li>' +
					'<span class="grc-flag-state">' + esc(f.state) + '</span>' +
					'<span class="grc-flag-type">' + esc('Buyer requirement') + '</span>' +
					'<span class="grc-flag-reason">' + esc(f.reason || '') + '</span>' +
					(f.citation ? '<div class="grc-flag-cite">' + esc(f.citation) + '</div>' : '') +
					'</li>';
			}).join('');
			advDet.innerHTML = '<summary>Details</summary><ul class="grc-flag-list">' + advList + '</ul>';
			advRow.appendChild(advDet);
			wrap.appendChild(advRow);
		}

		/* Clear-across-the-board row. */
		if (!restrictedStates.length && !advisoryStates.length) {
			var clearRow = document.createElement('div');
			clearRow.className = 'grc-row grc-row--clear';
			clearRow.innerHTML =
				'<span class="grc-row__label">✓ No known state restrictions</span>' +
				'<span class="grc-row__body">' + esc('We did not flag this item for any state. Always verify with your local laws and your FFL before purchase.') + '</span>';
			wrap.appendChild(clearRow);
		}

		/* Check-your-state picker. */
		renderStatePicker(wrap, payload, loadStoredState());

		/* Disclaimer + attribution. */
		appendDisclaimer(wrap, payload);
	}

	function appendDisclaimer(wrap, payload) {
		if (payload.disclaimer) {
			var disc = document.createElement('div');
			disc.className = 'grc-disclaimer';
			disc.textContent = payload.disclaimer;
			wrap.appendChild(disc);
		}
		var attr = document.createElement('div');
		attr.className = 'grc-attribution';
		attr.innerHTML = 'Compliance data by <a href="' + esc(ATTRIBUTION) + '" target="_blank" rel="noopener">Gun Rack</a>';
		wrap.appendChild(attr);
	}

	/* ---------------------------------------------------------------
	 * Mount one container. Silently ignore misconfigured mounts.
	 */
	function mountOne(container) {
		if (!container || container.getAttribute('data-grc-mounted') === '1') return;
		container.setAttribute('data-grc-mounted', '1');

		var upc = (container.getAttribute('data-upc') || '').trim();
		var key = (container.getAttribute('data-key') || '').trim();
		if (!upc || !key) {
			renderQuiet(container, '');
			return;
		}
		/* Basic sanity on the UPC — same charset as the API allows. */
		if (!/^[A-Za-z0-9._\-\/ ]+$/.test(upc)) {
			renderQuiet(container, '');
			return;
		}

		var url = API_BASE + '?upc=' + encodeURIComponent(upc) + '&api_key=' + encodeURIComponent(key);

		function loadFetch() {
			return fetch(url, { method: 'GET', credentials: 'omit', headers: { 'Accept': 'application/json' } })
				.then(function (resp) { return resp.json().catch(function () { return null; }); });
		}
		function loadXhr() {
			return new Promise(function (resolve) {
				try {
					var xhr = new XMLHttpRequest();
					xhr.open('GET', url, true);
					xhr.setRequestHeader('Accept', 'application/json');
					xhr.onreadystatechange = function () {
						if (xhr.readyState === 4) {
							try { resolve(JSON.parse(xhr.responseText)); }
							catch (e) { resolve(null); }
						}
					};
					xhr.send();
				} catch (e) { resolve(null); }
			});
		}

		var loader = (typeof window.fetch === 'function') ? loadFetch : loadXhr;

		loader().then(function (payload) {
			if (!payload) { renderQuiet(container, ''); return; }
			if (payload.error) {
				/* Auth/domain/quota failure — fail QUIETLY. Never break
				   the dealer's page with a loud error. */
				renderQuiet(container, '');
				return;
			}
			try { render(container, payload); }
			catch (e) { renderQuiet(container, ''); }
		}, function () { renderQuiet(container, ''); });
	}

	/* ---------------------------------------------------------------
	 * Discovery + init. Runs on DOM ready OR immediately if the DOM is
	 * already parsed. Supports:
	 *   #gunrack-compliance                — canonical single mount
	 *   .gunrack-compliance                — any number of mounts
	 *   [data-gunrack-compliance]          — future-proofing
	 */
	function init() {
		var seen = [];
		var byId = document.getElementById('gunrack-compliance');
		if (byId) seen.push(byId);
		var nodeList = document.querySelectorAll('.gunrack-compliance, [data-gunrack-compliance]');
		for (var i = 0; i < nodeList.length; i++) {
			var el = nodeList[i];
			if (seen.indexOf(el) === -1) seen.push(el);
		}
		for (var j = 0; j < seen.length; j++) {
			mountOne(seen[j]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/* Optional public hook — dealers can call GunRackCompliance.mount(el)
	   if they inject the container after our script ran. */
	window.GunRackCompliance = window.GunRackCompliance || {
		mount: function (el) { try { mountOne(el); } catch (e) {} },
		refresh: function () { try { init(); } catch (e) {} }
	};
}());
