/*
 * gdsearch — state-restriction + buyer-permit-advisory chip popup.
 * No template interpolation, no $-vars. Reads state/reason/citation/type
 * from data-* attributes on each chip and toggles the shared popup div.
 * Two scopes handled by the same handler shape:
 *   .gdsp-restrict — red "cannot ship" restriction chips.
 *   .gdsp-advisory — yellow buyer-permit chips (v1.6.17, CO / MN).
 * The advisory scope has no pin/exemption blocks; init() handles the
 * missing selectors gracefully.
 */
(function () {
	'use strict';

	function initScope(scopeSelector, cls) {
		var wraps = document.querySelectorAll(scopeSelector);
		if (!wraps || !wraps.length) { return; }

		wraps.forEach(function (wrap) {
			var popup      = wrap.querySelector('.' + cls + '__popup');
			var closeBtn   = wrap.querySelector('.' + cls + '__close');
			var titleEl    = wrap.querySelector('.' + cls + '__pop-title');
			var reasonEl   = wrap.querySelector('.' + cls + '__pop-reason');
			var citeWrap   = wrap.querySelector('.' + cls + '__pop-citation');
			var citeSpan   = citeWrap ? citeWrap.querySelector('span') : null;
			var pinEl      = wrap.querySelector('.' + cls + '__pop-pin');       // restrict only
			var exWrap     = wrap.querySelector('.' + cls + '__pop-exemption'); // restrict only
			var exBody     = exWrap ? exWrap.querySelector('.' + cls + '__pop-exemption-body') : null;
			var chips      = wrap.querySelectorAll('.' + cls + '__chip');
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
				var exempt = chip.getAttribute('data-exemption') || '';
				var type   = chip.getAttribute('data-type') || '';
				var ftype  = chip.getAttribute('data-ftype') || '';

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
					// Pin-remedy language ("FFL can pin the magazine to comply")
					// only applies when the RESTRICTED item is a firearm whose
					// capacity issue could be cured by swapping/blocking its
					// magazine. For a bare LCM (firearm_type === 'magazine')
					// the mag itself is the restricted item — no pin remedy.
					pinEl.hidden = (type !== 'capacity' || ftype === 'magazine');
				}

				if (exWrap) {
					if (exempt && exBody) {
						exBody.textContent = exempt;
						exWrap.hidden = false;
					} else {
						exWrap.hidden = true;
					}
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

	function init() {
		initScope('.gdsp-restrict', 'gdsp-restrict');
		initScope('.gdsp-advisory', 'gdsp-advisory');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
