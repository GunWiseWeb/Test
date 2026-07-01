/*
 * gdsearch — state-restriction chip popup.
 * No template interpolation, no $-vars. Reads state/reason/citation/type
 * from data-* attributes on each chip and toggles the shared popup div.
 * Scoped under .gdsp-restrict, mobile-friendly.
 */
(function () {
	'use strict';

	function init() {
		var wraps = document.querySelectorAll('.gdsp-restrict');
		if (!wraps || !wraps.length) { return; }

		wraps.forEach(function (wrap) {
			var popup      = wrap.querySelector('.gdsp-restrict__popup');
			var closeBtn   = wrap.querySelector('.gdsp-restrict__close');
			var titleEl    = wrap.querySelector('.gdsp-restrict__pop-title');
			var reasonEl   = wrap.querySelector('.gdsp-restrict__pop-reason');
			var citeWrap   = wrap.querySelector('.gdsp-restrict__pop-citation');
			var citeSpan   = citeWrap ? citeWrap.querySelector('span') : null;
			var pinEl      = wrap.querySelector('.gdsp-restrict__pop-pin');
			var chips      = wrap.querySelectorAll('.gdsp-restrict__chip');
			var activeChip = null;

			if (!popup || !chips.length) { return; }

			function humanReason(raw, type) {
				if (!raw) { return ''; }
				// "Handgun mag 17 > CA limit 10" → "Handgun magazine (17) exceeds California's 10-round limit"
				// We only rewrite the terse capacity form; roster/override reasons come through as-is.
				var m = raw.match(/^(handgun|rifle|shotgun)\s+mag\s+(\d+)\s*>\s*([A-Z]{2})\s+limit\s+(\d+)$/i);
				if (m && type === 'capacity') {
					var kind = m[1].charAt(0).toUpperCase() + m[1].slice(1).toLowerCase();
					var cap  = m[2];
					var lim  = m[4];
					return kind + ' magazine (' + cap + ') exceeds the state’s ' + lim + '-round limit.';
				}
				return raw;
			}

			function openPopup(chip) {
				if (activeChip === chip && !popup.hidden) {
					closePopup();
					return;
				}
				var name = chip.getAttribute('data-statename') || chip.getAttribute('data-state') || '';
				var reason = chip.getAttribute('data-reason') || '';
				var cite   = chip.getAttribute('data-citation') || '';
				var type   = chip.getAttribute('data-type') || '';

				if (titleEl)  { titleEl.textContent  = name; }
				if (reasonEl) { reasonEl.textContent = humanReason(reason, type); }

				if (citeWrap) {
					if (cite && citeSpan) {
						citeSpan.textContent = cite;
						citeWrap.hidden = false;
					} else {
						citeWrap.hidden = true;
					}
				}

				if (pinEl) {
					pinEl.hidden = (type !== 'capacity');
				}

				popup.hidden = false;
				popup.setAttribute('aria-hidden', 'false');

				// Position roughly near the chip (below by default).
				var chipRect = chip.getBoundingClientRect();
				var wrapRect = wrap.getBoundingClientRect();
				popup.style.top  = (chipRect.bottom - wrapRect.top + 6) + 'px';
				var leftPx = chipRect.left - wrapRect.left;
				var maxLeft = wrapRect.width - popup.offsetWidth - 8;
				if (leftPx > maxLeft) { leftPx = Math.max(4, maxLeft); }
				if (leftPx < 4) { leftPx = 4; }
				popup.style.left = leftPx + 'px';

				activeChip = chip;
			}

			function closePopup() {
				if (!popup.hidden) {
					popup.hidden = true;
					popup.setAttribute('aria-hidden', 'true');
				}
				activeChip = null;
			}

			chips.forEach(function (chip) {
				chip.addEventListener('click', function (ev) {
					ev.stopPropagation();
					openPopup(chip);
				});
			});

			if (closeBtn) {
				closeBtn.addEventListener('click', function (ev) {
					ev.stopPropagation();
					closePopup();
				});
			}

			// Dismiss on outside click.
			document.addEventListener('click', function (ev) {
				if (popup.hidden) { return; }
				if (!wrap.contains(ev.target)) { closePopup(); }
			});

			// Dismiss on Esc.
			document.addEventListener('keydown', function (ev) {
				if (ev.key === 'Escape' && !popup.hidden) { closePopup(); }
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
